<?php

namespace App\Jobs;

use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\ShopifyRow;
use App\Models\Variant;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\HeaderStore;
use App\Services\ProductShopifyUpdater;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ApplyProcurementPriceUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /**
     * @param array<int,array{sku:string,variant_id:int,new_price:string}> $updates
     */
    public function __construct(
        public readonly array $updates,
        public readonly ?int $userId = null,
    ) {}

    public function handle(ProductShopifyUpdater $updater, ProcurementSheetSyncService $sheets): void
    {
        foreach ($this->updates as $update) {
            $variant = Variant::query()->with('product')->find((int) $update['variant_id']);
            if (! $variant instanceof Variant || ! $variant->product) {
                Log::warning('Procurement price update skipped because the variant disappeared.', $update);
                continue;
            }

            $sku = strtoupper(trim((string) $update['sku']));
            $newPrice = number_format((float) $update['new_price'], 2, '.', '');
            $oldPrice = $variant->price === null ? '' : number_format((float) $variant->price, 2, '.', '');

            try {
                $confirmed = $updater->updateVariantPrice($variant, $newPrice);
                DB::transaction(function () use ($variant, $sku, $oldPrice, $confirmed): void {
                    $price = $confirmed['price'];
                    $variant->forceFill([
                        'price' => $price,
                        'last_synced_at' => now(),
                        'local_dirty' => false,
                    ])->save();

                    $product = $variant->product;
                    ShopifyRow::query()
                        ->where('import_id', $product->import_id)
                        ->where('handle', $product->handle)
                        ->where('row_type', 'variant')
                        ->get()
                        ->filter(fn (ShopifyRow $row): bool => strtoupper(trim((string) $row->get(HeaderStore::VARIANT_SKU, ''))) === $sku)
                        ->each(function (ShopifyRow $row) use ($price): void {
                            $row->set(HeaderStore::VARIANT_PRICE, $price);
                            $row->save();
                        });

                    NewProductDraft::query()
                        ->where(function ($query) use ($product, $sku): void {
                            $query->where('shopify_id', $product->shopify_id)
                                ->orWhere('handle', $product->handle)
                                ->orWhere('sku', $sku);
                        })
                        ->get()
                        ->each(fn (NewProductDraft $draft) => NewProductDraft::withoutEvents(
                            fn () => $draft->forceFill(['variant_price' => $price])->save()
                        ));

                    ChangeLog::create([
                        'import_id' => $product->import_id,
                        'product_id' => $product->id,
                        'changed_by' => $this->userId,
                        'source' => 'PROCUREMENT_PRICE_UPDATE',
                        'model_type' => Variant::class,
                        'model_id' => $variant->id,
                        'field' => 'price',
                        'old_value' => $oldPrice,
                        'new_value' => $price,
                    ]);
                });

                $sheets->updateConfirmedRows([
                    $sku => ['current_price' => $confirmed['price'], 'new_price' => ''],
                ]);
            } catch (\Throwable $exception) {
                Log::error('Procurement price update failed; New Price was retained.', [
                    'sku' => $sku,
                    'variant_id' => $variant->id,
                    'new_price' => $newPrice,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
