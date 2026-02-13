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

        $total = 0;
        $updated = 0;
        $skippedMissingHandle = 0;
        $skippedNotFound = 0;

        foreach ($csv->getRecords() as $row) {
            $total++;

            $handle = trim((string) ($row[$map['handle']] ?? ''));
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
            if ($map['title'] !== null) {
                $payload['draft_title'] = $this->nullIfEmpty($row[$map['title']] ?? null);
            }
            if ($map['description_html'] !== null) {
                $payload['draft_description_html'] = $this->nullIfEmpty($row[$map['description_html']] ?? null);
            }
            if ($map['seo_title'] !== null) {
                $payload['draft_seo_title'] = $this->nullIfEmpty($row[$map['seo_title']] ?? null);
            }
            if ($map['seo_description'] !== null) {
                $payload['draft_seo_description'] = $this->nullIfEmpty($row[$map['seo_description']] ?? null);
            }

            if ($payload === []) {
                continue;
            }

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
}
