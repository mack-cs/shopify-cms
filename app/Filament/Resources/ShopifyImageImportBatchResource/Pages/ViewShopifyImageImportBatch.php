<?php

namespace App\Filament\Resources\ShopifyImageImportBatchResource\Pages;

use App\Filament\Resources\ShopifyImageImportBatchResource;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\ShopifyImageImportBatch;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ViewShopifyImageImportBatch extends ViewRecord
{
    protected static string $resource = ShopifyImageImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retryLocalRebuild')
                ->label('Retry local rebuild')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('This stages inherited component photographs locally. It does not upload images or publish alt text to Shopify.')
                ->visible(function (): bool {
                    $ids = $this->record->rebuild_stack_product_ids;

                    return in_array($this->record->rebuild_status, [
                        ShopifyImageImportBatch::REBUILD_INCOMPLETE,
                        ShopifyImageImportBatch::REBUILD_FAILED,
                    ], true) && is_array($ids) && $ids !== [];
                })
                ->action(function (): void {
                    $ids = array_values(array_unique(array_filter(array_map(
                        'intval',
                        is_array($this->record->rebuild_stack_product_ids) ? $this->record->rebuild_stack_product_ids : [],
                    ))));
                    sort($ids);
                    $job = new RebuildShopifyStackImagesJob($this->record->id, $ids, Auth::id());
                    try {
                        dispatch($job);
                    } catch (\Throwable $exception) {
                        RebuildShopifyStackImagesJob::markDispatchFailed($this->record, $ids, $exception, $job);
                        Notification::make()
                            ->title('Local stack rebuild could not be started')
                            ->body(Str::limit($exception->getMessage(), 500, ''))
                            ->danger()
                            ->send();

                        return;
                    }
                    Notification::make()->title('Local stack rebuild started')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
