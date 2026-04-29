<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductPartialApprovalRequestResource\Pages;
use App\Models\ProductPartialApprovalRequest;
use App\Services\ProductPartialApprovalService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ProductPartialApprovalRequestResource extends Resource
{
    protected static ?string $model = ProductPartialApprovalRequest::class;
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Partial Approval Queue';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->url(fn (ProductPartialApprovalRequest $record): ?string => $record->product
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('product.handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('requested_fields')
                    ->label('Requested Fields')
                    ->state(fn (ProductPartialApprovalRequest $record): string => implode(', ', self::requestedFieldLabels($record)))
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\Action::make('openProduct')
                    ->label('Open Product')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ProductPartialApprovalRequest $record): ?string => $record->product
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null)
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('approveRequest')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ProductPartialApprovalRequest $record): void {
                        $summary = app(ProductPartialApprovalService::class)->approveRequests(collect([$record]), (int) Auth::id());

                        Notification::make()
                            ->title('Partial approval complete')
                            ->body(self::approvalNotificationBody($summary))
                            ->status(($summary['approved'] ?? 0) > 0 ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelectedRequests')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $summary = app(ProductPartialApprovalService::class)->approveRequests($records, (int) Auth::id());

                            $parts = [];
                            if (($summary['approved'] ?? 0) > 0) {
                                $parts[] = "Approved {$summary['approved']} partial request(s).";
                            }
                            if (($summary['skipped_not_pending'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_not_pending']} that were no longer pending.";
                            }
                            if (($summary['skipped_stale'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_stale']} because the product changed after the request was created.";
                            }
                            if (($summary['skipped_own_request'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_own_request']} because you cannot approve your own request.";
                            }

                            Notification::make()
                                ->title('Partial approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No partial approvals were recorded.')
                                ->status(($summary['approved'] ?? 0) > 0 ? 'success' : 'warning')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = (int) Auth::id();

        return app(ProductPartialApprovalService::class)
            ->actionableRequestsQuery($userId);
    }

    public static function getNavigationBadge(): ?string
    {
        $userId = (int) Auth::id();
        if ($userId <= 0) {
            return null;
        }

        $count = app(ProductPartialApprovalService::class)
            ->actionableRequestsQuery($userId)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return Auth::check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductPartialApprovalRequests::route('/'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function requestedFieldLabels(ProductPartialApprovalRequest $record): array
    {
        return app(ProductPartialApprovalService::class)->requestFieldLabels(
            is_array($record->scopes) ? $record->scopes : [],
            is_array($record->core_fields) ? $record->core_fields : [],
        );
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function approvalNotificationBody(array $summary): string
    {
        if (($summary['approved'] ?? 0) > 0) {
            return 'The partial approval request was approved.';
        }

        $parts = [];

        if (($summary['skipped_not_pending'] ?? 0) > 0) {
            $parts[] = 'This request is no longer pending.';
        }

        if (($summary['skipped_stale'] ?? 0) > 0) {
            $parts[] = 'The product changed after the request was created.';
        }

        if (($summary['skipped_own_request'] ?? 0) > 0) {
            $parts[] = 'You cannot approve your own request.';
        }

        return $parts !== [] ? implode(' ', $parts) : 'No partial approval could be recorded.';
    }
}
