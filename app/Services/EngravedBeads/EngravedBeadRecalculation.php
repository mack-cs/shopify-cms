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
use Illuminate\Support\Facades\Schema;

final class EngravedBeadRecalculation
{
    public function inventoryItem(string $inventoryItem): void
    {
        if (! Schema::hasColumn('engraved_bead_families', 'recalculate_at')) {
            return;
        }
        $ids = EngravedBeadFamilyMember::query()->where('shopify_inventory_item_id', $inventoryItem)->pluck('family_id');
        EngravedBeadFamily::query()->whereIn('id', $ids)->update(['recalculate_at' => now()]);
        // Dispatch is done by the minute reconciler, after inventory ledger commits.
        // This prevents a synchronous queue from reentering the physical pool lock.
    }

    public function dispatchPending(): void
    {
        if (! config('engraved_beads.inventory_writes_enabled', false) || ! config('engraved_beads.store_contract_verified', false)
            || ! Schema::hasColumn('engraved_bead_families', 'recalculate_at')) {
            return;
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
}
