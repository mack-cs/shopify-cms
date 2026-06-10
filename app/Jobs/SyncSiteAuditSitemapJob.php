<?php

namespace App\Jobs;

use App\Services\AdminNotification;
use App\Services\SiteAudit\SitemapDiscoveryService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncSiteAuditSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public ?int $userId = null,
    ) {
    }

    public function handle(SitemapDiscoveryService $sitemapDiscoveryService): void
    {
        try {
            $syncedUrls = $sitemapDiscoveryService->sync((string) config('site-audit.sitemap_url'));

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Sitemap sync complete')
                    ->body("Synced {$syncedUrls} public sitemap URL(s).")
                    ->success(),
                $this->userId,
            );
        } catch (Throwable $exception) {
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Sitemap sync failed')
                    ->body($exception->getMessage())
                    ->danger(),
                $this->userId,
            );

            throw $exception;
        }
    }
}
