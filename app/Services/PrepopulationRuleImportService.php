<?php

namespace App\Services;

use App\Models\PrepopulationRule;
use League\Csv\Reader;

final class PrepopulationRuleImportService
{
    public function import(string $path): int
    {
        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);
        $count = 0;

        foreach ($csv->getRecords() as $row) {
            $behavior = $this->value($row, 'Behavior');
            $handle = $this->handle($row);
            if ($behavior === null || $handle === null) {
                continue;
            }

            PrepopulationRule::query()->updateOrCreate(
                ['behavior' => $behavior, 'handle' => $handle],
                [
                    'collection_name' => $this->value($row, 'Collection Name'),
                    'layer' => $this->value($row, 'Layer'),
                    'where_it_appears' => $this->value($row, 'Where It Appears'),
                    'parents' => $this->list($row, 'Parents / Depends On'),
                    'add_tags' => $this->list($row, 'Full Auto Tags - ADD'),
                    'remove_tags' => $this->list($row, 'Remove Tags'),
                    'auto_vendor' => $this->value($row, 'Auto Vendor'),
                    'auto_type' => $this->value($row, 'Auto Type'),
                    'auto_cms_collection' => $this->value($row, 'Auto CMS Collection'),
                    'auto_product_category' => $this->value($row, 'Auto Product Category'),
                    'auto_google_product_category' => $this->value($row, 'Auto Google Product Category'),
                    'auto_design' => $this->value($row, 'Auto Design (apply when trigger fires)'),
                    'auto_colour_style' => $this->value($row, 'Auto Colour Style (apply when trigger fires)'),
                    'auto_jewelry_type' => $this->value($row, 'Auto Jewelry Type'),
                    'auto_target_gender' => $this->value($row, 'Auto Target Gender'),
                    'auto_age_group' => $this->value($row, 'Auto Age Group'),
                    'auto_status' => $this->value($row, 'Auto Status'),
                    'flag' => $this->value($row, 'Flag'),
                    'notes' => $this->value($row, 'Notes'),
                    'source_refs' => $this->value($row, 'Source Refs'),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function value(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value === '' || str_starts_with($value, '—') ? null : $value;
    }

    private function handle(array $row): ?string
    {
        $value = trim((string) ($row['Handle'] ?? ''));
        if ($value === '') {
            return null;
        }

        return str_starts_with($value, '—') ? PrepopulationRule::HANDLE_GLOBAL_DEFAULTS : $value;
    }

    /** @return array<int, string> */
    private function list(array $row, string $key): array
    {
        $value = $this->value($row, $key);
        if ($value === null) {
            return [];
        }

        return collect(preg_split('/[,;]+/', $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique(fn (string $item): string => strtolower($item))
            ->values()
            ->all();
    }
}
