<?php

namespace App\Services;

use App\Jobs\RunScheduledSaleJob;
use App\Models\SaleProductUpdate;
use App\Models\ScheduledJob;
use App\Models\ScheduledJobItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SaleProductSchedulingService
{
    public function timezone(): string
    {
        return (string) config('sale_scheduling.timezone', 'Africa/Johannesburg');
    }

    public function tablesReady(): bool
    {
        foreach ([
            'sale_import_batches',
            'sale_import_items',
            'sale_product_updates',
            'scheduled_jobs',
            'scheduled_job_items',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public function approvedCount(): int
    {
        if (!$this->tablesReady()) {
            return 0;
        }

        return SaleProductUpdate::query()
            ->approvedForScheduling()
            ->count();
    }

    public function pendingCount(): int
    {
        if (!$this->tablesReady()) {
            return 0;
        }

        return SaleProductUpdate::query()
            ->where('status', SaleProductUpdate::STATUS_PENDING)
            ->count();
    }

    public function createSaleJob(CarbonInterface $scheduledAt, ?int $userId = null): ScheduledJob
    {
        if (!$this->tablesReady()) {
            throw new \RuntimeException('Sale scheduling tables are missing. Run php artisan migrate before scheduling sale updates.');
        }

        $job = DB::transaction(function () use ($scheduledAt, $userId): ScheduledJob {
            $updates = SaleProductUpdate::query()
                ->with(['product:id,handle,title,shopify_id', 'variant:id,product_id,sku,shopify_id,price,compare_at_price'])
                ->approvedForScheduling()
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($updates->isEmpty()) {
                throw new \RuntimeException('There are no sale-approved products to schedule.');
            }

            $job = ScheduledJob::create([
                'type' => ScheduledJob::TYPE_SALE_PRODUCT_UPDATE,
                'status' => ScheduledJob::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledAt->copy()->utc(),
                'created_by' => $userId,
                'metadata' => [
                    'timezone' => $this->timezone(),
                    'local_scheduled_at' => $scheduledAt->copy()->timezone($this->timezone())->format('Y-m-d H:i:s'),
                ],
                'total_items' => $updates->count(),
            ]);

            foreach ($updates as $update) {
                ScheduledJobItem::create([
                    'scheduled_job_id' => $job->id,
                    'sale_product_update_id' => $update->id,
                    'product_id' => $update->product_id,
                    'sku' => $update->sku,
                    'status' => ScheduledJobItem::STATUS_PENDING,
                    'payload' => $this->payloadForUpdate($update),
                ]);

                $update->update([
                    'status' => SaleProductUpdate::STATUS_SCHEDULED,
                    'scheduled_job_id' => $job->id,
                    'scheduled_at' => $scheduledAt->copy()->utc(),
                    'error_message' => null,
                ]);
            }

            logger()->info('Scheduled sale product update job created', [
                'scheduled_job_id' => $job->id,
                'total_items' => $updates->count(),
                'scheduled_at' => $scheduledAt->copy()->utc()->toDateTimeString(),
                'created_by' => $userId,
            ]);

            return $job;
        });

        $delay = (int) max(0, ceil(now()->diffInSeconds($job->scheduled_at, false)));
        RunScheduledSaleJob::dispatch($job->id)->delay($delay);

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForUpdate(SaleProductUpdate $update): array
    {
        return [
            'sale_product_update_id' => $update->id,
            'product_id' => $update->product_id,
            'variant_id' => $update->variant_id,
            'sku' => $update->sku,
            'sale_price' => (string) $update->sale_price,
            'compare_at_price' => (string) $update->compare_at_price,
            'prepared_tags' => $update->prepared_tags,
            'shopify_updates' => [
                'tags' => TagNormalizer::parseTokens((string) $update->prepared_tags),
                'variant' => [
                    'price' => (string) $update->sale_price,
                    'compareAtPrice' => (string) $update->compare_at_price,
                ],
            ],
        ];
    }
}
