<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EngravedBeadFamilyAdministration
{
    public function authorize(?User $actor): void
    {
        abort_unless($actor?->can('ShopifyPushProducts'), 403, 'Shopify product permission is required.');
    }

    public function activate(EngravedBeadFamily $family, User $actor): void
    {
        $this->authorize($actor);
        app(EngravedBeadFamilyDiscovery::class)->synchronize();
        DB::transaction(function () use ($family, $actor): void {
            $family = EngravedBeadFamily::query()->lockForUpdate()->findOrFail($family->id);
            if ($family->status !== 'pending_validation' || $family->validation_errors !== []
                || $family->validated_at === null || $family->members()->count() !== 4) {
                throw new \RuntimeException('Family membership, tracking, SKU and location validation must pass before activation.');
            }
            if (! config('engraved_beads.store_contract_verified', false)) {
                throw new \RuntimeException('Store location, sellability, checkout and API contract verification is required before activation.');
            }
            $family->forceFill(['status' => 'active', 'activated_at' => now(), 'approved_by' => $actor->id, 'recalculate_at' => now()])->save();
            $family->members()->update(['status' => 'active']);
            $family->audits()->create(['user_id' => $actor->id, 'action' => 'activation', 'details' => [
                'mapping_hash' => $family->mapping_hash, 'configuration_version' => $family->configuration_version,
            ]]);
        });
    }

    public function threshold(EngravedBeadFamilyMember $member, int $threshold, User $actor): void
    {
        $this->authorize($actor);
        if (in_array((int) $member->required_beads, [2, 3, 4], true)) {
            throw new \InvalidArgumentException('The '.$member->required_beads.'-bead safety buffer is ENGRAVED_BEAD_'.$member->required_beads.'_THRESHOLD. Change that environment value instead of a per-family number.');
        }
        if ($threshold < $member->required_beads || $threshold > 1000000) {
            throw new \InvalidArgumentException('Threshold must be at least the physical bead requirement and at most 1,000,000.');
        }
        DB::transaction(function () use ($member, $threshold, $actor): void {
            $family = EngravedBeadFamily::query()->lockForUpdate()->findOrFail($member->family_id);
            $member = EngravedBeadFamilyMember::query()->lockForUpdate()->findOrFail($member->id);
            $previous = $member->disable_threshold;
            $member->update(['disable_threshold' => $threshold]);
            $family->increment('configuration_version');
            $family->update(['recalculate_at' => now()]);
            $family->audits()->create(['user_id' => $actor->id, 'action' => 'threshold_changed', 'details' => [
                'member' => $member->shopify_variant_id, 'previous' => $previous, 'threshold' => $threshold,
                'configuration_version' => $family->configuration_version,
            ]]);
            // A durable request, retained while remote enforcement is blocked.
            $family->audits()->create(['action' => 'recalculation_required', 'details' => [
                'configuration_version' => $family->configuration_version,
            ]]);
        });
    }

    public function manualDisable(EngravedBeadFamilyMember $member, bool $disabled, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($member, $disabled, $actor): void {
            $family = EngravedBeadFamily::query()->lockForUpdate()->findOrFail($member->family_id);
            $member->update(['manual_disabled' => $disabled]);
            $family->increment('configuration_version');
            $family->update(['recalculate_at' => now()]);
            $family->audits()->create(['user_id' => $actor->id, 'action' => 'automatic_enable_permission',
                'details' => ['variant' => $member->shopify_variant_id, 'manual_disabled' => $disabled]]);
        });
    }
}
