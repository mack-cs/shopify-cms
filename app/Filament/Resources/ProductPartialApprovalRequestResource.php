<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductPartialApprovalRequestResource\Pages;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ProductShopifyUpdater;
use App\Services\ProductPartialApprovalService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

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
                TextColumn::make('request_batch_id')
                    ->label('Batch')
                    ->state(fn (ProductPartialApprovalRequest $record): string => app(ProductPartialApprovalService::class)->batchLabel($record->request_batch_id))
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = trim($search);
                        if ($term === '') {
                            return $query;
                        }

                        return $query->where('request_batch_id', 'like', '%' . strtolower($term) . '%');
                    }),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->description(fn (ProductPartialApprovalRequest $record): string => self::reviewSummary($record))
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
                TextColumn::make('targetApprover.name')
                    ->label('Approver')
                    ->state(fn (ProductPartialApprovalRequest $record): string => $record->targetApprover?->name ?: 'Any reviewer')
                    ->description(fn (ProductPartialApprovalRequest $record): string => $record->targetApprover ? 'Assigned reviewer only' : 'Open queue')
                    ->toggleable(),
                TextColumn::make('requested_fields')
                    ->label('Requested Fields')
                    ->state(fn (ProductPartialApprovalRequest $record): string => implode(', ', self::requestedFieldLabels($record)))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('request_note')
                    ->label('Review Note')
                    ->state(fn (ProductPartialApprovalRequest $record): string => trim((string) ($record->request_note ?? '')) ?: 'None')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('request_batch_id')
                    ->label('Batch')
                    ->options(fn (): array => ProductPartialApprovalRequest::query()
                        ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
                        ->whereNotNull('request_batch_id')
                        ->orderByDesc('created_at')
                        ->get(['request_batch_id'])
                        ->unique('request_batch_id')
                        ->mapWithKeys(fn (ProductPartialApprovalRequest $request): array => [
                            (string) $request->request_batch_id => app(ProductPartialApprovalService::class)->batchLabel($request->request_batch_id),
                        ])
                        ->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('requested_by')
                    ->label('Requested By')
                    ->options(fn (): array => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('target_approver_id')
                    ->label('Approver')
                    ->options(fn (): array => ['__any__' => 'Any reviewer'] + app(ProductPartialApprovalService::class)
                        ->eligibleApproversQuery()
                        ->pluck('name', 'id')
                        ->all())
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
                SelectFilter::make('scope')
                    ->label('Request Type')
                    ->options([
                        ProductShopifyUpdater::SYNC_SCOPE_PRODUCT => ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_PRODUCT],
                        ProductShopifyUpdater::SYNC_SCOPE_SEO => ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_SEO],
                        ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS => ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS],
                        ProductShopifyUpdater::SYNC_SCOPE_VARIANTS => ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_VARIANTS],
                        ProductShopifyUpdater::SYNC_SCOPE_IMAGES => ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_IMAGES],
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '') {
                            return $query;
                        }

                        return $query->whereJsonContains('scopes', $value);
                    }),
                TernaryFilter::make('handle_impact')
                    ->label('Handle Impact')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(function (Builder $sub): void {
                            $sub->whereJsonContains('core_fields', ProductShopifyUpdater::CORE_FIELD_HANDLE)
                                ->orWhereJsonContains('core_fields', ProductShopifyUpdater::CORE_FIELD_TITLE);
                        }),
                        false: fn (Builder $query): Builder => $query
                            ->whereJsonDoesntContain('core_fields', ProductShopifyUpdater::CORE_FIELD_HANDLE)
                            ->whereJsonDoesntContain('core_fields', ProductShopifyUpdater::CORE_FIELD_TITLE),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('reviewRequest')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalHeading(fn (ProductPartialApprovalRequest $record): string => 'Review Partial Approval: ' . trim((string) ($record->product?->title ?? 'Product')))
                    ->modalContent(fn (ProductPartialApprovalRequest $record): HtmlString => new HtmlString(self::reviewModalHtml($record))),
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
                    ->visible(fn (ProductPartialApprovalRequest $record): bool => app(ProductPartialApprovalService::class)
                        ->canApproveRequest($record, (int) Auth::id()))
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
                        ->visible(fn (): bool => (int) Auth::id() > 0
                            && app(ProductPartialApprovalService::class)->actionableRequestsQuery((int) Auth::id())->exists())
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
                            if (($summary['skipped_targeted'] ?? 0) > 0) {
                                $parts[] = "Skipped {$summary['skipped_targeted']} because they were assigned to a different reviewer.";
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
        return app(ProductPartialApprovalService::class)
            ->visiblePendingRequestsQuery();
    }

    public static function getNavigationBadge(): ?string
    {
        if ((int) Auth::id() <= 0) {
            return null;
        }

        $count = app(ProductPartialApprovalService::class)
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

        if (($summary['skipped_targeted'] ?? 0) > 0) {
            $parts[] = 'This request is assigned to a different reviewer.';
        }

        return $parts !== [] ? implode(' ', $parts) : 'No partial approval could be recorded.';
    }

    private static function reviewSummary(ProductPartialApprovalRequest $record): string
    {
        $parts = [];

        if ($record->product?->status) {
            $parts[] = 'Status: ' . trim((string) $record->product->status);
        }

        if (app(ProductPartialApprovalService::class)->isHandleAffectingRequest($record)) {
            $currentHandle = trim((string) ($record->product?->handle ?? ''));
            $approvedHandle = trim((string) ($record->product?->approved_handle ?? ''));
            if ($approvedHandle !== '' && $approvedHandle !== $currentHandle) {
                $parts[] = "Handle impact: {$currentHandle} -> {$approvedHandle}";
            } else {
                $parts[] = 'Handle impact';
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : 'Pending review';
    }

    private static function reviewModalHtml(ProductPartialApprovalRequest $record): string
    {
        $requestedFields = self::requestedFieldLabels($record);
        $currentHandle = trim((string) ($record->product?->handle ?? ''));
        $approvedHandle = trim((string) ($record->product?->approved_handle ?? ''));
        $handleImpact = app(ProductPartialApprovalService::class)->isHandleAffectingRequest($record)
            ? ($approvedHandle !== '' && $approvedHandle !== $currentHandle
                ? e($currentHandle) . ' &rarr; ' . e($approvedHandle)
                : 'Yes')
            : 'No';

        $lines = [
            '<div class="space-y-3 text-sm">',
            '<div><strong>Requested by:</strong> ' . e($record->requester?->name ?: 'Unknown') . '</div>',
            '<div><strong>Approver routing:</strong> ' . e($record->targetApprover?->name ?: 'Any eligible reviewer') . '</div>',
            '<div><strong>Product status:</strong> ' . e(trim((string) ($record->product?->status ?? 'unknown'))) . '</div>',
            '<div><strong>Requested fields:</strong> ' . e(implode(', ', $requestedFields)) . '</div>',
            '<div><strong>Handle impact:</strong> ' . $handleImpact . '</div>',
        ];

        $note = trim((string) ($record->request_note ?? ''));
        if ($note !== '') {
            $lines[] = '<div><strong>Review note:</strong><br>' . nl2br(e($note)) . '</div>';
        }

        $lines[] = '</div>';

        return implode('', $lines);
    }
}
