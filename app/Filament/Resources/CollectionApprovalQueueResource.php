<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CollectionApprovalQueueResource\Pages;
use App\Enums\RolesEnum;
use App\Models\CollectionApprovalRequest;
use App\Models\User;
use App\Services\CollectionApprovalRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class CollectionApprovalQueueResource extends Resource
{
    protected static ?string $model = CollectionApprovalRequest::class;
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Collection Approval';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('request_batch_id')
                    ->label('Batch')
                    ->state(fn (CollectionApprovalRequest $record): string => app(CollectionApprovalRequestService::class)->batchLabel($record->request_batch_id))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = trim($search);
                        if ($term === '') {
                            return $query;
                        }

                        return $query->where('request_batch_id', 'like', '%' . strtolower($term) . '%');
                    }),
                TextColumn::make('collection.title')
                    ->label('Collection')
                    ->searchable()
                    ->description(fn (CollectionApprovalRequest $record): string => self::reviewSummary($record))
                    ->url(fn (CollectionApprovalRequest $record): ?string => $record->collection
                        ? ShopifyCollectionResource::getUrl('edit', ['record' => $record->collection])
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('collection.handle')
                    ->label('Handle')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('requester.name')
                    ->label('Requested By')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('targetApprover.name')
                    ->label('Approver')
                    ->state(fn (CollectionApprovalRequest $record): string => $record->targetApprover?->name ?: 'Any reviewer')
                    ->description(fn (CollectionApprovalRequest $record): string => $record->targetApprover ? 'Assigned reviewer only' : 'Open queue')
                    ->toggleable(),
                TextColumn::make('request_note')
                    ->label('Review Note')
                    ->state(fn (CollectionApprovalRequest $record): string => trim((string) ($record->request_note ?? '')) ?: 'None')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('recently_edited_today')
                    ->label('Recently Edited Today')
                    ->indicator('Recently Edited Today')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),
                Filter::make('edited_last_7_days')
                    ->label('Edited in Last 7 Days')
                    ->indicator('Edited in Last 7 Days')
                    ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', now()->subDays(7))),
                SelectFilter::make('request_batch_id')
                    ->label('Batch')
                    ->options(fn (): array => CollectionApprovalRequest::query()
                        ->where('status', CollectionApprovalRequest::STATUS_PENDING)
                        ->whereNotNull('request_batch_id')
                        ->orderByDesc('created_at')
                        ->get(['request_batch_id'])
                        ->unique('request_batch_id')
                        ->mapWithKeys(fn (CollectionApprovalRequest $request): array => [
                            (string) $request->request_batch_id => app(CollectionApprovalRequestService::class)->batchLabel($request->request_batch_id),
                        ])
                        ->all())
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators($data, 'Batch'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('requested_by')
                    ->label('Requested By')
                    ->options(fn (): array => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators($data, 'Requested By', User::query()->pluck('name', 'id')->all())),
                SelectFilter::make('target_approver_id')
                    ->label('Approver')
                    ->options(fn (): array => ['__any__' => 'Any reviewer'] + app(CollectionApprovalRequestService::class)
                        ->eligibleApproversQuery()
                        ->pluck('name', 'id')
                        ->all())
                    ->indicateUsing(fn (array $data): array => self::singleValueIndicators(
                        $data,
                        'Approver',
                        ['__any__' => 'Any reviewer'] + app(CollectionApprovalRequestService::class)
                            ->eligibleApproversQuery()
                            ->pluck('name', 'id')
                            ->all()
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === '__any__') {
                            return $query->whereNull('target_approver_id');
                        }

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->where('target_approver_id', (int) $value);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('reviewRequest')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalHeading(fn (CollectionApprovalRequest $record): string => 'Review Collection Approval: ' . trim((string) ($record->collection?->title ?? 'Collection')))
                    ->modalContent(fn (CollectionApprovalRequest $record): HtmlString => new HtmlString(self::reviewModalHtml($record))),
                Tables\Actions\Action::make('openCollection')
                    ->label('Open Collection')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (CollectionApprovalRequest $record): ?string => $record->collection
                        ? ShopifyCollectionResource::getUrl('edit', ['record' => $record->collection])
                        : null)
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('approveRequest')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (CollectionApprovalRequest $record): bool => app(CollectionApprovalRequestService::class)
                        ->canApproveRequest($record, (int) Auth::id()))
                    ->requiresConfirmation()
                    ->action(function (CollectionApprovalRequest $record): void {
                        $summary = app(CollectionApprovalRequestService::class)->approveRequests(collect([$record]), (int) Auth::id());

                        Notification::make()
                            ->title('Collection approval complete')
                            ->body(self::approvalNotificationBody($summary))
                            ->status(($summary['approved'] ?? 0) > 0 ? 'success' : 'warning')
                            ->send();
                    }),
                Tables\Actions\Action::make('deletePendingRequest')
                    ->label('Delete Pending Approval')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (CollectionApprovalRequest $record): bool => static::canDelete($record)
                        && $record->status === CollectionApprovalRequest::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (CollectionApprovalRequest $record): void {
                        $summary = app(CollectionApprovalRequestService::class)->deletePendingRequests(collect([$record]), (int) Auth::id());

                        Notification::make()
                            ->title('Pending approval deleted')
                            ->body(self::deleteNotificationBody($summary))
                            ->status(($summary['deleted'] ?? 0) > 0 ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelectedRequests')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (): bool => (int) Auth::id() > 0
                            && app(CollectionApprovalRequestService::class)->actionableRequestsQuery((int) Auth::id())->exists())
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $summary = app(CollectionApprovalRequestService::class)->approveRequests($records, (int) Auth::id());

                            $parts = [];
                            if (($summary['approved'] ?? 0) > 0) {
                                $parts[] = "Approved {$summary['approved']} collection request(s).";
                            }
                            if (($summary['skipped_not_pending'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_not_pending']} that were no longer pending.";
                            }
                            if (($summary['skipped_stale'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_stale']} because the collection changed after the request was created.";
                            }
                            if (($summary['skipped_own_request'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_own_request']} because you cannot approve your own request.";
                            }
                            if (($summary['skipped_targeted'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_targeted']} because they were assigned to a different reviewer.";
                            }
                            if (($summary['skipped_already_approved'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_already_approved']} because you already approved that collection version.";
                            }

                            Notification::make()
                                ->title('Collection approval complete')
                                ->body($parts ? implode(' ', $parts) : 'No collection approvals were recorded.')
                                ->status(($summary['approved'] ?? 0) > 0 ? 'success' : 'warning')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteSelectedPendingRequests')
                        ->label('Delete Pending Approvals')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => static::canDelete(null))
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $summary = app(CollectionApprovalRequestService::class)->deletePendingRequests($records, (int) Auth::id());

                            Notification::make()
                                ->title('Pending approvals deleted')
                                ->body(self::deleteNotificationBody($summary))
                                ->status(($summary['deleted'] ?? 0) > 0 ? 'success' : 'warning')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CollectionApprovalRequestService::class)
            ->visiblePendingRequestsQuery();
    }

    public static function getNavigationBadge(): ?string
    {
        if ((int) Auth::id() <= 0) {
            return null;
        }

        $count = app(CollectionApprovalRequestService::class)
            ->visiblePendingRequestsQuery()
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
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollectionApprovalQueues::route('/'),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function approvalNotificationBody(array $summary): string
    {
        if (($summary['approved'] ?? 0) > 0) {
            return 'The collection approval request was approved.';
        }

        $parts = [];

        if (($summary['skipped_not_pending'] ?? 0) > 0) {
            $parts[] = 'This request is no longer pending.';
        }
        if (($summary['skipped_stale'] ?? 0) > 0) {
            $parts[] = 'The collection changed after the request was created.';
        }
        if (($summary['skipped_own_request'] ?? 0) > 0) {
            $parts[] = 'You cannot approve your own request.';
        }
        if (($summary['skipped_targeted'] ?? 0) > 0) {
            $parts[] = 'This request is assigned to a different reviewer.';
        }
        if (($summary['skipped_already_approved'] ?? 0) > 0) {
            $parts[] = 'You already approved this collection version.';
        }

        return $parts !== [] ? implode(' ', $parts) : 'No collection approval could be recorded.';
    }

    /**
     * @param array<string, mixed> $summary
     */
    private static function deleteNotificationBody(array $summary): string
    {
        $parts = [];

        if (($summary['deleted'] ?? 0) > 0) {
            $parts[] = "Deleted {$summary['deleted']} pending approval request(s).";
        }

        if (($summary['skipped_not_pending'] ?? 0) > 0) {
            $parts[] = "Skipped {$summary['skipped_not_pending']} that were no longer pending.";
        }

        return $parts !== [] ? implode(' ', $parts) : 'No pending approval requests were deleted.';
    }

    private static function reviewSummary(CollectionApprovalRequest $record): string
    {
        $parts = [];

        $collection = $record->collection;
        if ($collection?->sync_status) {
            $parts[] = 'Sync: ' . trim((string) $collection->sync_status);
        }

        $proposedHandle = trim((string) ($collection?->draft_handle ?? ''));
        $currentHandle = trim((string) ($collection?->handle ?? ''));
        if ($proposedHandle !== '' && $proposedHandle !== $currentHandle) {
            $parts[] = "URL: {$currentHandle} -> {$proposedHandle}";
        }

        if ($collection && ShopifyCollectionResource::hasMissingSeoFields($collection)) {
            $parts[] = 'Missing SEO';
        }

        return $parts !== [] ? implode(' | ', $parts) : 'Pending review';
    }

    private static function reviewModalHtml(CollectionApprovalRequest $record): string
    {
        $collection = $record->collection;
        $currentHandle = trim((string) ($collection?->handle ?? ''));
        $proposedHandle = trim((string) ($collection?->draft_handle ?? ''));
        $handleImpact = $proposedHandle !== '' && $proposedHandle !== $currentHandle
            ? e($currentHandle) . ' &rarr; ' . e($proposedHandle)
            : 'No URL change requested';

        $lines = [
            '<div class="space-y-3 text-sm">',
            '<div><strong>Requested by:</strong> ' . e($record->requester?->name ?: 'Unknown') . '</div>',
            '<div><strong>Approver routing:</strong> ' . e($record->targetApprover?->name ?: 'Any eligible reviewer') . '</div>',
            '<div><strong>Collection handle:</strong> ' . e($currentHandle !== '' ? $currentHandle : 'Unknown') . '</div>',
            '<div><strong>Requested version:</strong> ' . e((string) $record->approval_version) . '</div>',
            '<div><strong>Handle impact:</strong> ' . $handleImpact . '</div>',
            '<div><strong>Missing SEO:</strong> ' . ($collection && ShopifyCollectionResource::hasMissingSeoFields($collection) ? 'Yes' : 'No') . '</div>',
        ];

        $note = trim((string) ($record->request_note ?? ''));
        if ($note !== '') {
            $lines[] = '<div><strong>Review note:</strong><br>' . nl2br(e($note)) . '</div>';
        }

        $lines[] = '</div>';

        return implode('', $lines);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, string> $options
     * @return array<int, Indicator>
     */
    private static function singleValueIndicators(array $data, string $label, array $options = [], string $field = 'value'): array
    {
        $value = $data[$field] ?? null;

        if (!filled($value)) {
            return [];
        }

        return [
            Indicator::make($label . ': ' . ($options[$value] ?? (string) $value))
                ->removeField($field),
        ];
    }

}
