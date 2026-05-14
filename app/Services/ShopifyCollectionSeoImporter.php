<?php

namespace App\Services;

use App\Models\Import;
use App\Models\ShopifyCollection;
use League\Csv\Reader;

final class ShopifyCollectionSeoImporter
{
    public function importFromPath(Import $import, string $path): array
    {
        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();

        $map = $this->headerMap($headers);
        $importBatch = 'batch' . now()->format('YmdHis');

        $total = 0;
        $updated = 0;
        $skippedMissingHandle = 0;
        $skippedNotFound = 0;

        foreach ($csv->getRecords() as $row) {
            $total++;

            $handle = trim((string) $this->mappedValue($row, $map['handle']));
            if ($handle === '') {
                $skippedMissingHandle++;
                continue;
            }

            $collection = ShopifyCollection::where('import_id', $import->id)
                ->where('handle', $handle)
                ->first();
            if (!$collection) {
                $skippedNotFound++;
                continue;
            }

            $payload = [];
            if ($this->hasMappedValue($row, $map['title'])) {
                $payload['draft_title'] = $this->nullIfEmpty($this->mappedValue($row, $map['title']));
            }
            if ($this->hasMappedValue($row, $map['description_html'])) {
                $payload['draft_description_html'] = $this->nullIfEmpty($this->mappedValue($row, $map['description_html']));
            }
            if ($this->hasMappedValue($row, $map['seo_title'])) {
                $payload['draft_seo_title'] = $this->nullIfEmpty($this->mappedValue($row, $map['seo_title']));
            }
            if ($this->hasMappedValue($row, $map['seo_description'])) {
                $payload['draft_seo_description'] = $this->nullIfEmpty($this->mappedValue($row, $map['seo_description']));
            }
            if ($this->hasMappedValue($row, $map['footer_title'])) {
                $payload['draft_footer_title'] = $this->nullIfEmpty($this->mappedValue($row, $map['footer_title']));
            }
            if ($this->hasMappedValue($row, $map['elegant_footer_description'])) {
                $payload['draft_elegant_footer_description'] = $this->nullIfEmpty($this->mappedValue($row, $map['elegant_footer_description']));
            }
            if ($this->hasMappedValue($row, $map['deindex'])) {
                $payload['deindex'] = $this->nullableBool($this->mappedValue($row, $map['deindex']));
            }

            if ($payload === []) {
                continue;
            }

            $payload['batch'] = $this->nullIfEmpty($this->mappedValue($row, $map['batch'])) ?? $importBatch;

            $collection->fill($payload);
            if ($collection->isDirty()) {
                $collection->save();
                $updated++;
            }
        }

        return [
            'total' => $total,
            'updated' => $updated,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_not_found' => $skippedNotFound,
            'batch' => $importBatch,
        ];
    }

    private function headerMap(array $headers): array
    {
        $index = [];
        foreach ($headers as $header) {
            $index[strtolower(trim((string) $header))] = $header;
        }

        $find = function (array $candidates) use ($index): ?string {
            foreach ($candidates as $candidate) {
                $key = strtolower($candidate);
                if (isset($index[$key])) {
                    return $index[$key];
                }
            }
            return null;
        };

        return [
            'handle' => $find(['handle', 'collection_handle', 'collection handle']),
            'title' => $find(['title', 'collection_title', 'collection title', 'page title']),
            'description_html' => $find(['description_html', 'description html', 'description', 'body_html', 'body html']),
            'seo_title' => $find(['seo_title', 'seo title', 'meta title']),
            'seo_description' => $find(['seo_description', 'seo description', 'meta description']),
            'footer_title' => $find(['footer_title', 'footer title', 'footer_description', 'footer description']),
            'elegant_footer_description' => $find(['elegant_footer_description', 'elegant footer description']),
            'deindex' => $find(['deindex', 'seo_deindex', 'hide_from_google', 'seo hidden']),
            'batch' => $find(['batch']),
        ];
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));
        if ($trimmed === '') {
            return null;
        }

        return match ($trimmed) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }

    private function mappedValue(array $row, ?string $header): mixed
    {
        if ($header === null || $header === '') {
            return null;
        }

        return $row[$header] ?? null;
    }

    private function hasMappedValue(array $row, ?string $header): bool
    {
        $value = $this->mappedValue($row, $header);

        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}
