<?php

namespace App\Filament\Resources\NewProductDraftResource\Widgets;

use App\Services\AsyncJobStateService;
use Filament\Widgets\Widget;

class NewProductDraftRunBanner extends Widget
{
    protected static string $view = 'filament.widgets.async-job-status-banner';
    protected static ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $state = app(AsyncJobStateService::class)->snapshot(AsyncJobStateService::NEW_PRODUCT_SHOPIFY_CREATE);

        return [
            'isRunning' => $state['is_running'],
            'title' => 'Creating new products in Shopify',
            'body' => 'New approved drafts are still being created in Shopify. This indicator clears automatically when the background job finishes and the follow-up sync work is done.',
            'startedAt' => $state['started_at']?->diffForHumans(),
            'color' => 'success',
        ];
    }
}
