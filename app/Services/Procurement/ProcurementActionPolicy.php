<?php

namespace App\Services\Procurement;

use Carbon\CarbonInterface;

final class ProcurementActionPolicy
{
    public function resolve(
        ?string $currentAction,
        int $additionalOrderRequired,
        ?CarbonInterface $predictedRunoutDate,
        int $attentionHorizonDays,
        bool $ignore = false,
        ?CarbonInterface $asOf = null,
        ?int $availableInventory = null,
    ): string {
        if ($ignore || $additionalOrderRequired <= 0) {
            return 'NO_ACTION';
        }

        if ($availableInventory !== null && $availableInventory <= 0) {
            return 'ORDER_NOW';
        }

        if ($additionalOrderRequired > (int) config('procurement.order_now_threshold', 30)) {
            return 'ORDER_NOW';
        }

        if (in_array($currentAction, ['MANUAL_REVIEW', 'INSUFFICIENT_DATA'], true)) {
            return $currentAction;
        }

        $asOf ??= now((string) config('procurement.timezone', 'Africa/Johannesburg'));

        return $predictedRunoutDate !== null
            && $predictedRunoutDate->copy()->startOfDay()->lte($asOf->copy()->startOfDay()->addDays($attentionHorizonDays))
                ? 'ATTENTION_WITHIN_3_WEEKS'
                : 'MONITOR';
    }
}
