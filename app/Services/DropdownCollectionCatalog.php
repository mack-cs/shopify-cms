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

    public function vendorForCollection(?string $collectionStyle): ?string
    {
        if (!is_string($collectionStyle) || trim($collectionStyle) === '') {
            return null;
        }

        foreach ($this->contexts() as $context) {
            if (strcasecmp((string) ($context['collection_style'] ?? ''), $collectionStyle) !== 0) {
                continue;
            }

            return $this->humanizeVendorTag($context['tag_primary'] ?? null);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function vendorsByCollection(): array
    {
        $vendors = [];

        foreach ($this->contexts() as $context) {
            $collectionStyle = $this->normalizeValue($context['collection_style'] ?? null);
            $vendor = $this->humanizeVendorTag($context['tag_primary'] ?? null);
            if ($collectionStyle === null || $vendor === null) {
                continue;
            }

            $vendors[strtolower($collectionStyle)] = $vendor;
        }

        return $vendors;
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

    private function humanizeVendorTag(mixed $value): ?string
    {
        $tag = $this->normalizeValue($value);
        if ($tag === null) {
            return null;
        }

        $parts = preg_split('/[-_]+/', strtolower($tag)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));

        if (empty($parts)) {
            return null;
        }

        return implode(' ', array_map(fn (string $part): string => ucfirst($part), $parts));
    }
}
