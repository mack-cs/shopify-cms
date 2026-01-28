<?php

namespace Database\Seeders;

use App\Models\DropdownOption;
use App\Services\TagNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

class DropdownOptionSeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/public/template/dropdown-seed.csv');
        if (!is_file($path)) {
            return;
        }

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);
        $rawHeaders = $csv->getHeader();
        if (empty($rawHeaders)) {
            return;
        }

        $headerLookup = [];
        foreach ($rawHeaders as $rawHeader) {
            $headerLookup[$rawHeader] = $this->normalizeHeader($rawHeader);
        }

        $collectionHeader = $this->findHeaderByNormalized($headerLookup, 'Collection');
        if ($collectionHeader === null) {
            return;
        }
        $tagsHeader = $this->findHeaderByNormalized($headerLookup, 'Tags');
        if ($tagsHeader === null) {
            return;
        }

        $rows = [];
        $sort = 0;
        foreach ($csv->getRecords() as $record) {
            $collectionStyle = $this->normalizeValue($record[$collectionHeader] ?? null);
            $tagsValue = $this->normalizeValue($record[$tagsHeader] ?? null);
            $tags = $this->normalizeTags($tagsValue);
            $tagPrimary = $tags[0] ?? null;
            $tagSecondary = $tags[1] ?? null;

            foreach ($headerLookup as $rawHeader => $normalizedHeader) {
                if ($rawHeader === $collectionHeader || $rawHeader === $tagsHeader) {
                    continue;
                }

                $raw = $record[$rawHeader] ?? null;
                $values = $this->parseValues($raw);
                if (empty($values)) {
                    continue;
                }

                $targetHeader = $normalizedHeader;
                foreach ($values as $value) {
                    $rows[] = [
                        'header' => $targetHeader,
                        'value' => $value,
                        'vendor' => null,
                        'product_type' => null,
                        'collection_style' => $collectionStyle,
                        'collection_tag_primary' => $tagPrimary,
                        'collection_tag_secondary' => $tagSecondary,
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

    private function normalizeHeader(string $header): string
    {
        $header = str_replace("\r", '', $header);
        $lines = preg_split('/\n/', $header) ?: [$header];
        return trim($lines[0] ?? $header);
    }

    private function findHeaderByNormalized(array $headerLookup, string $normalizedHeader): ?string
    {
        foreach ($headerLookup as $rawHeader => $normalized) {
            if ($normalized === $normalizedHeader) {
                return $rawHeader;
            }
        }

        return null;
    }

    private function normalizeTags(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return TagNormalizer::parseTokens($value);
    }
}
