<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamily;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** Python receives ordinary source identities and confirmed consumption, never family sales weights. */
final class EngravedBeadProcurementContract
{
    public const VERSION = 'shared-beads-v1';

    public const FORECAST_MODE = 'ordinary_tracked_source';

    public function export(): array
    {
        if (! Schema::hasTable('engraved_bead_families') || ! Schema::hasTable('engraved_bead_family_members')) {
            return ['version' => self::VERSION, 'forecast_mode' => self::FORECAST_MODE, 'families' => []];
        }
        $observed = app(EngravedBeadFamilyDiscovery::class)->inspect();
        $sources = [];
        foreach (EngravedBeadFamily::query()->where('status', 'active')->whereNotNull('activated_at')
            ->whereNotNull('approved_by')->with('members')->get() as $family) {
            $group = $observed[$family->family_tag] ?? null;
            $sourceMember = $family->members->firstWhere('required_beads', 1);
            $variant = Variant::query()->with('product')->where('shopify_id', $family->source_variant_id)->first();
            if ($group === null || $group['errors'] !== [] || $family->validation_errors !== []
                || $family->mapping_hash !== $group['mapping_hash'] || $family->members->count() !== 4
                || $sourceMember === null || $sourceMember->status !== 'active' || $variant === null
                || $family->source_location_id !== $group['source_location_id']
                || $sourceMember->shopify_variant_id !== $family->source_variant_id
                || $variant->inventory_tracked !== true || trim((string) $variant->sku) !== $sourceMember->sku
                || $variant->shopify_inventory_item_id !== $family->source_inventory_item_id
                || $variant->product?->shopify_id !== $family->source_product_id
                || ! in_array(strtolower((string) $variant->product?->status), ['active', 'unlisted'], true)) {
                continue;
            }
            $persisted = $family->members->map(fn ($m) => [$m->required_beads, $m->shopify_product_id,
                $m->shopify_variant_id, $m->shopify_inventory_item_id, $m->sku])->sortBy(fn ($m) => $m[0])->values()->all();
            $current = array_map(fn ($m) => [$m['required_beads'], $m['shopify_product_id'], $m['shopify_variant_id'],
                $m['shopify_inventory_item_id'], $m['sku']], $group['members']);
            if ($persisted !== $current) {
                continue;
            }
            $sources[] = [
                'family_id' => $family->id, 'family_tag' => $family->family_tag,
                'status' => 'active', 'approved' => true,
                'configuration_version' => $family->configuration_version, 'mapping_hash' => $family->mapping_hash,
                'activated_at' => $family->activated_at->toIso8601String(),
                'source_product_id' => $family->source_product_id, 'source_variant_id' => $family->source_variant_id,
                'source_inventory_item_id' => $family->source_inventory_item_id,
                'source_location_id' => $family->source_location_id, 'source_sku' => $sourceMember->sku,
            ];
        }

        return ['version' => self::VERSION, 'forecast_mode' => self::FORECAST_MODE, 'families' => $sources];
    }

    /** Only confirmed physical consumption, attributed to its immutable source snapshot. */
    public function consumption(string $from, string $to): iterable
    {
        $sources = collect($this->export()['families'])->keyBy('family_id');
        if ($sources->isEmpty()) {
            return;
        }
        // Movement timestamps are stored in Laravel's application timezone, not necessarily UTC.
        $databaseTimezone = (string) config('app.timezone', 'UTC');
        $start = Carbon::parse($from, 'Africa/Johannesburg')->startOfDay()->setTimezone($databaseTimezone);
        $end = Carbon::parse($to, 'Africa/Johannesburg')->addDay()->startOfDay()->setTimezone($databaseTimezone);
        foreach (ShopifyStackInventoryMovement::query()->where('action', ShopifyStackInventoryMovement::ACTION_CONSUME)
            ->where('status', ShopifyStackInventoryMovement::STATUS_COMPLETED)
            ->where('processed_at', '>=', $start)->where('processed_at', '<', $end)
            ->whereHas('reservation', fn ($q) => $q->whereIn('bead_family_id', $sources->keys()))
            ->with('reservation')->orderBy('id')->cursor() as $movement) {
            $reservation = $movement->reservation;
            $source = $sources->get($reservation->bead_family_id);
            if ($source === null || $movement->quantity <= 0
                || $reservation->shopify_component_variant_id !== $source['source_variant_id']
                || $reservation->shopify_inventory_item_id !== $source['source_inventory_item_id']
                || $reservation->shopify_location_id !== $source['source_location_id']
                || $reservation->shopify_order_created_at === null
                || $reservation->shopify_order_created_at->lt(Carbon::parse($source['activated_at']))
                || $movement->processed_at->lt(Carbon::parse($source['activated_at']))
                || $reservation->shopify_stack_variant_id === $source['source_variant_id']) {
                continue;
            }
            yield [
                'event_id' => 'cms-bead-consume:'.$movement->event_key, 'action' => 'consume', 'status' => 'completed',
                'occurred_at' => $movement->processed_at->toIso8601String(),
                'order_name' => $reservation->shopify_order_name ?: $reservation->shopify_order_id,
                'source_variant_id' => $source['source_variant_id'],
                'source_inventory_item_id' => $source['source_inventory_item_id'], 'source_location_id' => $source['source_location_id'],
                'sku' => $source['source_sku'], 'quantity' => (int) $movement->quantity, 'units' => 'physical_beads',
            ];
        }
    }
}
