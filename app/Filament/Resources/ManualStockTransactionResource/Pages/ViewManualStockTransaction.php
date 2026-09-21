<?php
namespace App\Filament\Resources\ManualStockTransactionResource\Pages;
use App\Filament\Resources\ManualStockTransactionResource;
use App\Jobs\ProcessManualStockTransactionJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
class ViewManualStockTransaction extends ViewRecord
{
    protected static string $resource = ManualStockTransactionResource::class;
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->visible(fn (): bool => in_array($this->record->status, ['draft', 'ready', 'needs_attention', 'failed'], true)),
            Actions\Action::make('retryInventory')
                ->label(fn (): string => $this->record->status === 'failed' ? 'Retry failed inventory update' : 'Process inventory')
                ->icon('heroicon-o-arrow-path')->color('danger')->requiresConfirmation()
                ->modalDescription('Shopify will set On Hand to the previewed physical quantity using compare-and-swap protection. Completed component rows are never processed twice.')
                ->visible(fn (): bool => in_array($this->record->status, ['ready', 'failed', 'partially_failed'], true))
                ->action(function (): void {
                    ProcessManualStockTransactionJob::dispatch($this->record->id, Auth::id());
                    $this->record->update(['status' => 'queued']);
                    Notification::make()->title('Inventory update queued')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
