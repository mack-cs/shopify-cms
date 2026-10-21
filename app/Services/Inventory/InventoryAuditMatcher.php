<?php

namespace App\Services\Inventory;

use Illuminate\Support\Carbon;

final class InventoryAuditMatcher
{
    /**
     * @param array<int, array<string, mixed>> $local
     * @param array<int, array<string, mixed>> $shopify
     * @return array<int, array<string, mixed>>
     */
    public function merge(array $local, array $shopify): array
    {
        $byReference = [];
        foreach ($local as $index => $row) {
            foreach ((array) ($row['references'] ?? []) as $reference) {
                $normalized = $this->reference((string) $reference);
                if ($normalized !== '') {
                    $byReference[$normalized][] = $index;
                }
            }
        }

        $probableUsed = [];
        $unmatched = [];
        foreach ($shopify as $remote) {
            $reference = $this->reference((string) ($remote['reference'] ?? ''));
            $indexes = $reference !== '' ? array_values(array_unique($byReference[$reference] ?? [])) : [];
            if (count($indexes) === 1) {
                $local[$indexes[0]] = $this->attach($local[$indexes[0]], $remote, 'confirmed');

                continue;
            }
            if (count($indexes) > 1) {
                $unmatched[] = $this->remoteRow($remote, 'The Shopify reference appears on more than one CMS record, so it was left unmatched.');

                continue;
            }

            $candidate = $this->probableIndex($local, $remote, $probableUsed);
            if ($candidate !== null) {
                $probableUsed[$candidate] = true;
                $local[$candidate] = $this->attach($local[$candidate], $remote, 'probable');

                continue;
            }

            $unmatched[] = $this->remoteRow($remote, null);
        }

        $rows = [];
        foreach ($local as $row) {
            $rows[] = $row['row'];
        }

        return array_merge($rows, $unmatched);
    }

    /**
     * @param array<int, array<string, mixed>> $local
     * @param array<string, mixed> $remote
     * @param array<int, bool> $used
     */
    private function probableIndex(array $local, array $remote, array $used): ?int
    {
        $remoteAt = $remote['occurred_at'] ?? null;
        if (! $remoteAt instanceof Carbon) {
            return null;
        }

        $matches = [];
        foreach ($local as $index => $row) {
            if (isset($used[$index]) || ($row['row']['confidence'] ?? '') === 'confirmed') {
                continue;
            }
            $localAt = $row['occurred_at'] ?? null;
            if (! $localAt instanceof Carbon || abs($localAt->diffInSeconds($remoteAt)) > 120) {
                continue;
            }
            if (! $this->sameId((string) ($row['inventory_item_id'] ?? ''), (string) ($remote['inventory_item_id'] ?? ''))) {
                continue;
            }
            if ((int) ($row['delta'] ?? 0) !== (int) ($remote['delta'] ?? 0)) {
                continue;
            }
            if (! $this->statesAlign($row, $remote)) {
                continue;
            }
            $localLocation = trim((string) ($row['location_id'] ?? ''));
            $remoteLocation = trim((string) ($remote['location_id'] ?? ''));
            if ($localLocation !== '' && $remoteLocation !== '' && ! $this->sameId($localLocation, $remoteLocation)) {
                continue;
            }
            $matches[] = $index;
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * On-hand corrections can appear in Shopify as an available change of the same quantity.
     * That remains a probable match, and only when one CMS record fits.
     *
     * @param array<string, mixed> $local
     * @param array<string, mixed> $remote
     */
    private function statesAlign(array $local, array $remote): bool
    {
        $localState = strtolower((string) ($local['state'] ?? ''));
        $remoteState = strtolower((string) ($remote['state'] ?? ''));
        if ($localState === $remoteState) {
            return true;
        }
        $correction = ($local['row']['activity_key'] ?? '') === 'correction'
            && strtolower((string) ($remote['reason'] ?? '')) === 'correction';

        return $correction
            && in_array($localState, ['on_hand', 'available'], true)
            && in_array($remoteState, ['on_hand', 'available'], true);
    }

    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $remote
     * @return array<string, mixed>
     */
    private function attach(array $local, array $remote, string $confidence): array
    {
        $row = $local['row'];
        $row['detail'] = array_values(array_filter(
            (array) ($row['detail'] ?? []),
            fn (mixed $line): bool => ! is_array($line) || ($line[0] ?? '') !== 'Shopify history match',
        ));
        if (($row['confidence'] ?? '') !== 'confirmed') {
            $row['confidence'] = $confidence;
            $row['confidence_label'] = $confidence === 'confirmed' ? 'Confirmed' : 'Probable';
        }
        $evidence = (array) ($remote['evidence'] ?? []);
        if ($confidence === 'probable') {
            array_unshift($evidence, 'Probable match only. No stored Shopify reference matched. One history row shared the inventory item, quantity, state, and a timestamp within two minutes.');
        } else {
            array_unshift($evidence, 'Confirmed from the same reference sent to Shopify.');
        }
        foreach ($evidence as $line) {
            $row['detail'][] = ['Shopify history', (string) $line];
        }
        $remoteRow = (array) ($remote['row'] ?? []);
        if (trim((string) ($remoteRow['group_key'] ?? '')) !== '') {
            $row['group_key'] = $remoteRow['group_key'];
        }
        if (trim((string) ($remoteRow['app'] ?? '')) !== '') {
            $row['app'] = $remoteRow['app'];
        }
        $row['states'] = array_merge((array) ($row['states'] ?? []), (array) ($remoteRow['states'] ?? []));
        $row['reported_by'] = 'both';
        $row['identifiers'] = array_values(array_unique(array_filter(array_merge(
            (array) ($row['identifiers'] ?? []),
            (array) ($remoteRow['identifiers'] ?? []),
        ))));
        $local['row'] = $row;

        return $local;
    }

    /**
     * @param array<string, mixed> $remote
     * @return array<string, mixed>
     */
    private function remoteRow(array $remote, ?string $reason): array
    {
        $row = $remote['row'];
        $row['confidence'] = 'unmatched';
        $row['confidence_label'] = 'Unmatched';
        if ($reason !== null) {
            $row['detail'][] = ['Match', $reason];
        } else {
            $row['detail'][] = ['Match', 'No CMS record matched this Shopify adjustment.'];
        }

        return $row;
    }

    private function reference(string $reference): string
    {
        return strtolower(trim($reference));
    }

    private function sameId(string $left, string $right): bool
    {
        $left = $this->tail($left);
        $right = $this->tail($right);

        return $left !== '' && $left === $right;
    }

    private function tail(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        $position = strrpos($id, '/');

        return strtolower($position === false ? $id : substr($id, $position + 1));
    }
}
