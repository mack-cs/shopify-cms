<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeletionRequestResource\Pages;
use App\Models\DeletionRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\DeletionRequestWorkflowService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DeletionRequestResource extends Resource
{
    protected static ?string $model = DeletionRequest::class;
    protected static ?string $navigationGroup = 'Audit & History';
    protected static ?string $navigationLabel = 'Deletion Requests';
    protected static ?string $navigationIcon = 'heroicon-o-trash';
    protected static ?int $navigationSort = 11;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('entity_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('entity_title')
                    ->label('Title')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('entity_handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DeletionRequest::STATUS_COMPLETED => 'success',
                        DeletionRequest::STATUS_REJECTED => 'danger',
                        DeletionRequest::STATUS_FAILED => 'danger',
                        DeletionRequest::STATUS_PROCESSING => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('requestedBy.name')
                    ->label('Requested By')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('targetApprover.name')
                    ->label('Assigned To')
                    ->state(fn (DeletionRequest $record): string => $record->targetApprover?->name ?: 'Any eligible approver')
                    ->description(fn (DeletionRequest $record): string => $record->targetApprover
                        ? 'Assigned reviewer only'
                        : 'Open approval queue')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('approvals_count')
                    ->label('Approvals')
                    ->state(fn (DeletionRequest $record): string => $record->approvalCount() . '/2')
                    ->toggleable(),
                TextColumn::make('reason')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('rejectedBy.name')
                    ->label('Rejected By')
                    ->toggleable(),
                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completedBy.name')
                    ->label('Completed By')
                    ->toggleable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('rejected_at')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('failure_message')
                    ->label('Failure')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        DeletionRequest::STATUS_PENDING => 'Pending',
                        DeletionRequest::STATUS_PROCESSING => 'Processing',
                        DeletionRequest::STATUS_COMPLETED => 'Completed',
                        DeletionRequest::STATUS_REJECTED => 'Rejected',
                        DeletionRequest::STATUS_FAILED => 'Failed',
                    ]),
                SelectFilter::make('entity_type')
                    ->options([
                        'draft' => 'Draft',
                        'product' => 'Product',
                        'collection' => 'Collection',
                    ]),
                SelectFilter::make('target_approver_id')
                    ->label('Assigned To')
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('openProduct')
                    ->label('Open Product')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (DeletionRequest $record): bool => $record->deletable instanceof Product)
                    ->url(fn (DeletionRequest $record): ?string => $record->deletable instanceof Product
                        ? ProductResource::getUrl('edit', ['record' => $record->deletable])
                        : null)
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('approveRequest')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DeletionRequest $record): bool => self::canReview($record))
                    ->requiresConfirmation()
                    ->modalDescription('This is the second approval. Approving will queue deletion from Shopify and the local catalogue.')
                    ->action(function (DeletionRequest $record): void {
                        try {
                            $result = app(DeletionRequestWorkflowService::class)
                                ->approveRequest($record, (int) Auth::id());

                            Notification::make()
                                ->title($result['queued'] ? 'Deletion approved and queued' : 'Deletion approval recorded')
                                ->warning()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Deletion approval failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('rejectRequest')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DeletionRequest $record): bool => self::canReview($record))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason')
                            ->rows(3)
                            ->maxLength(1000),
                    ])
                    ->action(function (DeletionRequest $record, array $data): void {
                        try {
                            app(DeletionRequestWorkflowService::class)->rejectRequest(
                                $record,
                                (int) Auth::id(),
                                $data['reason'] ?? null,
                            );

                            Notification::make()
                                ->title('Deletion request rejected')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Deletion rejection failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with([
            'requester',
            'targetApprover',
            'completedBy',
            'rejectedBy',
            'approvals',
            'deletable',
        ]);
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
            'index' => Pages\ListDeletionRequests::route('/'),
        ];
    }

    private static function canReview(DeletionRequest $request): bool
    {
        $userId = (int) Auth::id();

        if (
            $userId <= 0
            || $request->status !== DeletionRequest::STATUS_PENDING
            || $request->userHasApproved($userId)
        ) {
            return false;
        }

        return $request->target_approver_id === null
            || (int) $request->target_approver_id === $userId;
    }
}
