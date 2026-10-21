<?php

namespace App\Services\Inventory;

final class InventoryAuditOperationGrouper
{
    /**
     * Fold Shopify state lines that belong to one reference, second, and reason into one operation.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function group(array $rows): array
    {
        $plain = [];
        $grouped = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['group_key'] ?? ''));
            if ($key === '') {
                $plain[] = $row;

                continue;
            }
            $grouped[$key][] = $row;
        }

        $operations = $plain;
        foreach ($grouped as $key => $members) {
            $operations[] = count($members) === 1 ? $members[0] : $this->merge($key, $members);
        }

        usort($operations, function (array $left, array $right): int {
            return strcmp((string) ($right['occurred_at_iso'] ?? ''), (string) ($left['occurred_at_iso'] ?? ''));
        });

        return $operations;
    }

    /**
     * @param array<int, array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private function merge(string $groupKey, array $members): array
    {
        $base = $members[0];
        foreach ($members as $member) {
            if (($member['reported_by'] ?? '') !== 'shopify') {
                $base = $member;
                break;
            }
        }

        $states = [];
        $detail = [];
        $links = [];
        $identifiers = [];
        $confidence = 'local';
        $reported = [];
        foreach ($members as $member) {
            foreach ((array) ($member['states'] ?? []) as $state) {
                if (is_array($state)) {
                    $states[] = $state;
                }
            }
            foreach ((array) ($member['detail'] ?? []) as $line) {
                if (is_array($line)) {
                    $detail[$line[0].'|'.$line[1]] = $line;
                }
            }
            foreach ((array) ($member['links'] ?? []) as $link) {
                if (is_array($link) && isset($link[1])) {
                    $links[(string) $link[1]] = $link;
                }
            }
            foreach ((array) ($member['identifiers'] ?? []) as $identifier) {
                $identifiers[] = (string) $identifier;
            }
            $confidence = $this->stronger($confidence, (string) ($member['confidence'] ?? 'local'));
            $reported[(string) ($member['reported_by'] ?? 'local')] = true;
        }

        $base['key'] = 'op-'.substr(hash('sha256', $groupKey), 0, 24);
        $base['group_key'] = $groupKey;
        $base['activity'] = $this->activity($base['activity'] ?? 'Inventory adjustment');
        $base['change'] = $this->change($states, (string) ($base['change'] ?? ''));
        $base['states'] = $this->uniqueStates($states);
        $base['detail'] = array_values($detail);
        $base['detail'][] = ['Grouped states', 'These state changes share one reference, time, and reason, so they are one operation.'];
        $base['links'] = array_values($links);
        $base['identifiers'] = array_values(array_unique(array_filter($identifiers)));
        $base['confidence'] = $confidence;
        $base['confidence_label'] = match ($confidence) {
            'confirmed' => 'Confirmed',
            'probable' => 'Probable',
            'unmatched' => 'Unmatched',
            default => 'Local record',
        };
        $base['reported_by'] = isset($reported['shopify'], $reported['local']) || isset($reported['both'])
            ? 'both'
            : (isset($reported['shopify']) ? 'shopify' : 'local');
        foreach (['sku', 'product', 'app', 'location', 'triggered_by', 'process', 'reason'] as $field) {
            if (trim((string) ($base[$field] ?? '')) !== '') {
                continue;
            }
            foreach ($members as $member) {
                if (trim((string) ($member[$field] ?? '')) !== '') {
                    $base[$field] = $member[$field];
                    break;
                }
            }
        }

        return $base;
    }

    private function stronger(string $current, string $next): string
    {
        $rank = ['local' => 0, 'unmatched' => 1, 'probable' => 2, 'confirmed' => 3];

        return ($rank[$next] ?? 0) > ($rank[$current] ?? 0) ? $next : $current;
    }

    /**
     * @param array<int, array<string, mixed>> $states
     */
    private function change(array $states, string $fallback): string
    {
        $parts = [];
        foreach ($this->uniqueStates($states) as $state) {
            if (($state['origin'] ?? '') === 'local') {
                continue;
            }
            $delta = (int) ($state['delta'] ?? 0);
            $name = trim((string) ($state['state'] ?? ''));
            $parts[] = ($delta > 0 ? '+' : '').$delta.($name !== '' ? ' '.$name : '');
        }

        return $parts === [] ? $fallback : implode('; ', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $states
     * @return array<int, array<string, mixed>>
     */
    private function uniqueStates(array $states): array
    {
        $unique = [];
        foreach ($states as $state) {
            $unique[strtolower((string) ($state['state'] ?? '')).'|'.(int) ($state['delta'] ?? 0).'|'.(string) ($state['origin'] ?? '')] = $state;
        }

        return array_values($unique);
    }

    private function activity(string $activity): string
    {
        $parts = explode(' · ', $activity, 2);

        return $parts[0];
    }
}
