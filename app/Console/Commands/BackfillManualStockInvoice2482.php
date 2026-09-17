<?php

namespace App\Console\Commands;

use App\Models\ManualStockTransaction;
use App\Models\Product;
use App\Services\ManualStockTransactionService;
use Illuminate\Console\Command;

class BackfillManualStockInvoice2482 extends Command
{
    protected $signature = 'manual-stock:backfill-invoice-2482 {--user-id=}';
    protected $description = 'Record historical invoice 2482 without changing Shopify inventory';

    public function handle(ManualStockTransactionService $service): int
    {
        $lines = ['Wildwood Facets Stack' => 10, 'Golden Ocean Stack' => 10, 'Sculpted Sun Stack' => 12];
        $products = Product::query()->whereIn('title', array_keys($lines))->get()->keyBy('title');
        $missing = collect(array_keys($lines))->reject(fn ($title) => $products->has($title));
        if ($missing->isNotEmpty()) {
            $this->error('Missing exact CMS product title(s): '.$missing->implode(', '));
            return self::FAILURE;
        }

        $transaction = ManualStockTransaction::query()->firstOrCreate(
            ['reference_number' => '2482', 'transaction_type' => 'direct_order'],
            ['processing_mode' => ManualStockTransaction::MODE_HISTORICAL, 'recipient_name' => 'Lomaen Medical Pty Ltd',
                'transaction_date' => now()->toDateString(), 'notes' => 'Historical direct order. Inventory was adjusted before this CMS workflow existed.',
                'is_historical' => true, 'created_by' => $this->option('user-id')]
        );
        if ($transaction->status === 'completed') {
            $this->info("Historical invoice 2482 already exists as transaction {$transaction->id}; no changes made.");
            return self::SUCCESS;
        }
        foreach ($lines as $title => $quantity) {
            $product = $products[$title];
            $transaction->items()->updateOrCreate(['product_id' => $product->id], ['product_title' => $title, 'sku' => $product->variants()->value('sku'), 'quantity' => $quantity]);
        }
        $service->prepare($transaction);
        $service->process($transaction, $this->option('user-id') ? (int) $this->option('user-id') : null);
        $this->info("Recorded invoice 2482 as historical transaction {$transaction->id}. Shopify inventory was not changed.");
        return self::SUCCESS;
    }
}
