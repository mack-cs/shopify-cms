<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadFamilyMember;
use Illuminate\Support\Facades\DB;

/** Safety buffers for multi-bead sellability. These never change how many beads a purchase consumes. */
final class EngravedBeadSafetyThresholds
{
    public static function normalize(mixed $value, int $fallback): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) $value;
        }

        return $fallback;
    }

    public function configured(int $requiredBeads): int
    {
        $fallback = in_array($requiredBeads, [2, 3, 4], true) ? $requiredBeads : max(0, $requiredBeads);

        return self::normalize(config('engraved_beads.thresholds.'.$requiredBeads), $fallback);
    }

    public function effective(EngravedBeadFamilyMember $member): int
    {
        if ((int) $member->required_beads === 1) {
            return max(0, (int) $member->disable_threshold);
        }

        return $this->configured((int) $member->required_beads);
    }

    /** Copy the configured buffers onto family members without calling Shopify. */
    public function alignStored(): void
    {
        EngravedBeadFamilyMember::query()->whereIn('required_beads', [2, 3, 4])->orderBy('id')->each(function (EngravedBeadFamilyMember $member): void {
            $configured = $this->configured((int) $member->required_beads);
            if ((int) $member->disable_threshold === $configured) {
                return;
            }
            DB::transaction(function () use ($member, $configured): void {
                $locked = EngravedBeadFamilyMember::query()->lockForUpdate()->find($member->id);
                $family = EngravedBeadFamily::query()->lockForUpdate()->find($member->family_id);
                if ($locked === null || $family === null || (int) $locked->disable_threshold === $configured) {
                    return;
                }
                $previous = (int) $locked->disable_threshold;
                $locked->update(['disable_threshold' => $configured]);
                $family->increment('configuration_version');
                $family->update(['recalculate_at' => now()]);
                $family->audits()->create([
                    'action' => 'threshold_configured',
                    'details' => [
                        'required_beads' => (int) $locked->required_beads,
                        'previous' => $previous,
                        'threshold' => $configured,
                        'configuration_version' => (int) $family->fresh()->configuration_version,
                        'shopify_mutation' => false,
                    ],
                ]);
            });
        });
    }
}
