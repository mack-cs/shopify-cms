<?php

namespace App\Services\GoogleSheets;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class GoogleSheetsClient
{
    private const API = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const DATA_COLUMNS = 'A:AG';

    private const LEGACY_LAST_COLUMN = 'AJ';

    public function __construct(private readonly GoogleServiceAccountTokenProvider $tokens) {}

    /** @return array<int,array<int,mixed>> */
    public function values(string $tab): array
    {
        $response = $this->request()->get($this->valuesUrl($this->range($tab, self::DATA_COLUMNS)));
        $this->ensureSuccess($response->successful(), $response->body(), 'read');

        return (array) $response->json('values', []);
    }

    /** @param array<int,array{range:string,values:array<int,array<int,mixed>>}> $data */
    public function batchUpdateValues(array $data): void
    {
        foreach (array_chunk($data, 500) as $chunk) {
            $response = $this->request()->post($this->baseUrl().'/values:batchUpdate', [
                'valueInputOption' => 'RAW',
                'data' => $chunk,
            ]);
            $this->ensureSuccess($response->successful(), $response->body(), 'write');
        }
    }

    /** @param array<int,array<int,mixed>> $rows */
    public function append(string $tab, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $url = $this->valuesUrl($this->range($tab, self::DATA_COLUMNS)).':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';
        $response = $this->request()->post($url, ['values' => $rows]);
        $this->ensureSuccess($response->successful(), $response->body(), 'append');
    }

    public function ensureTab(string $tab): void
    {
        $metadata = $this->request()->get($this->baseUrl(), ['fields' => 'sheets.properties.title']);
        $this->ensureSuccess($metadata->successful(), $metadata->body(), 'metadata');
        $exists = collect((array) $metadata->json('sheets', []))->contains(
            fn (array $item): bool => data_get($item, 'properties.title') === $tab
        );
        if ($exists) {
            return;
        }

        $response = $this->request()->post($this->baseUrl().':batchUpdate', [
            'requests' => [['addSheet' => ['properties' => ['title' => $tab]]]],
        ]);
        $this->ensureSuccess($response->successful(), $response->body(), 'create Change Log tab');
    }

    /** @param array<int,array<int,mixed>> $rows */
    public function replaceBody(string $tab, array $rows, int $existingRowCount): void
    {
        if ($rows !== []) {
            $this->batchUpdateValues([[
                'range' => $this->range($tab, 'A2:AG'.(count($rows) + 1)),
                'values' => $rows,
            ]]);
        }
        if ($existingRowCount > count($rows)) {
            $firstStaleRow = count($rows) + 2;
            $lastExistingRow = $existingRowCount + 1;
            $clear = $this->request()->withBody('{}', 'application/json')
                ->post($this->valuesUrl($this->range($tab, "A{$firstStaleRow}:AG{$lastExistingRow}")).':clear');
            $this->ensureSuccess($clear->successful(), $clear->body(), 'clear stale Master rows');
        }
    }

    /** @param array<int,array<int,mixed>> $rows */
    public function replaceAll(string $tab, array $rows, int $existingRowCount): void
    {
        $rowsToClear = max(1, $existingRowCount, count($rows));
        $clear = $this->request()->withBody('{}', 'application/json')->post(
            $this->valuesUrl($this->range($tab, 'A1:'.self::LEGACY_LAST_COLUMN.$rowsToClear)).':clear'
        );
        $this->ensureSuccess($clear->successful(), $clear->body(), 'clear existing Sheet layout');

        if ($rows !== []) {
            $this->batchUpdateValues([[
                'range' => $this->range($tab, 'A1:AG'.count($rows)),
                'values' => $rows,
            ]]);
        }
    }

    /** @param array<int,int> $oneBasedRows */
    public function deleteRows(string $tab, array $oneBasedRows): void
    {
        if ($oneBasedRows === []) {
            return;
        }
        $metadata = $this->request()->get($this->baseUrl(), ['fields' => 'sheets.properties']);
        $this->ensureSuccess($metadata->successful(), $metadata->body(), 'metadata');
        $sheet = collect((array) $metadata->json('sheets', []))->first(
            fn (array $item): bool => data_get($item, 'properties.title') === $tab
        );
        if (! is_array($sheet)) {
            throw new \RuntimeException("Google Sheet tab [{$tab}] was not found.");
        }
        rsort($oneBasedRows);
        $requests = array_map(fn (int $row): array => ['deleteDimension' => ['range' => [
            'sheetId' => (int) data_get($sheet, 'properties.sheetId'),
            'dimension' => 'ROWS', 'startIndex' => $row - 1, 'endIndex' => $row,
        ]]], $oneBasedRows);
        $response = $this->request()->post($this->baseUrl().':batchUpdate', ['requests' => $requests]);
        $this->ensureSuccess($response->successful(), $response->body(), 'delete rows');
    }

    /** @param array<int,int> $dateColumns @param array<int,int> $dateTimeColumns */
    public function formatDateColumns(string $tab, array $dateColumns, array $dateTimeColumns = []): void
    {
        $metadata = $this->request()->get($this->baseUrl(), ['fields' => 'sheets.properties']);
        $this->ensureSuccess($metadata->successful(), $metadata->body(), 'metadata');
        $sheet = collect((array) $metadata->json('sheets', []))->first(
            fn (array $item): bool => data_get($item, 'properties.title') === $tab
        );
        if (! is_array($sheet)) {
            throw new \RuntimeException("Google Sheet tab [{$tab}] was not found.");
        }

        $sheetId = (int) data_get($sheet, 'properties.sheetId');
        $requests = [];
        foreach ([['columns' => $dateColumns, 'pattern' => 'dd/MM/yyyy'],
            ['columns' => $dateTimeColumns, 'pattern' => 'dd/MM/yyyy HH:mm']] as $group) {
            foreach ($group['columns'] as $column) {
                $requests[] = ['repeatCell' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'startRowIndex' => 1,
                        'startColumnIndex' => $column,
                        'endColumnIndex' => $column + 1,
                    ],
                    'cell' => ['userEnteredFormat' => ['numberFormat' => [
                        'type' => $group['pattern'] === 'dd/MM/yyyy' ? 'DATE' : 'DATE_TIME',
                        'pattern' => $group['pattern'],
                    ]]],
                    'fields' => 'userEnteredFormat.numberFormat',
                ]];
            }
        }
        if ($requests === []) {
            return;
        }
        $response = $this->request()->post($this->baseUrl().':batchUpdate', ['requests' => $requests]);
        $this->ensureSuccess($response->successful(), $response->body(), 'format date columns');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->tokens->token())
            ->acceptJson()->asJson()->timeout(max(5, (int) config('google_sheets.timeout_seconds', 60)));
    }

    private function baseUrl(): string
    {
        $id = trim((string) config('google_sheets.spreadsheet_id'));
        if ($id === '') {
            throw new \RuntimeException('GOOGLE_SHEETS_SPREADSHEET_ID is not configured.');
        }

        return self::API.'/'.rawurlencode($id);
    }

    private function valuesUrl(string $range): string
    {
        return $this->baseUrl().'/values/'.rawurlencode($range);
    }

    public function range(string $tab, string $cells): string
    {
        return "'".str_replace("'", "''", $tab)."'!{$cells}";
    }

    private function ensureSuccess(bool $successful, string $body, string $operation): void
    {
        if (! $successful) {
            throw new \RuntimeException("Google Sheets {$operation} failed: {$body}");
        }
    }
}
