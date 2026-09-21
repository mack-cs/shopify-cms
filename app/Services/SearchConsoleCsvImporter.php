<?php

namespace App\Services;

use Illuminate\Support\Str;

final class SearchConsoleCsvImporter
{
    public function __construct(
        private readonly SearchConsoleMetricImportService $metricImporter,
    ) {}

    /**
     * @return array{period_id:int,total:int,imported:int,skipped:int}
     */
    public function import(string $path, string $entityType, string $label, ?string $startDate = null, ?string $endDate = null): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("CSV file was not found: {$path}");
        }

        $entityType = strtolower(trim($entityType));
        if (!in_array($entityType, ['site', 'query', 'page'], true)) {
            throw new \InvalidArgumentException('Search Console import type must be site, query, or page.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV file: {$path}");
        }

        try {
            $headers = fgetcsv($handle);
            if (!is_array($headers)) {
                throw new \RuntimeException('CSV file is missing a header row.');
            }

            $map = $this->headerMap($headers, $entityType);
            $rows = $this->rows($handle, $map);

            return $this->metricImporter->importRows($rows, $entityType, $label, $startDate, $endDate);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private function headerMap(array $headers, string $entityType): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);
            if ($entityType !== 'site' && in_array($normalized, $entityType === 'query'
                ? ['query', 'queries', 'topqueries', 'searchquery']
                : ['page', 'pages', 'toppages', 'url'], true)) {
                $map['entity'] = $index;
            } elseif ($normalized === 'clicks') {
                $map['clicks'] = $index;
            } elseif ($normalized === 'impressions') {
                $map['impressions'] = $index;
            } elseif (in_array($normalized, ['ctr', 'clickthroughrate'], true)) {
                $map['ctr'] = $index;
            } elseif (in_array($normalized, ['position', 'avgposition', 'averageposition'], true)) {
                $map['position'] = $index;
            }
        }

        $requiredColumns = $entityType === 'site'
            ? ['clicks', 'impressions', 'position']
            : ['entity', 'clicks', 'impressions', 'position'];

        foreach ($requiredColumns as $required) {
            if (!array_key_exists($required, $map)) {
                throw new \RuntimeException("CSV is missing required Search Console column: {$required}.");
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->replace("\xEF\xBB\xBF", '')
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    /**
     * @param resource $handle
     * @param array<string, int> $map
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows($handle, array $map): \Generator
    {
        while (($row = fgetcsv($handle)) !== false) {
            yield [
                'entity' => $this->value($row, $map['entity'] ?? null),
                'clicks' => (int) $this->number($this->value($row, $map['clicks'] ?? null)),
                'impressions' => (int) $this->number($this->value($row, $map['impressions'] ?? null)),
                'ctr' => $this->number($this->value($row, $map['ctr'] ?? null)),
                'position' => $this->number($this->value($row, $map['position'] ?? null)),
            ];
        }
    }

    /**
     * @param array<int, string|null> $row
     */
    private function value(array $row, ?int $index): ?string
    {
        if ($index === null || !array_key_exists($index, $row)) {
            return null;
        }

        $value = trim((string) $row[$index]);

        return $value === '' ? null : $value;
    }

    private function number(?string $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $value = str_replace(['%', ',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
