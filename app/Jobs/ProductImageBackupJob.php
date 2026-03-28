<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductImageBackupService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductImageBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $productIds
     */
    public function __construct(
        public array $productIds,
        public ?int $userId = null,
        public ?string $reason = null,
    ) {}

    public function handle(ProductImageBackupService $service): void
    {
        $products = Product::query()
            ->whereIn('id', $this->productIds)
            ->get();

        $result = $service->backupProducts($products);

        if (!$this->userId) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        $parts = [];
        if ($this->reason) {
            $parts[] = $this->reason . '.';
        }
        $parts[] = "Products {$result['products']}.";
        $parts[] = "Processed {$result['processed']} image(s).";
        if ($result['backed_up'] > 0) {
            $parts[] = "Backed up {$result['backed_up']}.";
        }
        if ($result['reused'] > 0) {
            $parts[] = "Reused {$result['reused']} existing backup(s).";
        }
        if ($result['restored_candidates'] > 0) {
            $parts[] = "Remote-missing candidates {$result['restored_candidates']}.";
        }
        if ($result['missing_source'] > 0) {
            $parts[] = "Missing source {$result['missing_source']}.";
        }
        if ($result['failed'] > 0) {
            $parts[] = "Failed {$result['failed']}.";
        }

        if (!empty($result['failures'])) {
            $samples = collect($result['failures'])
                ->take(3)
                ->map(fn (array $failure): string => "Image {$failure['image_id']}: {$failure['message']}")
                ->implode(' | ');
            if ($samples !== '') {
                $parts[] = "Errors: {$samples}";
            }
        }

        $notification = Notification::make()
            ->title('Product image backup complete')
            ->body(implode(' ', $parts));

        if ($result['failed'] > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->sendToDatabase($user);
    }
}
