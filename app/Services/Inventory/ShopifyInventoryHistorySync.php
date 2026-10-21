<?php

namespace App\Services\Inventory;

use App\Models\ShopifyInventoryAdjustment;
use App\Models\ShopifyInventoryHistorySync as ShopifyInventoryHistorySyncLog;
use Illuminate\Support\Carbon;

final class ShopifyInventoryHistorySync
{
    public function __construct(private readonly ShopifyInventoryHistoryQuery $history) {}

    /**
     * Store one calendar day of Shopify adjustment history.
     *
     * @return array{date:string,status:string,stored:int,message:?string}
     */
    public function syncDay(Carbon $day, ?string $app = null, ?string $reason = null): array
    {
        $from = $day->copy()->startOfDay();
        $until = $day->copy()->endOfDay();
        $result = $this->history->collect($from, $until, null, $app !== '' ? $app : null, 250, 8, $reason);
        if (($result['status'] ?? '') !== 'ok') {
            $this->log($from, 'failed', 0, $result['message'] ?? 'Shopify history could not be loaded.');

            throw new \RuntimeException((string) ($result['message'] ?? 'Shopify history could not be loaded.'));
        }

        $stored = 0;
        $syncedAt = now();
        foreach ($result['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $adjustmentId = trim((string) ($row['inventory_adjustment_id'] ?? ''));
            if ($adjustmentId === '') {
                $adjustmentId = substr(hash('sha256', (string) json_encode($row)), 0, 40);
            }
            $occurredAt = $this->occurredAt($row['second'] ?? null) ?? $from;
            ShopifyInventoryAdjustment::query()->updateOrCreate(
                ['adjustment_id' => $adjustmentId],
                [
                    'occurred_at' => $occurredAt,
                    'sku' => $this->nullable($row['product_variant_sku'] ?? null),
                    'product_title' => $this->nullable($row['product_variant_title'] ?? null),
                    'inventory_item_id' => $this->nullable($row['inventory_item_id'] ?? null),
                    'product_variant_id' => $this->nullable($row['product_variant_id'] ?? null),
                    'location_id' => $this->nullable($row['inventory_location_id'] ?? null),
                    'location_name' => $this->nullable($row['inventory_location_name'] ?? null),
                    'inventory_state' => $this->nullable($row['inventory_state'] ?? null),
                    'delta' => (int) ($row['inventory_adjustment_change'] ?? 0),
                    'reason' => $this->nullable($row['inventory_change_reason'] ?? null),
                    'app_name' => $this->nullable($row['inventory_app_name'] ?? null),
                    'staff_name' => $this->nullable($row['staff_member_name'] ?? null),
                    'reference_document_uri' => $this->nullable($row['reference_document_uri'] ?? null),
                    'reference_document_type' => $this->nullable($row['reference_document_type'] ?? null),
                    'reference_document_id' => $this->nullable($row['reference_document_id'] ?? null),
                    'adjustment_group_id' => $this->nullable($row['inventory_adjustment_group_id'] ?? null),
                    'synced_at' => $syncedAt,
                ],
            );
            $stored++;
        }

        $status = ($result['incomplete'] ?? false) ? 'incomplete' : ($reason !== null && trim($reason) !== '' ? 'corrections' : 'succeeded');
        $this->log($from, $status, $stored, $result['message'] ?? null);

        return [
            'date' => $from->toDateString(),
            'status' => $status,
            'stored' => $stored,
            'message' => $result['message'] ?? null,
        ];
    }

    /**
     * @return array<int, array{date:string,status:string,stored:int,message:?string}>
     */
    public function syncRange(Carbon $from, Carbon $until, ?string $app = null): array
    {
        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($until->copy()->startOfDay()); $day->addDay()) {
            $days[] = $this->syncDay($day->copy(), $app);
        }

        return $days;
    }

    public function latestSyncAt(): ?Carbon
    {
        $value = ShopifyInventoryHistorySyncLog::query()->where('status', 'succeeded')->max('finished_at');

        return $value ? Carbon::parse($value) : null;
    }

    private function log(Carbon $day, string $status, int $rows, ?string $message): void
    {
        $payload = [
            'status' => $status,
            'row_count' => $rows,
            'message' => $message,
            'finished_at' => now(),
        ];
        $record = ShopifyInventoryHistorySyncLog::query()
            ->whereDate('history_date', $day->toDateString())
            ->first();
        if ($record) {
            $record->update($payload);

            return;
        }

        ShopifyInventoryHistorySyncLog::query()->create($payload + [
            'history_date' => $day->toDateString(),
        ]);
    }

    private function occurredAt(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) <= 10) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
