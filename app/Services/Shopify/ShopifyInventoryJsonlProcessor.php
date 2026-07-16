<?php

namespace App\Services\Shopify;

use App\Models\ShopifySyncIssue;
use App\Models\ShopifySyncRun;

final class ShopifyInventoryJsonlProcessor
{
    public function __construct(
        private readonly ShopifyInventoryUpsertService $upserts,
    ) {
    }

    /**
     * @return array{source_lines:int,inventory_items:int,inventory_levels:int,unclassified:int,invalid_json:int,current_updated:int,unmatched:int,duplicates:int}
     */
    public function process(string $gzPath, ShopifySyncRun $run): array
    {
        $handle = gzopen($gzPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open Shopify inventory archive {$gzPath}.");
        }

        $counts = [
            'source_lines' => 0,
            'inventory_items' => 0,
            'inventory_levels' => 0,
            'unclassified' => 0,
            'invalid_json' => 0,
            'current_updated' => 0,
            'unmatched' => 0,
            'duplicates' => 0,
        ];
        $parents = [];

        try {
            while (!gzeof($handle)) {
                $line = gzgets($handle);
                if ($line === false) {
                    break;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $counts['source_lines']++;
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    $counts['invalid_json']++;
                    $this->issue($run, ShopifySyncIssue::TYPE_INVALID_JSON, 'Invalid inventory JSONL row encountered.', ['line' => $counts['source_lines']]);
                    continue;
                }

                $this->processRecord($record, $run, $parents, $counts);
            }
        } finally {
            gzclose($handle);
        }

        $current = $this->upserts->updateCurrentStateForRun($run);
        $counts['current_updated'] = $current['updated'];
        $counts['unmatched'] = $current['unmatched'];
        $counts['duplicates'] = $current['duplicates'];

        $run->forceFill([
            'records_processed' => $counts['source_lines'],
            'inventory_items_processed' => $counts['inventory_items'],
            'inventory_levels_processed' => $counts['inventory_levels'],
            'metadata' => array_merge($run->metadata ?? [], [
                'source_lines' => $counts['source_lines'],
                'unclassified' => $counts['unclassified'],
                'invalid_json' => $counts['invalid_json'],
                'current_inventory_updated' => $counts['current_updated'],
                'unmatched_inventory' => $counts['unmatched'],
                'duplicate_inventory_skus' => $counts['duplicates'],
            ]),
        ])->save();

        if ($counts['invalid_json'] > 0) {
            throw new \RuntimeException("Shopify inventory JSONL contained {$counts['invalid_json']} invalid JSON row(s).");
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, array<string, mixed>> $parents
     * @param array<string, int> $counts
     */
    private function processRecord(array $record, ShopifySyncRun $run, array &$parents, array &$counts): void
    {
        $id = trim((string) ($record['id'] ?? ''));

        if (str_starts_with($id, 'gid://shopify/InventoryItem/')) {
            $parents[$id] = $record;
            if (count($parents) > 1000) {
                array_shift($parents);
            }

            $counts['inventory_items']++;
            $this->processNestedLevels($record, $run, $counts);

            return;
        }

        if (str_starts_with($id, 'gid://shopify/InventoryLevel/') || array_key_exists('quantities', $record)) {
            $parentId = trim((string) ($record['__parentId'] ?? ''));
            $parent = $parentId !== '' ? ($parents[$parentId] ?? ['id' => $parentId]) : [];
            $this->upserts->upsertSnapshot($this->snapshotPayload($parent, $record), $run);
            $counts['inventory_levels']++;

            return;
        }

        $counts['unclassified']++;
        $this->issue($run, ShopifySyncIssue::TYPE_UNCLASSIFIED_RECORD, 'Unclassified Shopify inventory JSONL record.', [
            'id' => $id,
            'keys' => array_keys($record),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, int> $counts
     */
    private function processNestedLevels(array $item, ShopifySyncRun $run, array &$counts): void
    {
        foreach (data_get($item, 'inventoryLevels.edges', []) as $edge) {
            $node = data_get($edge, 'node');
            if (!is_array($node)) {
                continue;
            }

            $this->upserts->upsertSnapshot($this->snapshotPayload($item, $node), $run);
            $counts['inventory_levels']++;
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $level
     * @return array<string, mixed>
     */
    private function snapshotPayload(array $item, array $level): array
    {
        $variant = data_get($item, 'variant', []);
        $product = data_get($item, 'variant.product', []);
        $quantities = $this->quantities((array) ($level['quantities'] ?? []));

        return [
            'shopify_inventory_item_id' => trim((string) ($item['id'] ?? $level['__parentId'] ?? '')),
            'shopify_inventory_level_id' => $this->nullIfBlank($level['id'] ?? null),
            'shopify_product_id' => $this->nullIfBlank(data_get($product, 'id')),
            'shopify_variant_id' => $this->nullIfBlank(data_get($variant, 'id')),
            'shopify_location_id' => $this->nullIfBlank(data_get($level, 'location.id')),
            'location_name' => $this->nullIfBlank(data_get($level, 'location.name')),
            'location_active' => array_key_exists('isActive', (array) data_get($level, 'location', []))
                ? (bool) data_get($level, 'location.isActive')
                : null,
            'sku' => $this->normalizeSku(data_get($variant, 'sku', $item['sku'] ?? null)),
            'barcode' => $this->nullIfBlank(data_get($variant, 'barcode')),
            'product_title' => $this->nullIfBlank(data_get($product, 'title')),
            'product_handle' => $this->nullIfBlank(data_get($product, 'handle')),
            'product_type' => $this->nullIfBlank(data_get($product, 'productType')),
            'vendor' => $this->nullIfBlank(data_get($product, 'vendor')),
            'product_status' => $this->nullIfBlank(data_get($product, 'status')),
            'variant_title' => $this->nullIfBlank(data_get($variant, 'title', data_get($variant, 'displayName'))),
            'tracked' => array_key_exists('tracked', $item) ? (bool) $item['tracked'] : null,
            'requires_shipping' => array_key_exists('requiresShipping', $item) ? (bool) $item['requiresShipping'] : null,
            'variant_price' => $this->decimalOrNull(data_get($variant, 'price')),
            'available' => $quantities['available'] ?? $this->intOrNull(data_get($variant, 'inventoryQuantity')),
            'on_hand' => $quantities['on_hand'] ?? null,
            'committed' => $quantities['committed'] ?? null,
            'incoming' => $quantities['incoming'] ?? null,
            'reserved' => $quantities['reserved'] ?? null,
            'damaged' => $quantities['damaged'] ?? null,
            'quality_control' => $quantities['quality_control'] ?? null,
            'safety_stock' => $quantities['safety_stock'] ?? null,
        ];
    }

    /**
     * @param array<int, array{name?:string,quantity?:mixed}> $rows
     * @return array<string, int>
     */
    private function quantities(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $value = $this->intOrNull($row['quantity'] ?? null);
            if ($value !== null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private function normalizeSku(mixed $sku): ?string
    {
        $sku = strtoupper(trim((string) ($sku ?? '')));

        return $sku === '' ? null : $sku;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric((string) $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric((string) $value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function issue(ShopifySyncRun $run, string $type, string $message, array $payload): void
    {
        ShopifySyncIssue::query()->updateOrCreate([
            'sync_run_id' => $run->id,
            'dataset' => ShopifySyncRun::DATASET_INVENTORY,
            'issue_type' => $type,
            'shopify_id' => $payload['id'] ?? null,
        ], [
            'message' => $message,
            'payload' => $payload,
        ]);
    }
}
