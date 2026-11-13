<?php

namespace App\Filament\Actions;

use App\Services\InventoryPendingPushCancellation;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class DiscardPendingInventoryPushes
{
    public static function make(Closure $variantIds): Action
    {
        return Action::make('discardPendingPushes')
            ->label('Discard pending pushes')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading('Discard pending inventory pushes and read Shopify')
            ->modalDescription('This cancels stale local adjustments and removes waiting push jobs for the listed SKUs. Current Shopify stock will replace the CMS inventory values. Shopify stock will not be changed. Running jobs cannot be discarded.')
            ->modalSubmitActionLabel('Discard pushes and read Shopify')
            ->visible(fn () => InventoryPendingPushCancellation::isSuperAdmin(Auth::user()))
            ->form(function (Model $record) use ($variantIds): array {
                $plan = app(InventoryPendingPushCancellation::class)->preview($variantIds($record), (int) Auth::id());

                return [
                    Forms\Components\Placeholder::make('affected')
                        ->label('Affected SKUs and approvals')
                        ->content('SKUs: '.implode(', ', array_column($plan['variants'], 'sku'))
                            .'. Approval IDs: '.implode(', ', array_column($plan['requests'], 'id'))
                            .'. Waiting/active push jobs: '.count($plan['jobs'])
                            .'. Linked multi-SKU approvals are cancelled together; mixed jobs retain their other SKUs.'),
                    Forms\Components\Hidden::make('fingerprint')->default($plan['fingerprint'])->required(),
                    Forms\Components\Textarea::make('reason')->label('Reason for discarding')->required()->maxLength(2000),
                    Forms\Components\Checkbox::make('accept_shopify')
                        ->label('I have checked Shopify and want its current stock to replace the listed local counts. Previous write outcomes remain in the audit history.')
                        ->accepted(),
                ];
            })
            ->action(function (Model $record, array $data) use ($variantIds): void {
                $result = app(InventoryPendingPushCancellation::class)->cancel($variantIds($record), (int) Auth::id(),
                    (string) ($data['reason'] ?? ''), (string) ($data['fingerprint'] ?? ''));
                Notification::make()->success()->title('Pending inventory pushes discarded')
                    ->body("{$result['cancelled_requests']} approval(s) cancelled; {$result['deleted_jobs']} waiting job(s) deleted; {$result['refreshed_skus']} SKU(s) read from Shopify. Shopify inventory was not changed.")
                    ->send();
            });
    }
}
