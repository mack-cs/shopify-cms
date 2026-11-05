<?php

namespace App\Services\EngravedBeads;

use App\Jobs\ProcessShopifyStackFulfillmentJob;
use App\Jobs\ProcessShopifyStackOrderEventJob;
use App\Jobs\ReconcileEngravedBeadFulfillmentsJob;
use App\Jobs\SynchronizeEngravedBeadFamilyJob;
use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use App\Models\EngravedBeadOrderLine;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class EngravedBeadRecalculation
{
    public function inventoryItem(string $inventoryItem): void
    {
        if (! Schema::hasColumn('engraved_bead_families', 'recalculate_at')) {
            return;
        }
        $ids = EngravedBeadFamilyMember::query()
            ->whereIn('shopify_inventory_item_id', $this->inventoryItemCandidates($inventoryItem))
            ->pluck('family_id');
        if ($ids->isEmpty()) {
            return;
        }
        EngravedBeadFamily::query()->whereIn('id', $ids)->update(['recalculate_at' => now()]);
        // The database queue records the job without running it, so this does not re-enter the pool lock.
        // The every-minute reconciler remains the fallback when this dispatch is skipped or the job is stale.
        $this->dispatchFamilies($ids->all());
    }

    public function dispatchPending(): void
    {
        if (! Schema::hasColumn('engraved_bead_families', 'recalculate_at')) {
            return;
        }
        // Store new safety buffers locally. Shopify is not contacted while either write flag is false.
        app(EngravedBeadSafetyThresholds::class)->alignStored();
        if (! $this->writesAllowed()) {
            return;
        }
        $this->warnIfSellabilityIsStuck();
        try {
            app(EngravedBeadMissedOrderRecovery::class)->recover();
        } catch (\Throwable $error) {
            Log::warning('Engraved-bead missed-order recovery failed before the existing retries.', [
                'message' => $error->getMessage(),
            ]);
        }
        foreach (EngravedBeadFamily::query()->where('status', 'active')->where(function ($q): void {
            $q->whereNotNull('recalculate_at')->orWhereNull('last_reconciled_at')->orWhere('last_reconciled_at', '<', now()->subMinutes(5));
        })->cursor() as $family) {
            SynchronizeEngravedBeadFamilyJob::dispatch($family->id, $family->configuration_version)->afterCommit();
        }
        $activeOrders = EngravedBeadOrderLine::query()->where('configuration', EngravedBeadOrderLine::CONFIGURATION_BLOCKED)
            ->whereIn('variant_id', Variant::query()->whereIn('shopify_id',
                EngravedBeadFamilyMember::query()->whereHas('family', fn ($q) => $q->where('status', 'active'))->select('shopify_variant_id'))->select('id'))
            ->distinct()->pluck('shopify_order_id');
        foreach (ShopifyStackOrderEvent::query()->whereIn('shopify_order_id', $activeOrders)->latest('id')->get()->unique('shopify_order_id') as $event) {
            ProcessShopifyStackOrderEventJob::dispatch($event->id)->afterCommit();
        }
        $orders = ShopifyStackInventoryReservation::query()->whereNotNull('bead_family_id')->select('shopify_order_id');
        foreach (ShopifyStackOrderEvent::query()->whereIn('shopify_order_id', clone $orders)
            ->whereIn('status', ['failed', 'pending'])->orderBy('id')->limit(100)->get() as $event) {
            ProcessShopifyStackOrderEventJob::dispatch($event->id)->afterCommit();
        }
        foreach ((clone $orders)->withLeftoverReserved()->distinct()->pluck('shopify_order_id') as $orderId) {
            ReconcileEngravedBeadFulfillmentsJob::dispatch($orderId)->afterCommit();
        }
        foreach (ShopifyFulfillment::query()->whereIn('processing_status', ['pending', 'failed'])
            ->whereIn('shopify_order_id', $orders)->limit(100)->get() as $fulfillment) {
            ProcessShopifyStackFulfillmentJob::dispatch($fulfillment->id)->afterCommit();
        }
    }

    /** @param array<int, int|string> $familyIds */
    private function dispatchFamilies(array $familyIds): void
    {
        if (! $this->writesAllowed() || $familyIds === []) {
            return;
        }
        $families = EngravedBeadFamily::query()
            ->whereIn('id', $familyIds)
            ->where('status', 'active')
            ->whereNotNull('activated_at')
            ->get(['id', 'configuration_version']);
        foreach ($families as $family) {
            SynchronizeEngravedBeadFamilyJob::dispatch($family->id, (int) $family->configuration_version)->afterCommit();
        }
    }

    private function warnIfSellabilityIsStuck(): void
    {
        $stuck = EngravedBeadFamily::query()
            ->where('status', 'active')
            ->whereNotNull('recalculate_at')
            ->where('recalculate_at', '<', now()->subMinutes(10))
            ->count();
        if ($stuck > 0) {
            Log::warning('Engraved-bead families are still waiting for sellability. Check the inventory-ledger worker and failed_jobs.', [
                'families' => $stuck,
                'queue' => 'inventory-ledger',
            ]);
        }
    }

    private function writesAllowed(): bool
    {
        return (bool) config('engraved_beads.enabled', true)
            && (bool) config('engraved_beads.inventory_writes_enabled', false)
            && (bool) config('engraved_beads.store_contract_verified', false);
    }

    /** @return array<int, string> */
    private function inventoryItemCandidates(string $inventoryItemId): array
    {
        $id = trim($inventoryItemId);
        $candidates = [$id];
        if (preg_match('/^gid:\/\/shopify\/InventoryItem\/(\d+)$/', $id, $matches) === 1) {
            $candidates[] = $matches[1];
        } elseif (preg_match('/^\d+$/', $id) === 1) {
            $candidates[] = 'gid://shopify/InventoryItem/'.$id;
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
