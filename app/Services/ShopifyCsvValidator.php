<?php

namespace App\Services;

use League\Csv\Reader;

final class ShopifyCsvValidator
{
    /**
     * @return array{valid:bool, errors:array<int,string>}
     */
    public function validateAgainstTemplate(string $csvPath, string $templatePath): array
    {
        $errors = [];

        if (!is_file($templatePath)) {
            return [
                'valid' => false,
                'errors' => ["Template file missing: {$templatePath}"],
            ];
        }

        $template = Reader::createFromPath($templatePath);
        $template->setHeaderOffset(0);
        $expectedHeaders = $template->getHeader();

        $csv = Reader::createFromPath($csvPath);
        try {
            $csv->setHeaderOffset(0);
            $headers = $csv->getHeader();
        } catch (\League\Csv\SyntaxError $e) {
            return [
                'valid' => false,
                'errors' => ['Header error: ' . $e->getMessage()],
            ];
        }

        if ($headers !== $expectedHeaders) {
            $errors[] = 'Header order or names do not match the template.';
        }

        $expectedCount = count($expectedHeaders);

        $raw = Reader::createFromPath($csvPath);
        $raw->setHeaderOffset(null);
        $rowIndex = 0;
        foreach ($raw->getRecords() as $row) {
            $rowIndex++;
            if ($rowIndex === 1) {
                continue;
            }

            if (count($row) !== $expectedCount) {
                $errors[] = "Row {$rowIndex}: expected {$expectedCount} columns, found " . count($row) . '.';
            }
        }

        try {
            $csv->setHeaderOffset(0);
            $rowIndex = 1;
            foreach ($csv->getRecords() as $row) {
            $rowIndex++;

            $handle = trim((string) ($row[HeaderStore::HANDLE] ?? ''));
            $rowLabel = $handle !== '' ? "Handle {$handle}" : "Row {$rowIndex}";
            if ($handle === '') {
                $errors[] = "{$rowLabel}: Handle is required.";
            }

            $published = $this->normalizeBool($row[HeaderStore::PUBLISHED] ?? null);
            if ($published === null && ($row[HeaderStore::PUBLISHED] ?? '') !== '') {
                $errors[] = "{$rowLabel}: Published must be true/false or blank.";
            }

            $requiresShipping = $this->normalizeBool($row['Variant Requires Shipping'] ?? null);
            if ($requiresShipping === null && ($row['Variant Requires Shipping'] ?? '') !== '') {
                $errors[] = "{$rowLabel}: Variant Requires Shipping must be true/false or blank.";
            }

            $taxable = $this->normalizeBool($row['Variant Taxable'] ?? null);
            if ($taxable === null && ($row['Variant Taxable'] ?? '') !== '') {
                $errors[] = "{$rowLabel}: Variant Taxable must be true/false or blank.";
            }

            $this->checkNumeric($errors, $rowLabel, $row, 'Variant Grams');
            $this->checkNumeric($errors, $rowLabel, $row, 'Variant Inventory Qty');
            $this->checkNumeric($errors, $rowLabel, $row, 'Variant Price');
            $this->checkNumeric($errors, $rowLabel, $row, 'Variant Compare At Price');
            $this->checkNumeric($errors, $rowLabel, $row, 'Image Position');
            $this->checkNumeric($errors, $rowLabel, $row, 'Product rating count (product.metafields.reviews.rating_count)');
            $this->checkNumeric($errors, $rowLabel, $row, 'Cost per item');

            $this->checkCategoryFormat($errors, $rowLabel, $row);
            foreach (HeaderStore::semicolonSeparatedHeaders() as $header) {
                $this->checkSemicolonSeparator($errors, $rowLabel, $row, $header);
            }

        }
        } catch (\League\Csv\SyntaxError $e) {
            $errors[] = 'Header error: ' . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim((string) $value));
        if ($trimmed === '') {
            return null;
        }

        if (in_array($trimmed, ['true', 'false'], true)) {
            return $trimmed === 'true';
        }

        return null;
    }

    private function checkNumeric(array &$errors, string $rowLabel, array $row, string $header): void
    {
        if (!array_key_exists($header, $row)) {
            return;
        }

        $value = trim((string) $row[$header]);
        if ($value === '') {
            return;
        }

        $lower = strtolower($value);
        if (in_array($lower, ['n/a', 'na', 'null', '-', '--'], true)) {
            return;
        }

        $normalized = ltrim($value, "'");
        $normalized = str_replace(',', '', $normalized);
        if ($normalized === '') {
            return;
        }
        if (!is_numeric($normalized)) {
            $errors[] = "{$rowLabel}: {$header} must be numeric.";
        }
    }

    private function checkCategoryFormat(array &$errors, string $rowLabel, array $row): void
    {
        if (!array_key_exists(HeaderStore::PRODUCT_CATEGORY, $row)) {
            return;
        }

        $value = trim((string) $row[HeaderStore::PRODUCT_CATEGORY]);
        if ($value === '') {
            return;
        }

        if (str_contains($value, '>') && !str_contains($value, ' > ')) {
            $errors[] = "{$rowLabel}: Product Category must use ' > ' separators.";
        }
    }

    private function checkSemicolonSeparator(array &$errors, string $rowLabel, array $row, string $header): void
    {
        if (!array_key_exists($header, $row)) {
            return;
        }

        $value = trim((string) $row[$header]);
        if ($value === '') {
            return;
        }

        if (str_contains($value, ',')) {
            $errors[] = "{$rowLabel}: {$header} must use ';' separators (no commas).";
        }
    }

}
