<?php

namespace App\Jobs;

use App\Models\ShopifyStackInventoryReservation;
use App\Services\AdminNotification;
use App\Services\Shopify\StackOrderReservationService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CancelShopifyStackReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param array<int, int> $reservationIds */
    public function __construct(public array $reservationIds, public ?int $userId = null) {}

    public function handle(StackOrderReservationService $service): void
    {
        $result = $service->cancelManually(
            ShopifyStackInventoryReservation::query()->whereIn('id', $this->reservationIds)->get()
        );
        $body = "Cancelled {$result['cancelled']}. Skipped {$result['skipped']}. Failed {$result['failed']}.";
        if ($result['errors'] !== []) {
            $body .= ' '.implode(' ', array_slice($result['errors'], 0, 5));
        }
        $notification = Notification::make()->title('Stack reservation cancel')->body($body);
        if ($result['failed'] > 0) {
            $notification->danger();
        } elseif ($result['cancelled'] === 0) {
            $notification->warning();
        } else {
            $notification->success();
        }
        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
