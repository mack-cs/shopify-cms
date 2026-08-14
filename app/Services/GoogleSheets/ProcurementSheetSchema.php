<?php

namespace App\Services\GoogleSheets;

final class ProcurementSheetSchema
{
    public const FIELDS = [
        'sku' => 'SKU', 'product' => 'Product', 'vendor' => 'Vendor',
        'product_type' => 'Product Type', 'currently_on_sale' => 'Currently on Sale',
        'sale_percentage' => 'Sale Percentage',
        'current_inventory' => 'Current Inventory',
        'action_required' => 'Action Required',
        'ignore' => 'Ignore',
        'quantity_on_order_phase_1' => 'Quantity On Order - Phase 1',
        'order_id_phase_1' => 'Order ID - Phase 1',
        'eta_date_phase_1' => 'ETA Date - Phase 1',
        'quantity_on_order_phase_2' => 'Quantity On Order - Phase 2',
        'order_id_phase_2' => 'Order ID - Phase 2',
        'eta_date_phase_2' => 'ETA Date - Phase 2',
        'quantity_on_order_phase_3' => 'Quantity On Order - Phase 3',
        'order_id_phase_3' => 'Order ID - Phase 3',
        'eta_date_phase_3' => 'ETA Date - Phase 3',
        'total_quantity_on_order' => 'Total Quantity On Order',
        'projected_inventory_position' => 'Projected Inventory Position',
        'predicted_weekly_demand' => 'Predicted Weekly Demand',
        'estimated_days_of_stock_remaining' => 'Estimated Days of Stock Remaining',
        'predicted_runout_date' => 'Predicted Runout Date',
        'lead_time_days' => 'Lead Time Days',
        'stock_required_for_lead_time' => 'Stock Required for Lead Time',
        'recommended_order_before_incoming_stock' => 'Recommended Order Before Incoming Stock',
        'additional_order_required' => 'Additional Order Required',
        'cms_movement_classification' => 'cms_movement_classification',
        'action_reason' => 'Action Reason',
        'stockout_before_incoming_arrival' => 'Stockout Before Incoming Arrival',
        'incoming_stock_covers_requirement' => 'Incoming Stock Covers Requirement',
        'last_updated' => 'Last Updated',
    ];

    public const HUMAN_OWNED_FIELDS = [
        'ignore',
        'quantity_on_order_phase_1', 'order_id_phase_1', 'eta_date_phase_1',
        'quantity_on_order_phase_2', 'order_id_phase_2', 'eta_date_phase_2',
        'quantity_on_order_phase_3', 'order_id_phase_3', 'eta_date_phase_3',
    ];

    /** @param array<int,mixed> $headers @return array<string,int> */
    public function map(array $headers): array
    {
        $seen = [];
        foreach ($headers as $index => $header) {
            $key = $this->normalize((string) $header);
            if ($key === '') {
                continue;
            }
            if (array_key_exists($key, $seen)) {
                throw new \RuntimeException("Duplicate Google Sheet header [{$header}].");
            }
            $seen[$key] = $index;
        }
        $mapped = [];
        foreach (self::FIELDS as $field => $header) {
            $key = $this->normalize($header);
            if (! array_key_exists($key, $seen)) {
                throw new \RuntimeException("Required Google Sheet header [{$header}] is missing.");
            }
            $mapped[$field] = $seen[$key];
        }

        return $mapped;
    }

    public function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    /** @param array<int,mixed> $headers */
    public function isCurrentLayout(array $headers): bool
    {
        return array_map([$this, 'normalize'], $headers)
            === array_map([$this, 'normalize'], array_values(self::FIELDS));
    }

    /** @param array<int,array<int,mixed>> $values @return array<int,array<int,mixed>> */
    public function upgradeLayout(array $values): array
    {
        $headers = array_shift($values) ?? [];
        $seen = [];
        foreach ($headers as $index => $header) {
            $key = $this->normalize((string) $header);
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                throw new \RuntimeException("Duplicate Google Sheet header [{$header}].");
            }
            $seen[$key] = $index;
        }
        if (! isset($seen[$this->normalize(self::FIELDS['sku'])])) {
            throw new \RuntimeException('Required Google Sheet header [SKU] is missing.');
        }

        $upgraded = [array_values(self::FIELDS)];
        foreach ($values as $row) {
            $next = [];
            foreach (self::FIELDS as $header) {
                $oldIndex = $seen[$this->normalize($header)] ?? null;
                $next[] = $oldIndex === null ? '' : ($row[$oldIndex] ?? '');
            }
            $upgraded[] = $next;
        }

        return $upgraded;
    }

    public function columnName(int $zeroBased): string
    {
        $name = '';
        for ($number = $zeroBased + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + (($number - 1) % 26)).$name;
        }

        return $name;
    }
}
