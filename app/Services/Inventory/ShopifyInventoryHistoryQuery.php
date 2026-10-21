<?php

namespace App\Services\Inventory;

use App\Contracts\ShopifyGraphqlGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ShopifyInventoryHistoryQuery
{
    public const ACCESS_CACHE_KEY = 'inventory_audit.shopifyql_access_denied';

    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    /**
     * @return array{status:string,message:?string,rows:array<int, array<string, mixed>>}
     */
    public function forSku(string $sku, Carbon $from, Carbon $until): array
    {
        $sku = trim($sku);
        if ($sku === '' || preg_match('/[^A-Za-z0-9._-]/', $sku) === 1) {
            return [
                'status' => 'skipped',
                'message' => 'Shopify history was not requested because the SKU contains characters that cannot be safely placed in a ShopifyQL filter.',
                'rows' => [],
            ];
        }

        if (Cache::get(self::ACCESS_CACHE_KEY) === true) {
            return [
                'status' => 'unavailable',
                'message' => 'Shopify inventory history is unavailable. shopifyqlQuery requires the read_reports access scope, and the last check showed this app does not have it.',
                'rows' => [],
            ];
        }

        $days = (int) min(180, max(1, (int) round($from->copy()->startOfDay()->diffInDays(Carbon::today()))));
        $shopifyql = <<<QL
FROM inventory_adjustment_history
SHOW inventory_adjustment_change
WHERE product_variant_sku = '{$sku}'
GROUP BY second, inventory_adjustment_id, inventory_adjustment_group_id, inventory_item_id, product_variant_id, product_variant_sku, product_variant_title, inventory_location_id, inventory_location_name, inventory_state, inventory_change_reason, inventory_app_name, staff_member_name, reference_document_uri, reference_document_type, reference_document_id
SINCE startOfDay(-{$days}d) UNTIL today
ORDER BY second DESC
LIMIT 200
QL;

        try {
            $payload = $this->shopify->graphql(<<<'GRAPHQL'
query InventoryAdjustmentHistory($query: String!) {
  shopifyqlQuery(query: $query) {
    parseErrors
    tableData { columns { name dataType } rows }
  }
}
GRAPHQL, ['query' => $shopifyql]);
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $parseErrors = array_values(array_filter((array) data_get($payload, 'shopifyqlQuery.parseErrors', [])));
        if ($parseErrors !== []) {
            return [
                'status' => 'failed',
                'message' => 'ShopifyQL rejected the inventory history query: '.implode(' ', $parseErrors),
                'rows' => [],
            ];
        }

        $tableRows = data_get($payload, 'shopifyqlQuery.tableData.rows', []);
        if (! is_array($tableRows)) {
            return [
                'status' => 'failed',
                'message' => 'Shopify returned an inventory history response without table rows.',
                'rows' => [],
            ];
        }

        $rows = [];
        foreach ($tableRows as $tableRow) {
            if (! is_array($tableRow)) {
                continue;
            }
            $occurredAt = $this->timestamp($tableRow['second'] ?? null);
            if ($occurredAt instanceof Carbon && ($occurredAt->lt($from->copy()->startOfDay()) || $occurredAt->gt($until->copy()->endOfDay()))) {
                continue;
            }
            $rows[] = $tableRow;
        }

        $message = count($tableRows) >= 200
            ? 'Shopify returned the 200-row query cap. Older adjustments for this SKU may be missing from this result.'
            : null;

        return ['status' => 'ok', 'message' => $message, 'rows' => $rows];
    }

    /**
     * One ShopifyQL series for a date window, optionally limited to a SKU or app.
     * Pages with LIMIT/OFFSET and stops with an explicit incomplete flag at the page cap.
     *
     * @return array{status:string,message:?string,rows:array<int, array<string, mixed>>,incomplete:bool,retrieved_at:?string}
     */
    public function collect(Carbon $from, Carbon $until, ?string $sku = null, ?string $app = null, int $pageSize = 250, int $maxPages = 20, ?string $reason = null): array
    {
        $sku = trim((string) $sku);
        $app = trim((string) $app);
        $reason = trim((string) $reason);
        if ($sku !== '' && preg_match('/[^A-Za-z0-9._-]/', $sku) === 1) {
            return $this->emptyCollect('skipped', 'Shopify history was not requested because the SKU contains characters that cannot be safely placed in a ShopifyQL filter.');
        }
        if ($app !== '' && preg_match('/[^A-Za-z0-9 ._-]/', $app) === 1) {
            return $this->emptyCollect('skipped', 'Shopify history was not requested because the app name contains characters that cannot be safely placed in a ShopifyQL filter.');
        }
        if ($reason !== '' && preg_match('/[^A-Za-z0-9_-]/', $reason) === 1) {
            return $this->emptyCollect('skipped', 'Shopify history was not requested because the reason contains characters that cannot be safely placed in a ShopifyQL filter.');
        }
        if (Cache::get(self::ACCESS_CACHE_KEY) === true) {
            return $this->emptyCollect('unavailable', 'Shopify inventory history is unavailable. shopifyqlQuery requires the read_reports access scope, and the last check showed this app does not have it.');
        }

        $pageSize = max(1, min(250, $pageSize));
        $maxPages = max(1, $maxPages);
        $cacheKey = 'inventory_audit.history.'.sha1(implode('|', [
            $from->toDateString(),
            $until->toDateString(),
            $sku,
            strtolower($app),
            strtolower($reason),
            $pageSize,
            $maxPages,
        ]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['status'] ?? '') === 'ok') {
            return $cached;
        }

        $where = [];
        if ($sku !== '') {
            $where[] = "product_variant_sku = '{$sku}'";
        }
        if ($app !== '') {
            $where[] = "inventory_app_name = '{$app}'";
        }
        if ($reason !== '') {
            $where[] = "inventory_change_reason = '{$reason}'";
        }
        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where)."\n";
        $since = $from->toDateString();
        $untilDate = $until->copy()->addDay()->toDateString();
        $dimensions = 'second, inventory_adjustment_id, inventory_adjustment_group_id, inventory_item_id, product_variant_id, product_variant_sku, product_variant_title, inventory_location_id, inventory_location_name, inventory_state, inventory_change_reason, inventory_app_name, staff_member_name, reference_document_uri, reference_document_type, reference_document_id';

        $rows = [];
        $seen = [];
        $incomplete = false;
        $message = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $pageSize;
            $shopifyql = <<<QL
FROM inventory_adjustment_history
SHOW inventory_adjustment_change
{$whereSql}GROUP BY {$dimensions}
SINCE {$since} UNTIL {$untilDate}
ORDER BY second DESC
LIMIT {$pageSize} OFFSET {$offset}
QL;
            $pageResult = $this->run($shopifyql);
            if ($pageResult['status'] !== 'ok' && $page === 0 && $this->offsetRejected($pageResult['message'])) {
                $shopifyql = <<<QL
FROM inventory_adjustment_history
SHOW inventory_adjustment_change
{$whereSql}GROUP BY {$dimensions}
SINCE {$since} UNTIL {$untilDate}
ORDER BY second DESC
LIMIT {$pageSize}
QL;
                $pageResult = $this->run($shopifyql);
                if ($pageResult['status'] === 'ok' && count($pageResult['rows']) >= $pageSize) {
                    $incomplete = true;
                    $message = 'ShopifyQL did not accept OFFSET, so this result stops at the first '.$pageSize.' rows and is incomplete.';
                }
            }
            if ($pageResult['status'] !== 'ok') {
                if ($rows === []) {
                    return $pageResult + ['incomplete' => false, 'retrieved_at' => null];
                }
                $incomplete = true;
                $message = 'Shopify stopped returning history after '.count($rows).' rows. '.$pageResult['message'];
                break;
            }

            foreach ($pageResult['rows'] as $tableRow) {
                $occurredAt = $this->timestamp($tableRow['second'] ?? null);
                if ($occurredAt instanceof Carbon && ($occurredAt->lt($from->copy()->startOfDay()) || $occurredAt->gt($until->copy()->endOfDay()))) {
                    continue;
                }
                $identity = trim((string) ($tableRow['inventory_adjustment_id'] ?? ''));
                if ($identity === '') {
                    $identity = md5((string) json_encode($tableRow));
                }
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $rows[] = $tableRow;
            }

            if (count($pageResult['rows']) < $pageSize) {
                break;
            }
            if ($page === $maxPages - 1) {
                $incomplete = true;
                $message = 'This result stopped after '.count($rows).' Shopify rows because the query reached its page limit. Narrow the date range. Rows beyond this page are missing, and this result is incomplete.';
            }
        }

        $retrievedAt = now()->toIso8601String();
        $note = 'Shopify history retrieved at '.$retrievedAt.'.';
        $result = [
            'status' => 'ok',
            'message' => trim(($message ? $message.' ' : '').$note),
            'rows' => $rows,
            'incomplete' => $incomplete,
            'retrieved_at' => $retrievedAt,
        ];
        Cache::put($cacheKey, $result, now()->addMinutes(10));

        return $result;
    }

    public function forgetAccessDenial(): void
    {
        Cache::forget(self::ACCESS_CACHE_KEY);
    }

    /**
     * @return array{status:string,message:?string,rows:array<int, array<string, mixed>>,incomplete:bool,retrieved_at:?string}
     */
    private function emptyCollect(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'rows' => [],
            'incomplete' => false,
            'retrieved_at' => null,
        ];
    }

    /**
     * @return array{status:string,message:?string,rows:array<int, array<string, mixed>>}
     */
    private function run(string $shopifyql): array
    {
        try {
            $payload = $this->shopify->graphql(<<<'GRAPHQL'
query InventoryAdjustmentHistory($query: String!) {
  shopifyqlQuery(query: $query) {
    parseErrors
    tableData { columns { name dataType } rows }
  }
}
GRAPHQL, ['query' => $shopifyql]);
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $parseErrors = array_values(array_filter((array) data_get($payload, 'shopifyqlQuery.parseErrors', [])));
        if ($parseErrors !== []) {
            return [
                'status' => 'failed',
                'message' => 'ShopifyQL rejected the inventory history query: '.implode(' ', $parseErrors),
                'rows' => [],
            ];
        }

        $tableRows = data_get($payload, 'shopifyqlQuery.tableData.rows', []);
        if (! is_array($tableRows)) {
            return [
                'status' => 'failed',
                'message' => 'Shopify returned an inventory history response without table rows.',
                'rows' => [],
            ];
        }

        return [
            'status' => 'ok',
            'message' => null,
            'rows' => array_values(array_filter($tableRows, 'is_array')),
        ];
    }

    private function offsetRejected(?string $message): bool
    {
        return str_contains(strtolower((string) $message), 'offset');
    }

    /**
     * @return array{status:string,message:string,rows:array<int, array<string, mixed>>}
     */
    private function failure(string $message): array
    {
        if (str_contains($message, 'read_reports') || str_contains($message, 'shopifyqlQuery')) {
            Cache::put(self::ACCESS_CACHE_KEY, true, now()->addMinutes(15));

            return [
                'status' => 'unavailable',
                'message' => 'Shopify inventory history is unavailable. The Admin API rejected shopifyqlQuery because this app does not have the read_reports scope. CMS records are still shown.',
                'rows' => [],
            ];
        }

        return [
            'status' => 'failed',
            'message' => 'Shopify inventory history could not be loaded. '.$message,
            'rows' => [],
        ];
    }

    private function timestamp(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) <= 10) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
