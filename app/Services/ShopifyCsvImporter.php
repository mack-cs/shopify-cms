<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use League\Csv\Reader;
use Illuminate\Support\Facades\DB;

class ShopifyCsvImporter
{
    public function __construct(
        private readonly RowClassifier $classifier,
        private readonly Normalizer $normalizer,
    ) {}

    public function importIntoExistingImport(Import $import, string $absolutePath): void
    {
        set_time_limit(300);
        DB::transaction(function () use ($import, $absolutePath) {

            // wipe previous processed data for this import (so re-processing works)
            ShopifyRow::where('import_id', $import->id)->delete();
            Product::where('import_id', $import->id)->delete();

            $csv = Reader::createFromPath($absolutePath);
            $csv->setHeaderOffset(0);

            $headers = $csv->getHeader();
            $import->headers = $headers;
            $import->save();

            $seen = [];
            $i = 0;

            foreach ($csv->getRecords() as $row) {
                $handle = trim((string)($row[HeaderStore::HANDLE] ?? '')) ?: null;

                $isFirst = false;
                if ($handle !== null) {
                    $isFirst = !isset($seen[$handle]);
                    $seen[$handle] = true;
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

        // build normalized tables from rows
        $this->normalizer->buildNormalizedTables($import);

        $import->update(['status' => 'ready']);
    }

    private function normalizeRowToAllHeaders(array $row, array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            $out[$h] = $row[$h] ?? '';
        }
        return $out;
    }
}
