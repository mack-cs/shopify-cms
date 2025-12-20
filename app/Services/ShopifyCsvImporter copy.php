<?php

namespace App\Services;

use App\Models\Import;
use App\Models\ShopifyRow;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class ShopifyCsvImporter
{
    public function __construct(
        private readonly RowClassifier $classifier,
        private readonly Normalizer $normalizer,
    ) {}

    /**
     * Import Shopify CSV:
     * - Stores full rows in shopify_rows.data (no columns lost)
     * - Stores header order in imports.headers
     * - Classifies row types
     * - Builds normalized tables for editing
     */
    public function import(string $pathToCsv, string $filename, string $mode, int $userId): Import
    {
        $import = Import::create([
            'filename' => $filename,
            'mode' => $mode,
            'status' => 'processing',
            'created_by' => $userId,
        ]);

        DB::transaction(function () use ($import, $pathToCsv) {
            $csv = Reader::createFromPath($pathToCsv);
            $csv->setHeaderOffset(0);
            $headers = $csv->getHeader(); // exact header names in order

            $import->headers = $headers;
            $import->save();

            $seenFirstRowForHandle = []; // handle => bool
            $i = 0;

            foreach ($csv->getRecords() as $record) {
                // $record is already header=>value
                $row = $record;

                $handle = trim((string)($row[HeaderStore::HANDLE] ?? '')) ?: null;

                $isFirst = false;
                if ($handle !== null) {
                    $isFirst = !isset($seenFirstRowForHandle[$handle]);
                    $seenFirstRowForHandle[$handle] = true;
                }

                $meta = $this->classifier->classify($row, $isFirst);

                ShopifyRow::create([
                    'import_id' => $import->id,
                    'row_index' => $i++,
                    'handle' => $handle,
                    'row_type' => $meta['row_type'],
                    'variant_key' => $meta['variant_key'],
                    'image_key' => $meta['image_key'],
                    'data' => $this->normalizeRowToAllHeaders($row, $headers),
                ]);
            }
        });

        // Build normalized records for editing
        $this->normalizer->buildNormalizedTables($import);

        $import->status = 'ready';
        $import->save();

        return $import;
    }

    /**
     * Ensure every header exists as key (so export always has all columns).
     */
    private function normalizeRowToAllHeaders(array $row, array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            $out[$h] = $row[$h] ?? '';
        }
        return $out;
    }
}
