<?php

namespace App\Services\Shopify;

use Illuminate\Support\Carbon;

final class StackOrderLineMapper
{
    public function orderedQuantity(array $line): int
    {
        return max(0, (int) ($line['current_quantity'] ?? $line['currentQuantity'] ?? $line['quantity'] ?? 0));
    }

    public function fulfilledBaseline(array $line, string $topic = 'orders/updated'): ?int
    {
        $ordered = $this->orderedQuantity($line);
        if ($this->hasValue($line, '_stack_baseline_fulfilled_quantity')) {
            return max(0, min($ordered, (int) $line['_stack_baseline_fulfilled_quantity']));
        }
        if ($this->hasNumeric($line, 'unfulfilledQuantity') || $this->hasNumeric($line, 'unfulfilled_quantity')) {
            $unfulfilled = (int) ($line['unfulfilledQuantity'] ?? $line['unfulfilled_quantity']);

            return $ordered - max(0, min($ordered, $unfulfilled));
        }
        if ($this->hasNumeric($line, 'fulfillable_quantity') || $this->hasNumeric($line, 'fulfillableQuantity')) {
            $fulfillable = (int) ($line['fulfillable_quantity'] ?? $line['fulfillableQuantity']);

            return $ordered - max(0, min($ordered, $fulfillable));
        }
        $status = strtolower(trim((string) ($line['fulfillment_status'] ?? $line['fulfillmentStatus'] ?? '')));
        if (in_array($status, ['fulfilled', 'shipped'], true)) {
            return $ordered;
        }
        if (str_contains(strtolower($topic), 'create')) {
            return 0;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function fromGraphqlLine(array $line): ?array
    {
        if (! array_key_exists('unfulfilledQuantity', $line) || $line['unfulfilledQuantity'] === null) {
            return null;
        }

        $current = max(0, (int) ($line['currentQuantity'] ?? $line['quantity'] ?? 0));
        $unfulfilled = max(0, min($current, (int) $line['unfulfilledQuantity']));

        return [
            'admin_graphql_api_id' => $line['id'] ?? null,
            'variant_id' => data_get($line, 'variant.id'),
            'sku' => $line['sku'] ?? null,
            'title' => $line['title'] ?? null,
            'quantity' => $current,
            'current_quantity' => $current,
            'unfulfilled_quantity' => $unfulfilled,
            'fulfillable_quantity' => $unfulfilled,
            'fulfillment_status' => $unfulfilled === 0 ? 'fulfilled' : ($unfulfilled < $current ? 'partial' : null),
            '_stack_baseline_fulfilled_quantity' => $current - $unfulfilled,
        ];
    }

    public function orderCreatedAt(array $payload): ?Carbon
    {
        $value = $payload['created_at'] ?? $payload['createdAt'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isGraphqlOrderFullyFulfilled(array $order): bool
    {
        $status = strtoupper(trim((string) ($order['displayFulfillmentStatus'] ?? '')));

        return in_array($status, ['FULFILLED', 'COMPLETE'], true);
    }

    private function hasNumeric(array $line, string $key): bool
    {
        return array_key_exists($key, $line) && is_numeric($line[$key]);
    }

    private function hasValue(array $line, string $key): bool
    {
        return array_key_exists($key, $line) && $line[$key] !== null && $line[$key] !== '';
    }
}
