<?php

namespace App\Services\GoogleSheets;

use App\Models\ChangeLog;
use App\Models\ProcurementCollectionConfig;
use App\Models\ProcurementIncomingStock;
use App\Models\Variant;
use App\Services\OperationalProcurementCollectionResolver;
use App\Services\ProcurementIncomingStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ProcurementSheetSyncService
{
    public function __construct(
        private readonly GoogleSheetsClient $sheets,
        private readonly ProcurementSheetSchema $schema,
        private readonly ProcurementIncomingStockService $incomingStock,
        private readonly OperationalProcurementCollectionResolver $collections,
        private readonly ProcurementSheetDatasetBuilder $dataset,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('google_sheets.enabled', false);
    }

    /** @return array{tabs:int,rows:int,changed:int} */
    public function pullHumanInputs(): array
    {
        if (! $this->enabled()) {
            return ['tabs' => 0, 'rows' => 0, 'changed' => 0];
        }
        $variantGroups = Variant::query()->active()->whereNotNull('sku')
            ->whereHas('product', fn ($query) => $query->activeStatus()->nonBundle())
            ->with('product')->get()
            ->groupBy(fn (Variant $variant): string => strtoupper(trim((string) $variant->sku)));
        $stats = ['tabs' => 0, 'rows' => 0, 'changed' => 0];
        $pending = [];

        foreach ($this->collections->configured() as $collection) {
            $tab = trim((string) $collection->google_sheet_tab_name);
            $values = $this->currentLayoutValues($tab);
            $headers = array_shift($values) ?? [];
            $map = $this->mapForTab($tab, $headers);
            $seen = [];
            foreach ($values as $offset => $row) {
                $sku = strtoupper(trim((string) ($row[$map['sku']] ?? '')));
                if ($sku === '') {
                    continue;
                }
                if (isset($seen[$sku])) {
                    throw new \RuntimeException("Duplicate SKU [{$sku}] in Google Sheet tab [{$tab}].");
                }
                $seen[$sku] = true;
                $matches = $variantGroups->get($sku, collect());
                if ($matches->count() !== 1) {
                    Log::warning('Google Sheet incoming-stock SKU could not be matched uniquely', [
                        'tab' => $tab, 'sku' => $sku, 'matches' => $matches->count(),
                    ]);

                    continue;
                }
                /** @var Variant $variant */
                $variant = $matches->first();
                try {
                    $resolved = $this->collections->resolve($variant->product);
                } catch (\DomainException $exception) {
                    Log::warning($exception->getMessage(), ['tab' => $tab, 'sku' => $sku]);

                    continue;
                }
                if ($resolved->id !== $collection->id) {
                    Log::warning('Google Sheet SKU is in the wrong operational collection tab', [
                        'sku' => $sku, 'tab' => $tab, 'expected_tab' => $resolved->google_sheet_tab_name,
                    ]);

                    continue;
                }
                $pending[] = [
                    'variant' => $variant,
                    'workflow' => [
                        'ignore' => $this->incomingStock->normalizeBoolean($row[$map['ignore']] ?? null),
                        'quantity_to_order' => $this->incomingStock->normalizeQuantity(
                            $row[$map['quantity_to_order']] ?? null,
                            'quantity_to_order'
                        ),
                    ],
                    'tab' => $tab,
                    'row' => $offset + 2,
                ];
                $stats['rows']++;
            }
            $stats['tabs']++;
        }

        DB::transaction(function () use ($pending, &$stats): void {
            foreach ($pending as $item) {
                /** @var Variant $variant */
                $variant = $item['variant'];
                $before = $variant->procurementIncomingStock;
                $previous = $before?->only(ProcurementSheetSchema::HUMAN_OWNED_FIELDS) ?? [];
                $this->incomingStock->updateFromSheet(
                    $variant, $item['workflow'], 'google_sheets:'.$item['tab'], $item['row']
                );
                $after = $variant->procurementIncomingStock()->first()?->only(ProcurementSheetSchema::HUMAN_OWNED_FIELDS) ?? [];
                if ($before === null || json_encode($previous) !== json_encode($after)) {
                    $stats['changed']++;
                }
            }
        });

        $this->publishChangeLogSafely();

        return $stats;
    }

    /** @return array{master_rows:int,brand_rows:int,tabs:int} */
    public function publish(): array
    {
        if (! $this->enabled()) {
            return ['master_rows' => 0, 'brand_rows' => 0, 'tabs' => 0];
        }
        $records = $this->dataset->records();
        if ($records === []) {
            throw new \RuntimeException('Refusing to publish an empty procurement dataset.');
        }
        $staleSkus = collect($records)->where('_prediction_stale', true)->pluck('sku')->values();
        if ($staleSkus->isNotEmpty()) {
            throw new \RuntimeException(
                "Refusing to publish because {$staleSkus->count()} SKU(s) have incoming-stock changes ".
                'that are not included in the latest completed prediction. Run php artisan procurement:run first.'
            );
        }

        $masterTab = trim((string) config('google_sheets.master_tab', 'master-file'));
        $masterValues = $this->currentLayoutValues($masterTab);
        $masterMap = $this->mapForTab($masterTab, array_shift($masterValues) ?? []);
        $brandSheets = [];
        foreach ($this->collections->configured() as $collection) {
            $tab = trim((string) $collection->google_sheet_tab_name);
            $values = $this->currentLayoutValues($tab);
            $headers = $values[0] ?? [];
            $this->mapForTab($tab, $headers);
            $brandSheets[$collection->id] = $values;
        }

        $this->backupBeforePublish($masterTab, $masterValues, $brandSheets);
        $this->sheets->replaceBody($masterTab, array_map(
            fn (array $record): array => $this->orderedRow($record, $masterMap),
            $records
        ), count($masterValues));
        $this->formatDateColumns($masterTab, $masterMap);

        $brandRows = 0;
        foreach ($this->collections->configured() as $collection) {
            $desired = collect($records)->where('_collection_id', $collection->id)
                ->keyBy('sku')->all();
            $brandRows += $this->publishBrand($collection, $desired, $brandSheets[$collection->id]);
        }

        $this->publishChangeLogSafely();

        return [
            'master_rows' => count($records), 'brand_rows' => $brandRows,
            'tabs' => $this->collections->configured()->count(),
        ];
    }

    /** Publish live inventory/order fields without requiring a fresh ML prediction. */
    public function publishOperational(array $variantIds = [], bool $includeHumanInputs = false): array
    {
        if (! $this->enabled()) {
            return ['rows' => 0, 'tabs' => 0];
        }
        $allRecords = collect($this->dataset->records());
        $records = $allRecords;
        if ($variantIds !== []) {
            $records = $records->whereIn('_variant_id', array_map('intval', $variantIds));
        }
        $ambiguitySource = $variantIds === [] ? $records : $allRecords->whereIn('sku', $records->pluck('sku'));
        $ambiguous = $ambiguitySource->groupBy('sku')->filter(fn ($group) => $group->count() > 1)->keys();
        if ($ambiguous->isNotEmpty()) {
            if ($variantIds !== []) {
                throw new \RuntimeException('Cannot operationally publish duplicate catalog SKU(s): '.$ambiguous->implode(', ').'.');
            }
            Log::warning('Skipping duplicate catalog SKUs during operational procurement Sheet publish', ['skus' => $ambiguous->all()]);
            $records = $records->reject(fn (array $record): bool => $ambiguous->contains($record['sku']));
        }
        $fields = [
            'current_inventory', 'total_quantity_on_order', 'number_of_wip_orders',
            'next_order_id', 'next_eta', 'second_order_id', 'second_eta',
            'projected_stock_before_second_eta', 'between_orders_stock_gap_status',
            'projected_inventory_position', 'predicted_runout_date',
            'replenishment_date', 'stock_gap_status', 'additional_order_required',
            'action_required', 'current_reserved_inventory',
            'current_on_hand_inventory', 'last_updated',
        ];
        if ($includeHumanInputs) {
            $fields[] = 'quantity_to_order';
        }
        $tabs = [[
            'name' => trim((string) config('google_sheets.master_tab', 'master-file')),
            'collection_id' => null,
        ]];
        foreach ($this->collections->configured() as $collection) {
            $tabs[] = ['name' => trim((string) $collection->google_sheet_tab_name), 'collection_id' => $collection->id];
        }
        $updated = 0;
        foreach ($tabs as $tabConfig) {
            $tab = $tabConfig['name'];
            $tabRecords = $tabConfig['collection_id'] === null
                ? $records : $records->where('_collection_id', $tabConfig['collection_id']);
            $targetSkus = $tabRecords->pluck('sku')->flip();
            $values = $this->currentLayoutValues($tab);
            $map = $this->mapForTab($tab, array_shift($values) ?? []);
            $rows = [];
            foreach ($values as $offset => $row) {
                $sku = strtoupper(trim((string) ($row[$map['sku']] ?? '')));
                if ($sku === '' || ! $targetSkus->has($sku)) {
                    continue;
                }
                if (isset($rows[$sku])) {
                    throw new \RuntimeException("Duplicate SKU [{$sku}] in Google Sheet tab [{$tab}].");
                }
                $rows[$sku] = $offset + 2;
            }
            $updates = [];
            foreach ($tabRecords as $record) {
                if (! isset($rows[$record['sku']])) {
                    continue;
                }
                foreach ($fields as $field) {
                    $column = $this->schema->columnName($map[$field]);
                    $updates[] = ['range' => $this->sheets->range($tab, $column.$rows[$record['sku']]), 'values' => [[$this->cell($record[$field] ?? null)]]];
                }
                $updated++;
            }
            $this->sheets->batchUpdateValues($updates);
            $this->formatDateColumns($tab, $map);
        }
        $this->publishChangeLogSafely();

        return ['rows' => $updated, 'tabs' => count($tabs)];
    }

    private function publishChangeLog(): void
    {
        $tab = trim((string) config('google_sheets.change_log_tab', 'Change Log'));
        $this->sheets->ensureTab($tab);
        $existing = $this->sheets->values($tab);
        $logs = ChangeLog::query()
            ->where('model_type', ProcurementIncomingStock::class)
            ->with(['changedBy:id,name', 'product:id,title'])
            ->latest('id')
            ->limit(5000)
            ->get();
        $skus = ProcurementIncomingStock::query()
            ->whereIn('id', $logs->pluck('model_id')->filter())
            ->pluck('sku', 'id');
        $rows = $logs->map(fn (ChangeLog $log): array => [
            $log->created_at?->format('d/m/Y H:i'),
            $skus[$log->model_id] ?? '',
            $log->product?->title ?? '',
            $log->field,
            $log->old_value,
            $log->new_value,
            $log->changedBy?->name ?? (str_starts_with((string) $log->source, 'google_sheets:') ? 'Google Sheets' : 'System'),
            $log->source,
            $log->id,
        ])->all();
        $values = [[
            'Changed At', 'SKU', 'Product', 'Field', 'Old Value', 'New Value',
            'Changed By', 'Source', 'CMS Log ID',
        ], ...$rows];

        $this->sheets->replaceAll($tab, $values, count($existing));
    }

    private function publishChangeLogSafely(): void
    {
        try {
            $this->publishChangeLog();
        } catch (\Throwable $exception) {
            Log::warning('Procurement Change Log Sheet could not be refreshed; CMS audit records remain available.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string,int> $map */
    private function formatDateColumns(string $tab, array $map): void
    {
        $this->sheets->formatDateColumns($tab, [
            $map['next_eta'], $map['second_eta'], $map['predicted_runout_date'], $map['replenishment_date'],
        ], [$map['last_updated']]);
    }

    /** @param array<string,array<string,mixed>> $desired */
    private function publishBrand(ProcurementCollectionConfig $collection, array $desired, array $values): int
    {
        $tab = trim((string) $collection->google_sheet_tab_name);
        $headers = array_shift($values) ?? [];
        $map = $this->mapForTab($tab, $headers);
        $existing = [];
        foreach ($values as $offset => $row) {
            $sku = strtoupper(trim((string) ($row[$map['sku']] ?? '')));
            if ($sku === '') {
                continue;
            }
            if (isset($existing[$sku])) {
                throw new \RuntimeException("Duplicate SKU [{$sku}] in Google Sheet tab [{$tab}].");
            }
            $existing[$sku] = $offset + 2;
        }

        $updates = [];
        $append = [];
        foreach ($desired as $sku => $record) {
            if (! isset($existing[$sku])) {
                $append[] = $this->orderedRow($record, $map);

                continue;
            }
            foreach (ProcurementSheetSchema::FIELDS as $field => $header) {
                if (in_array($field, ProcurementSheetSchema::HUMAN_OWNED_FIELDS, true)) {
                    continue;
                }
                $column = $this->schema->columnName($map[$field]);
                $updates[] = [
                    'range' => $this->sheets->range($tab, $column.$existing[$sku]),
                    'values' => [[$this->cell($record[$field] ?? null)]],
                ];
            }
        }
        $this->sheets->batchUpdateValues($updates);
        $this->sheets->append($tab, $append);
        $this->formatDateColumns($tab, $map);
        $staleRows = [];
        foreach ($existing as $sku => $row) {
            if (! isset($desired[$sku])) {
                $outstanding = ProcurementIncomingStock::query()
                    ->where('sku', $sku)->sum('total_quantity_on_order');
                if ($outstanding > 0) {
                    Log::warning('Removing an inactive procurement Sheet row with outstanding incoming stock retained in Laravel', [
                        'tab' => $tab, 'sku' => $sku, 'total_quantity_on_order' => $outstanding,
                    ]);
                }
                $staleRows[] = $row;
            }
        }
        $this->sheets->deleteRows($tab, $staleRows);

        return count($desired);
    }

    /** @param array<string,mixed> $record @param array<string,int> $map */
    private function orderedRow(array $record, array $map): array
    {
        $row = array_fill(0, max($map) + 1, '');
        foreach ($map as $field => $index) {
            $row[$index] = $this->cell($record[$field] ?? null);
        }

        return $row;
    }

    private function cell(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return $value ?? '';
    }

    /** @param array<int,mixed> $headers @return array<string,int> */
    private function mapForTab(string $tab, array $headers): array
    {
        try {
            return $this->schema->map($headers);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                "Google Sheet tab [{$tab}] has an invalid header row: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /** @return array<int,array<int,mixed>> */
    private function currentLayoutValues(string $tab): array
    {
        $values = $this->sheets->values($tab);
        if ($this->schema->isCurrentLayout($values[0] ?? [])) {
            return $values;
        }

        $upgraded = $this->schema->upgradeLayout($values);
        $name = now()->format('Y-m-d_His_u').'_layout_'.Str::slug($tab).'_'.Str::uuid().'.json';
        Storage::disk('local')->put(
            'procurement-sheet-backups/'.$name,
            json_encode(['captured_at' => now()->toIso8601String(), 'tabs' => [$tab => $values]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
        $this->sheets->replaceAll($tab, $upgraded, count($values));

        return $upgraded;
    }

    /** @param array<int,array<int,mixed>> $masterValues @param array<int,array<int,array<int,mixed>>> $brandSheets */
    private function backupBeforePublish(string $masterTab, array $masterValues, array $brandSheets): void
    {
        $tabs = [$masterTab => $masterValues];
        foreach ($this->collections->configured() as $collection) {
            $tabs[$collection->google_sheet_tab_name] = $brandSheets[$collection->id] ?? [];
        }
        $name = now()->format('Y-m-d_His_u').'_'.Str::uuid().'.json';
        Storage::disk('local')->put(
            'procurement-sheet-backups/'.$name,
            json_encode(['captured_at' => now()->toIso8601String(), 'tabs' => $tabs], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }
}
