<?php

namespace App\Filament\Resources\ShopifyAuditResource\Widgets;

use App\Services\AsyncJobStateService;
use Filament\Widgets\Widget;

class ShopifyAuditRunBanner extends Widget
{
    protected static string $view = 'filament.widgets.async-job-status-banner';
    protected static ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $stateService = app(AsyncJobStateService::class);
        $auditState = $stateService->snapshot(AsyncJobStateService::COMPLEMENTARY_AUDIT);
        $reconcileState = $stateService->snapshot(AsyncJobStateService::COMPLEMENTARY_RECONCILIATION);

        $isRunning = $auditState['is_running'] || $reconcileState['is_running'];
        $startedAt = collect([$auditState['started_at'], $reconcileState['started_at']])
            ->filter()
            ->sort()
            ->first();

        $body = $auditState['is_running'] && $reconcileState['is_running']
            ? 'The complementary audit and complementary fix are both running in the background. This page refreshes automatically until Shopify health results are updated.'
            : ($reconcileState['is_running']
                ? 'The complementary fix is running in the background. This page refreshes automatically until Shopify health results are updated.'
                : 'The live Shopify complementary audit is running in the background. This page refreshes automatically until the latest Shopify results are stored.');

        return [
            'isRunning' => $isRunning,
            'title' => 'Shopify audit job in progress',
            'body' => $body,
            'startedAt' => $startedAt?->diffForHumans(),
            'color' => 'warning',
        ];
    }
}
