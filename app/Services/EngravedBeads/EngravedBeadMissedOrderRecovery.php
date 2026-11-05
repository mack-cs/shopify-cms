<?php

namespace App\Services\EngravedBeads;

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\ProcessShopifyStackOrderEventJob;
use App\Models\EngravedBeadFamilyMember;
use App\Models\ShopifyStackOrderEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a short recent-order window for active families and queues the existing stack order job.
 * It does not mutate Shopify. Orders older than six hours are outside this window.
 */
final class EngravedBeadMissedOrderRecovery
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function recover(): void
    {
        if (! config('engraved_beads.missed_order_recovery', true)
            || ! config('engraved_beads.enabled', true)
            || ! config('engraved_beads.inventory_writes_enabled', false)
            || ! config('engraved_beads.store_contract_verified', false)) {
            return;
        }

        $wanted = $this->variantIds();
        if ($wanted === []) {
            return;
        }

        $since = $this->windowStart();
        $cursor = null;
        $recorded = 0;
        try {
            for ($page = 0; $page < 2; $page++) {
                $data = $this->shopify->graphql(<<<'GQL'
query EngravedBeadRecentOrders($query: String!, $cursor: String) {
  orders(first: 50, after: $cursor, sortKey: UPDATED_AT, reverse: true, query: $query) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id name updatedAt cancelledAt
      lineItems(first: 50) { nodes { id quantity currentQuantity variant { id } } }
    }
  }
}
GQL, ['query' => 'updated_at:>=\''.$since->utc()->format('Y-m-d\TH:i:s\Z').'\'', 'cursor' => $cursor]);
                $connection = $data['orders'] ?? null;
                if (! is_array($connection)) {
                    throw new \RuntimeException('Shopify did not return the recent-order window.');
                }
                foreach ($connection['nodes'] ?? [] as $order) {
                    if (is_array($order) && $this->record($order, $wanted)) {
                        $recorded++;
                    }
                }
                $cursor = $connection['pageInfo']['endCursor'] ?? null;
                if (empty($connection['pageInfo']['hasNextPage']) || ! is_string($cursor) || $cursor === '') {
                    Cache::put($this->cacheKey(), now()->subMinutes(5)->toIso8601String(), now()->addDay());

                    return;
                }
            }
            Log::warning('Engraved-bead missed-order recovery stopped after 100 orders. Older orders in this window were not scanned.', [
                'since' => $since->toIso8601String(),
                'recorded' => $recorded,
            ]);
        } catch (Throwable $error) {
            Log::warning('Engraved-bead missed-order recovery could not read Shopify.', [
                'message' => $error->getMessage(),
            ]);
        }
    }

    /** @return array<string, true> */
    private function variantIds(): array
    {
        $wanted = [];
        $ids = EngravedBeadFamilyMember::query()
            ->whereHas('family', fn ($query) => $query->where('status', 'active')->whereNotNull('activated_at'))
            ->pluck('shopify_variant_id');
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $wanted[$id] = true;
            if (preg_match('/(\d+)$/', $id, $matches) === 1) {
                $wanted[$matches[1]] = true;
            }
        }

        return $wanted;
    }

    private function windowStart(): \Illuminate\Support\Carbon
    {
        $stored = Cache::get($this->cacheKey());
        $parsed = is_string($stored) ? \Illuminate\Support\Carbon::make($stored) : null;
        $floor = now()->subHours(6);
        if ($parsed === null) {
            return now()->subMinutes(30);
        }

        return $parsed->greaterThan($floor) ? $parsed : $floor;
    }

    /** @param array<string, mixed> $order @param array<string, true> $wanted */
    private function record(array $order, array $wanted): bool
    {
        $orderId = trim((string) ($order['id'] ?? ''));
        if ($orderId === '' || ShopifyStackOrderEvent::query()->where('shopify_order_id', $orderId)->exists()) {
            return false;
        }
        $lines = [];
        foreach (data_get($order, 'lineItems.nodes', []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $variantId = trim((string) data_get($line, 'variant.id'));
            $numeric = preg_match('/(\d+)$/', $variantId, $matches) === 1 ? $matches[1] : '';
            if ($variantId === '' || (! isset($wanted[$variantId]) && ! isset($wanted[$numeric]))) {
                continue;
            }
            $lines[] = [
                'admin_graphql_api_id' => $line['id'] ?? null,
                'id' => $line['id'] ?? null,
                'variant_id' => $variantId,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'current_quantity' => (int) ($line['currentQuantity'] ?? $line['quantity'] ?? 0),
            ];
        }
        if ($lines === []) {
            return false;
        }
        $cancelled = filled($order['cancelledAt'] ?? null);
        $event = ShopifyStackOrderEvent::query()->firstOrCreate(
            ['webhook_id' => 'catchup:'.substr(sha1($orderId), 0, 40)],
            [
                'topic' => $cancelled ? 'orders/cancelled' : 'orders/updated',
                'shopify_order_id' => $orderId,
                'shopify_order_name' => trim((string) ($order['name'] ?? '')) ?: null,
                'shopify_updated_at' => $order['updatedAt'] ?? now(),
                'payload' => ['id' => $orderId, 'admin_graphql_api_id' => $orderId, 'name' => $order['name'] ?? null, 'line_items' => $lines],
                'status' => ShopifyStackOrderEvent::STATUS_PENDING,
            ],
        );
        if ($event->wasRecentlyCreated) {
            ProcessShopifyStackOrderEventJob::dispatch($event->id)->afterCommit();
        }

        return $event->wasRecentlyCreated;
    }

    private function cacheKey(): string
    {
        return 'engraved-bead-order-catchup-since';
    }
}
