<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GoogleSearchConsoleClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE_URL = 'https://www.googleapis.com/webmasters/v3';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchAnalyticsRows(string $startDate, string $endDate, string $dimension, int $rowLimit = 25000, int $maxRows = 100000): array
    {
        $siteUrl = trim((string) config('search_console.site_url'));
        if ($siteUrl === '') {
            throw new \RuntimeException('SEARCH_CONSOLE_SITE_URL is not configured.');
        }

        $dimension = strtolower(trim($dimension));
        if (!in_array($dimension, ['site', 'query', 'page'], true)) {
            throw new \InvalidArgumentException('Search Console dimension must be site, query, or page.');
        }

        $rowLimit = max(1, min(25000, $rowLimit));
        $maxRows = max($rowLimit, $maxRows);
        $rows = [];
        $startRow = 0;

        while (count($rows) < $maxRows) {
            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'rowLimit' => $dimension === 'site' ? 1 : min($rowLimit, $maxRows - count($rows)),
                'startRow' => $startRow,
            ];

            if ($dimension !== 'site') {
                $payload['dimensions'] = [$dimension];
            }

            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->post(self::API_BASE_URL . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', $payload);

            if ($response->failed()) {
                throw new \RuntimeException('Search Console API request failed: ' . $response->body());
            }

            $pageRows = $response->json('rows', []);
            if (!is_array($pageRows) || $pageRows === []) {
                break;
            }

            foreach ($pageRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rows[] = [
                    'entity' => $dimension === 'site'
                        ? 'site'
                        : (string) data_get($row, 'keys.0', ''),
                    'clicks' => (int) data_get($row, 'clicks', 0),
                    'impressions' => (int) data_get($row, 'impressions', 0),
                    'ctr' => ((float) data_get($row, 'ctr', 0)) * 100,
                    'position' => (float) data_get($row, 'position', 0),
                ];
            }

            if ($dimension === 'site' || count($pageRows) < $rowLimit) {
                break;
            }

            $startRow += count($pageRows);
        }

        return $rows;
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        $clientEmail = (string) ($credentials['client_email'] ?? '');

        return Cache::remember(
            'search-console-access-token-' . md5($clientEmail),
            now()->addMinutes(55),
            fn (): string => $this->requestAccessToken($credentials),
        );
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function requestAccessToken(array $credentials): string
    {
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = str_replace('\\n', "\n", trim((string) ($credentials['private_key'] ?? '')));

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException('Search Console service account credentials must include client_email and private_key.');
        }

        $now = time();
        $assertion = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR))
            . '.'
            . $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

        $signature = '';
        if (!openssl_sign($assertion, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign Search Console service account assertion.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion . '.' . $this->base64UrlEncode($signature),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Search Console token request failed: ' . $response->body());
        }

        $token = (string) $response->json('access_token', '');
        if ($token === '') {
            throw new \RuntimeException('Search Console token response did not include an access token.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        $json = trim((string) config('search_console.service_account_json'));

        if ($json === '') {
            $base64 = trim((string) config('search_console.service_account_json_base64'));
            if ($base64 !== '') {
                $decoded = base64_decode($base64, true);
                if ($decoded === false) {
                    throw new \RuntimeException('SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON_BASE64 is not valid base64.');
                }

                $json = $decoded;
            }
        }

        if ($json === '') {
            $path = trim((string) config('search_console.service_account_json_path'));
            if ($path !== '' && is_file($path)) {
                $json = (string) file_get_contents($path);
            }
        }

        if ($json === '') {
            throw new \RuntimeException('Search Console service account JSON is not configured.');
        }

        $credentials = json_decode($json, true);
        if (!is_array($credentials)) {
            throw new \RuntimeException('Search Console service account JSON could not be decoded.');
        }

        if (Str::startsWith((string) ($credentials['private_key'] ?? ''), '"')) {
            $credentials['private_key'] = trim((string) $credentials['private_key'], '"');
        }

        return $credentials;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
