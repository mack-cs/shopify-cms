<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\AdminNotification;
use App\Services\ProductShopifyUpdater;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductArchiveShopifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, int> $productIds
     */
    public function __construct(
        public array $productIds,
        public ?int $userId = null,
    ) {}

    public function handle(ProductShopifyUpdater $updater): void
    {
        $archived = 0;
        $failed = 0;
        $errors = [];

        $products = Product::query()->whereIn('id', $this->productIds)->get();

        foreach ($products as $product) {
            try {
                $updater->archiveProduct($product);
                $archived++;
            } catch (\Throwable $e) {
                $failed++;
                $sku = trim((string) ($product->variants()->value('sku') ?? ''));
                $label = $sku !== '' ? $sku : ('Product #'.$product->id);
                $errors[] = "{$label}: {$e->getMessage()}";
            }
        }

        if (!$this->userId) {
            return;
        }

        $parts = ["Archived {$archived} on Shopify."];
        if ($failed > 0) {
            $parts[] = "Failed {$failed}.";
        }
        if ($errors !== []) {
            $parts[] = collect($errors)->take(5)->implode(' | ');
        }

        $notification = Notification::make()
            ->title('Shopify product archive complete')
            ->body(implode(' ', $parts));

        if ($failed > 0) {
            $notification->danger();
        } elseif ($archived > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
