<?php

namespace Database\Seeders;

use App\Models\DropdownOption;
use App\Services\HeaderStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class DropdownOptionSeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/public/template/drp-downs.csv');
        if (!is_file($path)) {
            return;
        }

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        if (empty($headers)) {
            return;
        }

        $collectionHeader = 'Collections/ Style';
        $productTypeHeader = 'Product Type/ Style ';
        $headerMap = [
            'Colour' => HeaderStore::COLOR_METAFIELD,
            'Jewelry material' => HeaderStore::JEWELRY_MATERIAL,
            'Age group' => HeaderStore::AGE_GROUP,
            'Jewelry type' => HeaderStore::JEWELRY_TYPE,
            'Target gender' => HeaderStore::TARGET_GENDER,
        ];

        $rows = [];
        $sort = 0;
        foreach ($csv->getRecords() as $record) {
            $collectionStyle = $this->normalizeValue($record[$collectionHeader] ?? null);
            $productType = $this->normalizeValue($record[$productTypeHeader] ?? null);
            $vendor = $this->deriveVendor($collectionStyle, $productType);

            foreach ($headers as $header) {
                if ($header === $collectionHeader || $header === $productTypeHeader) {
                    continue;
                }

                $raw = $record[$header] ?? null;
                $values = $this->parseValues($raw);
                if (empty($values)) {
                    continue;
                }

                $targetHeader = $headerMap[$header] ?? $header;
                foreach ($values as $value) {
                    $rows[] = [
                        'header' => $targetHeader,
                        'value' => $value,
                        'vendor' => $targetHeader === HeaderStore::COLOR_METAFIELD ? $vendor : null,
                        'product_type' => $targetHeader === HeaderStore::COLOR_METAFIELD ? $productType : null,
                        'collection_style' => $collectionStyle,
                        'active' => true,
                        'sort_order' => $sort++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        DB::table('dropdown_options')->truncate();
        if (!empty($rows)) {
            DB::table('dropdown_options')->insert($rows);
        }
    }

    private function parseValues(mixed $raw): array
    {
        $value = $this->normalizeValue($raw);
        if ($value === null) {
            return [];
        }

        $delimiter = null;
        if (str_contains($value, ';')) {
            $delimiter = ';';
        } elseif (str_contains($value, ',')) {
            $delimiter = ',';
        }

        if ($delimiter === null) {
            return [$value];
        }

        $parts = array_map('trim', explode($delimiter, $value));
        return array_values(array_filter($parts, fn (string $part) => $part !== ''));
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function deriveVendor(?string $collectionStyle, ?string $productType): ?string
    {
        if ($collectionStyle === null) {
            return null;
        }

        if ($productType === null) {
            return $collectionStyle;
        }

        $lower = strtolower($collectionStyle);
        $typeLower = strtolower($productType);
        if (str_ends_with($lower, $typeLower)) {
            $vendor = trim(substr($collectionStyle, 0, strlen($collectionStyle) - strlen($productType)));
            return $vendor !== '' ? $vendor : null;
        }

        return $collectionStyle;
    }
}
