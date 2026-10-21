<?php

namespace App\Services\Inventory;

final class InventoryAuditExplanation
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function apply(array $row): array
    {
        $flags = [];
        if ($this->cmsAttributed($row)) {
            $detail = (array) ($row['detail'] ?? []);
            $encoded = strtolower((string) json_encode($detail));
            if (($row['confidence'] ?? '') === 'unmatched') {
                $flags[] = str_contains($encoded, 'more than one cms record') ? 'ambiguous' : 'no_local_record';
            }
            if (! $this->knownActor((string) ($row['triggered_by'] ?? ''))) {
                $flags[] = 'unknown_actor';
            }
            if (! $this->knownWorkflow($row)) {
                $flags[] = 'unknown_workflow';
            }
            if (! $this->hasReason($detail)) {
                $flags[] = 'missing_reason';
            }
            if ($this->quantityDiffers($row)) {
                $flags[] = 'quantity_mismatch';
            }
        }

        $row['flags'] = $flags;
        $row['flag_labels'] = array_map(fn (string $flag): string => $this->label($flag), $flags);
        $row['unexplained'] = $flags !== [];

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function cmsAttributed(array $row): bool
    {
        if (strtolower(trim((string) ($row['app'] ?? ''))) === 'la-cms-access') {
            return true;
        }

        return in_array((string) ($row['source'] ?? ''), ['cms', 'automation'], true);
    }

    private function knownActor(string $triggeredBy): bool
    {
        $triggeredBy = strtolower(trim($triggeredBy));
        if ($triggeredBy === '' || $triggeredBy === 'la-cms-access') {
            return false;
        }

        return ! str_contains($triggeredBy, 'not recorded');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function knownWorkflow(array $row): bool
    {
        $process = strtolower(trim((string) ($row['process'] ?? '')));
        $triggeredBy = strtolower(trim((string) ($row['triggered_by'] ?? '')));
        if ($process !== '' && ! str_contains($process, 'not recorded')) {
            return true;
        }

        return str_contains($triggeredBy, 'job')
            || str_contains($triggeredBy, 'order processing')
            || str_contains($triggeredBy, 'applied it');
    }

    /**
     * @param array<int, mixed> $detail
     */
    private function hasReason(array $detail): bool
    {
        foreach ($detail as $line) {
            if (! is_array($line) || ! in_array($line[0] ?? '', ['Reason', 'Shopify reason'], true)) {
                continue;
            }
            if (trim((string) ($line[1] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quantityDiffers(array $row): bool
    {
        if (! in_array((string) ($row['confidence'] ?? ''), ['confirmed', 'probable'], true)) {
            return false;
        }
        $expectedState = strtolower(trim((string) ($row['expected_state'] ?? '')));
        if ($expectedState === '' || ! array_key_exists('expected_delta', $row)) {
            return false;
        }
        $shopifyStates = array_values(array_filter(
            (array) ($row['states'] ?? []),
            fn (mixed $state): bool => is_array($state) && ($state['origin'] ?? '') === 'shopify',
        ));
        if ($shopifyStates === []) {
            return false;
        }
        foreach ($shopifyStates as $state) {
            $shopifyState = strtolower((string) ($state['state'] ?? ''));
            $sameState = $shopifyState === $expectedState
                || (($row['activity_key'] ?? '') === 'correction'
                    && in_array($expectedState, ['on_hand', 'available'], true)
                    && in_array($shopifyState, ['on_hand', 'available'], true));
            if ($sameState && (int) ($state['delta'] ?? 0) === (int) $row['expected_delta']) {
                return false;
            }
        }

        return true;
    }

    private function label(string $flag): string
    {
        return match ($flag) {
            'no_local_record' => 'No matching record in the database this application is using. This is not a finding that the adjustment was unauthorized.',
            'unknown_actor' => 'The triggering user was not recorded.',
            'unknown_workflow' => 'The job or workflow was not identified.',
            'missing_reason' => 'No business reason was recorded.',
            'quantity_mismatch' => 'The Shopify quantity differs from the local adjustment for this state.',
            'ambiguous' => 'More than one local record could match this adjustment.',
            default => $flag,
        };
    }
}
