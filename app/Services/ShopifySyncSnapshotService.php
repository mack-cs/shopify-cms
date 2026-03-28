<?php

namespace App\Services;

use App\Models\Import;
use App\Models\ShopifyRow;
use App\Models\ShopifySyncSnapshot;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

class ShopifySyncSnapshotService
{
    public function generateForImport(Import $import): ShopifySyncSnapshot
    {
        $headers = $this->resolveHeaders($import);
        $timestamp = now()->format('Ymd_His');
        $filename = "shopify-sync-import-{$import->id}-{$timestamp}.csv";
        $storagePath = "shopify-sync-snapshots/import-{$import->id}/{$filename}";

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create temporary CSV stream.');
        }

        $writer = Writer::createFromStream($stream);
        $writer->insertOne($headers);

        $rowCount = 0;
        $previousSnapshot = $import->syncSnapshot;
        ShopifyRow::query()
            ->where('import_id', $import->id)
            ->orderBy('row_index')
            ->chunk(500, function ($rows) use ($writer, $headers, &$rowCount): void {
                foreach ($rows as $row) {
                    $writer->insertOne($this->csvRowForShopifyRow($row, $headers));
                    $rowCount++;
                }
            });

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Unable to read generated Shopify snapshot CSV.');
        }

        Storage::disk('public')->put($storagePath, $csv);

        if (
            $previousSnapshot
            && filled($previousSnapshot->storage_path)
            && $previousSnapshot->storage_path !== $storagePath
        ) {
            Storage::disk($previousSnapshot->storage_disk ?: 'public')->delete($previousSnapshot->storage_path);
        }

        return ShopifySyncSnapshot::query()->updateOrCreate(
            ['import_id' => $import->id],
            [
                'created_by' => $import->created_by,
                'storage_disk' => 'public',
                'storage_path' => $storagePath,
                'filename' => $filename,
                'row_count' => $rowCount,
                'header_count' => count($headers),
                'generated_at' => now(),
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolveHeaders(Import $import): array
    {
        $headers = array_values(array_filter(
            array_map(
                static fn ($header): string => trim((string) $header),
                $import->headers ?? []
            ),
            static fn (string $header): bool => $header !== ''
        ));

        if (!empty($headers)) {
            return $headers;
        }

        $firstData = ShopifyRow::query()
            ->where('import_id', $import->id)
            ->orderBy('row_index')
            ->value('data');

        if (is_array($firstData) && !empty($firstData)) {
            return array_keys($firstData);
        }

        return HeaderStore::knownHeaders();
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    private function csvRowForShopifyRow(ShopifyRow $row, array $headers): array
    {
        $data = is_array($row->data) ? $row->data : [];

        return array_map(static function (string $header) use ($data): string {
            $value = $data[$header] ?? '';

            if (is_bool($value)) {
                return $value ? 'TRUE' : 'FALSE';
            }

            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }

            return trim((string) $value);
        }, $headers);
    }
}
