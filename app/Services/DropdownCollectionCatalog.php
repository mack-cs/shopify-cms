<?php

namespace App\Services;

use League\Csv\Reader;

final class DropdownCollectionCatalog
{
    /**
     * @return array<int, array{collection_style:string,tag_primary:string,tag_secondary:?string}>
     */
    public function contexts(): array
    {
        $path = storage_path('app/public/template/dropdown-seed.csv');
        if (!is_file($path)) {
            return [];
        }

        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);
        $rawHeaders = $csv->getHeader();
        if (empty($rawHeaders)) {
            return [];
        }

        $headerLookup = [];
        foreach ($rawHeaders as $rawHeader) {
            $headerLookup[$rawHeader] = $this->normalizeHeader($rawHeader);
        }

        $collectionHeader = $this->findHeaderByNormalized($headerLookup, 'Collection');
        $tagsHeader = $this->findHeaderByNormalized($headerLookup, 'Tags');
        if ($collectionHeader === null || $tagsHeader === null) {
            return [];
        }

        $contexts = [];
        foreach ($csv->getRecords() as $record) {
            $collectionStyle = $this->normalizeValue($record[$collectionHeader] ?? null);
            if ($collectionStyle === null) {
                continue;
            }

            $tagsValue = $this->normalizeValue($record[$tagsHeader] ?? null);
            $tags = TagNormalizer::parseTokens($tagsValue);
            $tagPrimary = $tags[0] ?? null;
            if ($tagPrimary === null || $tagPrimary === '') {
                continue;
            }

            $tagSecondary = $tags[1] ?? null;
            $key = strtolower($collectionStyle . '|' . $tagPrimary . '|' . ($tagSecondary ?? ''));
            $contexts[$key] = [
                'collection_style' => $collectionStyle,
                'tag_primary' => $tagPrimary,
                'tag_secondary' => $tagSecondary,
            ];
        }

        return array_values($contexts);
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

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}

