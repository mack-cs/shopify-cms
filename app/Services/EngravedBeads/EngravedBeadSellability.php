<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use App\Models\EngravedBeadSellabilityOperation;
use App\Models\Product;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\Variant;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EngravedBeadSellability
{
    public function __construct(
        private readonly EngravedBeadSellabilityShopify $shopify,
        private readonly ShopifyInventoryAdjustmentService $inventory,
        private readonly EngravedBeadWriteGuard $guard,
    ) {}

    public function synchronize(int $familyId, ?int $expectedVersion = null): void
    {
        // Crucially, disabled means no network request, including read requests.
        $this->guard->check();
        $family = EngravedBeadFamily::query()->findOrFail($familyId);
        $pool = hash('sha256', $family->source_inventory_item_id.'|'.$family->source_location_id);
        Cache::lock('inventory-pool:'.$pool, 420)->block(5, function () use ($familyId, $expectedVersion): void {
            $family = EngravedBeadFamily::query()->with('members')->findOrFail($familyId);
            if ($family->status !== 'active' || $family->activated_at === null || $family->members->count() !== 4) {
                throw new \RuntimeException('Only an explicitly approved, valid family can synchronize.');
            }
            if ($expectedVersion !== null && $expectedVersion !== (int) $family->configuration_version) {
                return; // A replacement reconciliation uses the latest configuration.
            }
            $startedAt = now();
            try {
                if (ShopifyStackInventoryMovement::query()->whereHas('reservation', fn ($q) => $q->where('bead_family_id', $family->id))
                    ->where('status', '!=', ShopifyStackInventoryMovement::STATUS_COMPLETED)->exists()) {
                    throw new \RuntimeException('Unconfirmed physical movement blocks sellability until reconciliation.');
                }
                foreach (EngravedBeadSellabilityOperation::query()->where('family_id', $familyId)->where('status', '!=', 'confirmed')->orderBy('id')->get() as $op) {
                    $this->apply($family, $family->members->firstWhere('id', $op->member_id), $op);
                    $family->refresh()->load('members');
                }
                $source = $family->members->firstWhere('required_beads', 1);
                $sourceState = $this->shopify->read($source, $family->source_location_id);
                if (! $sourceState['tracked']) {
                    throw new \RuntimeException('The physical source is no longer tracked.');
                }
                $available = (new EngravedBeadAvailability)->calculate($sourceState['available'], 0, (int) $family->automation_held_quantity);
                foreach ($family->members as $member) {
                    $state = $member->required_beads === 1 ? $sourceState : $this->shopify->read($member, $family->source_location_id);
                    $member->update(['last_verified_tracked' => $state['tracked'], 'last_verified_sellable' => $state['sellable']]);
                    $disable = (new EngravedBeadAvailability)->shouldDisable($available, app(EngravedBeadSafetyThresholds::class)->effective($member));
                    $liveStatus = in_array($state['status'], ['ACTIVE', 'UNLISTED'], true);
                    $product = Product::query()->where('shopify_id', $member->shopify_product_id)->first();
                    if (! $liveStatus || $member->manual_disabled || ! $product?->isApprovedByTwo()) {
                        continue;
                    }
                    if (! $disable && ! $member->automation_disabled) {
                        continue; // Never undo a manual tracking/stock/publication decision.
                    }
                    if ($disable && $member->automation_disabled && ! $state['sellable']
                        && ($member->required_beads > 1 || $state['available'] <= 0)) {
                        continue;
                    }
                    if ($disable && ! $member->automation_disabled && ! $state['sellable']) {
                        continue; // Do not take ownership of an existing manual disable.
                    }
                    $operation = EngravedBeadSellabilityOperation::query()->create([
                        'family_id' => $family->id, 'member_id' => $member->id,
                        'configuration_version' => $family->configuration_version,
                        'mapping_hash' => $family->mapping_hash, 'idempotency_key' => (string) Str::uuid(),
                        'disable' => $disable, 'intent' => [
                            'source' => $member->required_beads === 1,
                            'before' => $state, 'available_beads' => $available,
                            'quantity' => $member->required_beads === 1 ? ($disable ? max(0, $state['available']) : (int) $family->automation_held_quantity) : 0,
                            'held_before' => (int) $family->automation_held_quantity,
                            'location' => $family->source_location_id,
                        ], 'confirmed_steps' => [],
                    ]);
                    $this->apply($family, $member, $operation);
                    $family->refresh();
                }
                $family->refresh();
                $family->last_reconciled_at = now();
                $family->sync_error = null;
                if ($family->recalculate_at === null || $family->recalculate_at->lte($startedAt)) {
                    $family->recalculate_at = null;
                }
                $family->save();
            } catch (\Throwable $error) {
                $family->update(['sync_error' => $error->getMessage(), 'recalculate_at' => now()]);
                throw $error;
            }
        });
    }

    private function apply(EngravedBeadFamily $family, EngravedBeadFamilyMember $member, EngravedBeadSellabilityOperation $op): void
    {
        $this->guard->check();
        $family->refresh();
        if ($family->status !== 'active' || $op->mapping_hash !== $family->mapping_hash
            || (int) $op->configuration_version !== (int) $family->configuration_version) {
            throw new \RuntimeException('Stale or remapped operation requires explicit reconciliation; no mutation was attempted.');
        }
        $intent = $op->intent;
        if ($op->status === 'uncertain' && $op->created_at->lt(now()->subHours(23))) {
            throw new \RuntimeException('Uncertain operation exceeds the safe idempotency retry window; manual remote reconciliation required.');
        }
        $source = $intent['source'];
        $location = $intent['location'];
        try {
            $state = $this->shopify->read($member, $location);
            $product = Product::query()->where('shopify_id', $member->shopify_product_id)->first();
            if (! in_array($state['status'], ['ACTIVE', 'UNLISTED'], true) || $member->fresh()->manual_disabled
                || ! $product?->isApprovedByTwo()) {
                throw new \RuntimeException('Product eligibility changed during the operation; reconcile before retrying.');
            }
            if (! $source && ($state['on_hand'] !== 0 || $state['committed'] !== 0 || $state['reserved'] !== 0 || $state['safety_stock'] !== 0)) {
                throw new \RuntimeException('Member stock is not zero; reconcile independent inventory before changing tracking.');
            }
            if ($source) {
                if (! $state['tracked']) {
                    throw new \RuntimeException('Source tracking must remain enabled.');
                }
                $this->step($op, 'deny', fn () => $this->shopify->deny($member));
                if ($intent['quantity'] > 0) {
                    $variant = Variant::query()->where('shopify_id', $member->shopify_variant_id)->firstOrFail();
                    $this->step($op, 'move', function () use ($variant, $intent, $op, $family, $location): void {
                        $this->inventory->moveQuantity($variant, $intent['quantity'],
                            $op->disable ? 'available' : 'safety_stock', $op->disable ? 'safety_stock' : 'available',
                            'correction', 'leighavenue-cms://bead-sellability/'.$op->id,
                            'leighavenue-cms://bead-source-safety/'.$family->id, $op->idempotency_key, $location);
                    });
                }
            } elseif ($op->disable) {
                if ($state['committed'] !== 0 || $state['reserved'] !== 0 || $state['safety_stock'] !== 0) {
                    throw new \RuntimeException('Member has independent commitments or holds; zeroing is blocked.');
                }
                $this->step($op, 'deny', fn () => $this->shopify->deny($member));
                $this->step($op, 'tracking', fn () => $this->shopify->tracking($member, true));
                $this->step($op, 'zero', fn () => $this->shopify->zero($member, $location, $intent['before']['available'], $op->idempotency_key));
            } else {
                if (! $member->automation_disabled) {
                    throw new \RuntimeException('Only an automation-owned disable can be reversed.');
                }
                $this->step($op, 'tracking', fn () => $this->shopify->tracking($member, false));
            }
            $confirmed = $this->shopify->read($member, $location);
            if ($confirmed['sellable'] === $op->disable
                || $confirmed['tracked'] !== ($source || $op->disable)
                || ($op->disable && ($confirmed['available'] > 0 || $confirmed['policy'] !== 'DENY'))
                || ($source && $confirmed['on_hand'] !== $intent['before']['on_hand'])) {
                throw new \RuntimeException('Shopify sellability or source on-hand preservation has not been verified.');
            }
            DB::transaction(function () use ($family, $member, $op, $confirmed, $intent, $source): void {
                $locked = EngravedBeadSellabilityOperation::query()->lockForUpdate()->findOrFail($op->id);
                if ($locked->status === 'confirmed') {
                    return;
                }
                if ($source) {
                    $family->update(['automation_held_quantity' => $op->disable
                        ? $intent['held_before'] + $intent['quantity'] : 0]);
                }
                $member->update(['automation_disabled' => $op->disable,
                    'last_verified_tracked' => $confirmed['tracked'], 'last_verified_sellable' => $confirmed['sellable']]);
                $locked->update(['status' => 'confirmed', 'confirmed_at' => now(), 'error_message' => null]);
                $family->audits()->create(['action' => $op->disable ? 'threshold_disabled' : 'threshold_enabled',
                    'details' => ['operation_id' => $op->id, 'variant' => $member->shopify_variant_id,
                        'before' => $intent['before'], 'confirmed' => $confirmed, 'available_beads' => $intent['available_beads']]]);
            });
        } catch (\Throwable $error) {
            $op->update(['status' => 'uncertain', 'error_message' => $error->getMessage()]);
            throw $error;
        }
    }

    private function step(EngravedBeadSellabilityOperation $op, string $name, \Closure $callback): void
    {
        if (in_array($name, $op->confirmed_steps ?? [], true)) {
            return;
        }
        $this->guard->check();
        $latest = EngravedBeadFamily::query()->findOrFail($op->family_id);
        if ($latest->status !== 'active' || $latest->mapping_hash !== $op->mapping_hash
            || (int) $latest->configuration_version !== (int) $op->configuration_version) {
            throw new \RuntimeException('Family configuration changed before a remote step; reconciliation required.');
        }
        // Intent exists before every remote mutation; absolute setters are safely repeatable.
        $callback();
        $op->update(['confirmed_steps' => [...($op->confirmed_steps ?? []), $name]]);
    }
}
