<?php

namespace App\Jobs;

use App\Models\SiteAuditRun;
use App\Services\AdminNotification;
use App\Services\SiteAudit\SitemapDiscoveryService;
use App\Services\SiteAudit\SiteAuditRunnerService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class StartSiteAuditRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public string $type = SiteAuditRun::TYPE_MANUAL,
        public ?int $userId = null,
        public bool $syncSitemap = true,
    ) {
    }

    public function handle(
        SitemapDiscoveryService $sitemapDiscoveryService,
        SiteAuditRunnerService $siteAuditRunnerService,
    ): void {
        try {
            $syncedUrls = $this->syncSitemap
                ? $sitemapDiscoveryService->sync((string) config('site-audit.sitemap_url'))
                : null;

            $run = $siteAuditRunnerService->run($this->type);

            $body = "Audit run #{$run->id} queued {$run->total_urls} URL check(s).";
            if ($syncedUrls !== null) {
                $body = "Synced {$syncedUrls} sitemap URL(s). {$body}";
            }

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Site audit queued')
                    ->body($body)
                    ->success(),
                $this->userId,
            );
        } catch (Throwable $exception) {
            SiteAuditRun::query()->create([
                'type' => in_array($this->type, [SiteAuditRun::TYPE_MANUAL, SiteAuditRun::TYPE_SCHEDULED], true)
                    ? $this->type
                    : SiteAuditRun::TYPE_MANUAL,
                'status' => SiteAuditRun::STATUS_FAILED,
                'started_at' => now(),
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Site audit failed to start')
                    ->body($exception->getMessage())
                    ->danger(),
                $this->userId,
            );

            throw $exception;
        }
    }
}
