<?php

namespace App\Filament\Resources\InventoryResource\Widgets;

use App\Services\AsyncJobStateService;
use Filament\Widgets\Widget;

class InventoryRunBanner extends Widget
{
    protected static string $view = 'filament.widgets.async-job-status-banner';
    protected static ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $state = app(AsyncJobStateService::class)->snapshot(AsyncJobStateService::INVENTORY_CHECK);

        return [
            'isRunning' => $state['is_running'],
            'title' => 'Shopify inventory check in progress',
            'body' => 'The read-only Shopify inventory refresh is still running. This page refreshes automatically while Shopify status and inventory are being loaded into the local records.',
            'startedAt' => $state['started_at']?->diffForHumans(),
            'color' => 'primary',
        ];
    }
}
