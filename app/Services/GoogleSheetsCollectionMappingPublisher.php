<?php

namespace App\Services;

use App\Models\ShopifyCollectionProductReportRun;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GoogleSheetsCollectionMappingPublisher
{
    private const API_BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private const HEADERS = [
        'Collection Title',
        'Collection Handle',
        'Collection URL',
        'Collection Sort Order',
        'Product Title',
        'Product URL',
        'Product Status',
        'Main Collection',
        'Product Type',
        'Total Inventory',
        'Product Created At',
    ];

    public function isEnabled(): bool
    {
        return (bool) config('google_sheets.enabled');
    }

    /** @param array<int, string> $collectionHandles */
    public function publish(ShopifyCollectionProductReportRun $run, array $collectionHandles): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $spreadsheetId = trim((string) config('google_sheets.spreadsheet_id'));
        if ($spreadsheetId === '') {
            throw new \RuntimeException('GOOGLE_SHEETS_COLLECTION_MAPPING_SPREADSHEET_ID is not configured.');
        }

        $collectionHandles = collect($collectionHandles)
            ->map(fn ($handle): string => strtolower(trim((string) $handle)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($collectionHandles === []) {
            throw new \InvalidArgumentException('Select at least one collection to export to Google Sheets.');
        }

        return Cache::lock(
            'google-sheets-collection-mapping-'.md5($spreadsheetId),
            max(30, (int) config('google_sheets.lock_seconds', 300)),
        )->block(10, function () use ($run, $spreadsheetId, $collectionHandles): int {
            $sheets = $this->sheetMetadata($spreadsheetId);
            $groups = $run->rows()
                ->whereIn('collection_handle', $collectionHandles)
                ->whereNotNull('collection_handle')
                ->orderByDesc('product_created_at')
                ->get()
                ->groupBy('collection_handle');
            $published = 0;

            // Tabs are moved to index zero as they are written, so process in reverse
            // to leave the collection containing the newest product at the front.
            foreach ($groups->reverse() as $handle => $rows) {
                $tabTitle = $this->tabTitle((string) $handle);
                $sheetId = $sheets[$tabTitle] ?? null;
                if ($sheetId === null) {
                    $sheetId = $this->addSheet($spreadsheetId, $tabTitle);
                    $sheets[$tabTitle] = $sheetId;
                } else {
                    $this->moveSheetToFront($spreadsheetId, $sheetId);
                }

                $fetchedAt = ($run->completed_at ?? now())
                    ->timezone((string) config('google_sheets.timezone'))
                    ->format('Y-m-d H:i:s T');
                $values = [
                    ['Data fetched from Shopify', $fetchedAt],
                    [],
                    self::HEADERS,
                ];

                foreach ($rows as $row) {
                    $values[] = [
                        $row->collection_title,
                        $row->collection_handle,
                        $row->collection_url,
                        $row->collection_sort_order,
                        $row->product_title,
                        $row->product_url,
                        $row->product_status,
                        $row->main_collection,
                        $row->product_type,
                        $row->total_inventory,
                        $row->product_created_at?->timezone((string) config('google_sheets.timezone'))->format('Y-m-d H:i:s T'),
                    ];
                }

                $this->replaceValues($spreadsheetId, $tabTitle, $values);
                $published++;
            }

            return $published;
        });
    }

    /** @return array<string, int> */
    private function sheetMetadata(string $spreadsheetId): array
    {
        $response = $this->request()->get(
            self::API_BASE_URL.'/'.rawurlencode($spreadsheetId),
            ['fields' => 'sheets.properties(sheetId,title)'],
        );
        $this->ensureSuccessful($response, 'read spreadsheet tabs');

        return collect((array) $response->json('sheets', []))
            ->mapWithKeys(fn (array $sheet): array => [
                (string) data_get($sheet, 'properties.title') => (int) data_get($sheet, 'properties.sheetId'),
            ])
            ->all();
    }

    private function addSheet(string $spreadsheetId, string $title): int
    {
        $response = $this->batchUpdate($spreadsheetId, [[
            'addSheet' => ['properties' => [
                'title' => $title,
                'index' => 0,
                'gridProperties' => ['frozenRowCount' => 3],
            ]],
        ]]);

        return (int) $response->json('replies.0.addSheet.properties.sheetId');
    }

    private function moveSheetToFront(string $spreadsheetId, int $sheetId): void
    {
        $this->batchUpdate($spreadsheetId, [[
            'updateSheetProperties' => [
                'properties' => ['sheetId' => $sheetId, 'index' => 0, 'gridProperties' => ['frozenRowCount' => 3]],
                'fields' => 'index,gridProperties.frozenRowCount',
            ],
        ]]);
    }

    /** @param array<int, array<int, mixed>> $values */
    private function replaceValues(string $spreadsheetId, string $title, array $values): void
    {
        $range = "'".str_replace("'", "''", $title)."'!A:K";
        $clear = $this->request()->post(
            self::API_BASE_URL.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range).':clear',
            [],
        );
        $this->ensureSuccessful($clear, "clear {$title}");

        $write = $this->request()->put(
            self::API_BASE_URL.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range).'?valueInputOption=RAW',
            ['range' => $range, 'majorDimension' => 'ROWS', 'values' => $values],
        );
        $this->ensureSuccessful($write, "write {$title}");
    }

    /** @param array<int, array<string, mixed>> $requests */
    private function batchUpdate(string $spreadsheetId, array $requests): Response
    {
        $response = $this->request()->post(
            self::API_BASE_URL.'/'.rawurlencode($spreadsheetId).':batchUpdate',
            ['requests' => $requests],
        );
        $this->ensureSuccessful($response, 'update spreadsheet tabs');

        return $response;
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(max(5, (int) config('google_sheets.timeout_seconds', 30)));
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));

        return Cache::remember(
            'google-sheets-access-token-'.md5($clientEmail),
            now()->addMinutes(55),
            fn (): string => $this->requestAccessToken($credentials),
        );
    }

    /** @param array<string, mixed> $credentials */
    private function requestAccessToken(array $credentials): string
    {
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = str_replace('\\n', "\n", trim((string) ($credentials['private_key'] ?? '')));
        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException('Google Sheets service account credentials must include client_email and private_key.');
        }

        $now = time();
        $assertion = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
            .$this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
        $signature = '';
        if (! openssl_sign($assertion, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the Google Sheets service account assertion.');
        }

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion.'.'.$this->base64UrlEncode($signature),
        ]);
        $this->ensureSuccessful($response, 'request a Google access token');
        $token = (string) $response->json('access_token', '');
        if ($token === '') {
            throw new \RuntimeException('Google access token response was empty.');
        }

        return $token;
    }

    /** @return array<string, mixed> */
    private function credentials(): array
    {
        $json = trim((string) config('google_sheets.service_account_json'));
        if ($json === '') {
            $base64 = trim((string) config('google_sheets.service_account_json_base64'));
            if ($base64 !== '') {
                $json = (string) base64_decode($base64, true);
            }
        }
        if ($json === '') {
            $path = trim((string) config('google_sheets.service_account_json_path'));
            if ($path !== '' && is_file($path)) {
                $json = (string) file_get_contents($path);
            }
        }
        $credentials = json_decode($json, true);
        if (! is_array($credentials)) {
            throw new \RuntimeException('Google Sheets service account JSON is not configured or invalid.');
        }
        if (Str::startsWith((string) ($credentials['private_key'] ?? ''), '"')) {
            $credentials['private_key'] = trim((string) $credentials['private_key'], '"');
        }

        return $credentials;
    }

    private function ensureSuccessful(Response $response, string $action): void
    {
        if ($response->failed()) {
            throw new \RuntimeException("Unable to {$action}: ".$response->body());
        }
    }

    private function tabTitle(string $handle): string
    {
        $title = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', trim($handle));
        $title = preg_replace('/-+/', '-', $title) ?: 'collection';

        return Str::limit($title, 100, '');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
