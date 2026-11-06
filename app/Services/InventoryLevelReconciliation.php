<?php

namespace App\Services;

use App\Jobs\HandleShopifyInventoryLevelUpdatedJob;
use Illuminate\Support\Facades\DB;

final class InventoryLevelReconciliation
{
    public function record(string $id): void
    {
        DB::transaction(function () use ($id) {
            DB::table('inventory_reconciliations')->insertOrIgnore(['inventory_item_id' => $id, 'version' => 0, 'processed_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_reconciliations')->where('inventory_item_id', $id)->increment('version', 1, ['updated_at' => now()]);
        });
    }

    public function dispatchPending(): void
    {
        DB::table('inventory_reconciliations')->whereColumn('version', '>', 'processed_version')->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    HandleShopifyInventoryLevelUpdatedJob::dispatch($row->inventory_item_id);
                }
            });
    }
}
