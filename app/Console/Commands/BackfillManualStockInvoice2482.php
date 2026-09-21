<?php

namespace App\Console\Commands;

use App\Models\ManualStockTransaction;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ManualStockTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillManualStockInvoice2482 extends Command
{
    protected $signature = 'manual-stock:backfill-invoice-2482 {--user-id=} {--date= : Actual invoice date in YYYY-MM-DD format}';
    protected $description = 'Record historical invoice 2482 without changing Shopify inventory';

    public function handle(ManualStockTransactionService $service): int
    {
        $lines = ['LRBU44' => 10, 'LRBU50' => 10, 'LRBU49' => 12];
        $variants = Variant::query()->active()->with('product')->whereIn('sku', array_keys($lines))->get()
            ->groupBy(fn (Variant $variant): string => strtoupper(trim((string) $variant->sku)));
        $invalid = collect(array_keys($lines))->filter(fn (string $sku): bool => ($variants[$sku] ?? collect())->count() !== 1);
        if ($invalid->isNotEmpty()) {
            $this->error('Stack SKU(s) must match exactly one active CMS variant: '.$invalid->implode(', '));
            return self::FAILURE;
        }
        $products = collect($lines)->mapWithKeys(fn (int $quantity, string $sku): array => [$sku => $variants[$sku]->first()->product]);

        $date = trim((string) $this->option('date'));
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('The --date value must use YYYY-MM-DD.');
            return self::FAILURE;
        }

        $transaction = ManualStockTransaction::query()->firstOrCreate(
            ['reference_number' => '2482', 'transaction_type' => 'direct_order'],
            ['processing_mode' => ManualStockTransaction::MODE_HISTORICAL, 'recipient_name' => 'Lomaen Medical Pty Ltd',
                'transaction_date' => $date !== '' ? $date : now()->toDateString(), 'notes' => 'Historical direct order. Stack components were already deducted manually from Shopify before this CMS workflow existed.',
                'is_historical' => true, 'created_by' => $this->option('user-id')]
        );
        if ($transaction->status === 'completed') {
            $this->info("Historical invoice 2482 already exists as transaction {$transaction->id}; no changes made.");
            return self::SUCCESS;
        }
        $transaction->items()->whereNotIn('product_id', $products->pluck('id'))->delete();
        foreach ($lines as $sku => $quantity) {
            $product = $products[$sku];
            $transaction->items()->updateOrCreate(['product_id' => $product->id], ['product_title' => $product->title, 'sku' => $sku, 'quantity' => $quantity]);
        }
        $service->prepare($transaction);

        $historicalComponents = [
            'Silver Nile Facets Bracelet' => [['stack_sku' => 'LRBU44', 'quantity' => 10]],
            'Sundarbark Facets Bracelet' => [['stack_sku' => 'LRBU44', 'quantity' => 10]],
            'Nyika Facets Bracelet' => [['stack_sku' => 'LRBU44', 'quantity' => 10]],
            'Mosi Gold Bracelet' => [['stack_sku' => 'LRBU50', 'quantity' => 10], ['stack_sku' => 'LRBU49', 'quantity' => 12]],
            'Niassa Dawn Bracelet' => [['stack_sku' => 'LRBU50', 'quantity' => 10]],
            'Atlantic Tide Bracelet' => [['stack_sku' => 'LRBU50', 'quantity' => 10]],
            'Matobo Sculpt Bracelet' => [['stack_sku' => 'LRBU49', 'quantity' => 12]],
            'Desert Daisy Bracelet' => [['stack_sku' => 'LRBU49', 'quantity' => 12]],
        ];
        $expected = collect($historicalComponents)->map(fn (array $sources): int => collect($sources)->sum('quantity'))->all();
        $actual = $transaction->fresh('impacts')->impacts->mapWithKeys(fn ($impact): array => [$impact->product_title => (int) $impact->quantity_required])->all();
        ksort($expected);
        ksort($actual);
        if ($actual !== $expected) {
            $this->warn('Current stack mappings differ from the confirmed historical deduction. Recording the supplied historical component ledger instead; Shopify will not be changed.');
            $this->table(['Resolved component', 'Quantity'], collect($actual)->map(fn ($qty, $title) => [$title, $qty])->values()->all());
            $componentProducts = Product::query()->activeStatus()->whereIn('title', array_keys($historicalComponents))->with('variants')->get()->groupBy('title');
            $unresolved = collect(array_keys($historicalComponents))->filter(fn (string $title): bool => ($componentProducts[$title] ?? collect())->count() !== 1
                || $componentProducts[$title]->first()->variants->count() !== 1);
            if ($unresolved->isNotEmpty()) {
                $this->error('Historical component(s) must match exactly one active single-variant product: '.$unresolved->implode(', '));
                return self::FAILURE;
            }
            $transaction->impacts()->delete();
            foreach ($historicalComponents as $title => $sources) {
                $component = $componentProducts[$title]->first();
                $variant = $component->variants->first();
                $transaction->impacts()->create([
                    'variant_id' => $variant->id, 'sku' => (string) $variant->sku, 'product_title' => $title,
                    'quantity_required' => collect($sources)->sum('quantity'),
                    'sources' => collect($sources)->map(function (array $source) use ($products, $lines): array {
                        $stack = $products[$source['stack_sku']];
                        return ['product' => $stack->title, 'ordered_quantity' => $lines[$source['stack_sku']],
                            'component_quantity_each' => 1, 'component_quantity_total' => $source['quantity']];
                    })->all(),
                    'idempotency_key' => (string) Str::uuid(), 'status' => 'historical_pending',
                ]);
            }
        }
        $service->process($transaction, $this->option('user-id') ? (int) $this->option('user-id') : null);
        $this->info("Recorded invoice 2482 as historical transaction {$transaction->id}. Shopify inventory was not changed.");
        return self::SUCCESS;
    }
}
