<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\CollectionApprovalQueueResource\Pages;
use App\Models\ShopifyCollection;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CollectionApprovalQueueResource extends Resource
{
    protected static ?string $model = ShopifyCollection::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';
    protected static ?string $navigationLabel = 'Collection Approval Queue';
    protected static ?string $navigationGroup = 'SEO';
    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('effective_title')
                    ->label('Collection')
                    ->state(fn (ShopifyCollection $record): string => ShopifyCollectionResource::displayDraftValue($record->draft_title, $record->title))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $sub) use ($search): void {
                            $sub->where('title', 'like', "%{$search}%")
                                ->orWhere('draft_title', 'like', "%{$search}%");
                        });
                    })
                    ->sortable()
                    ->description(fn (ShopifyCollection $record): ?string => ShopifyCollectionResource::draftChangeDescription($record->draft_title, $record->title, 'Shopify'))
                    ->wrap(),
                TextColumn::make('effective_handle')
                    ->label('Handle')
                    ->state(fn (ShopifyCollection $record): string => ShopifyCollectionResource::displayCollectionHandle($record))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $sub) use ($search): void {
                            $sub->where('handle', 'like', "%{$search}%")
                                ->orWhere('draft_handle', 'like', "%{$search}%");
                        });
                    })
                    ->sortable()
                    ->description(fn (ShopifyCollection $record): ?string => ShopifyCollectionResource::draftChangeDescription($record->draft_handle, $record->handle, 'Live'))
                    ->wrap(),
                TextColumn::make('draft_handle')
                    ->label('Proposed URL')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_seo_title')
                    ->label('SEO Title')
                    ->state(fn (ShopifyCollection $record): string => ShopifyCollectionResource::displayDraftValue($record->draft_seo_title, $record->seo_title))
                    ->limit(60)
                    ->description(fn (ShopifyCollection $record): ?string => ShopifyCollectionResource::draftChangeDescription($record->draft_seo_title, $record->seo_title, 'Shopify'))
                    ->wrap(),
                TextColumn::make('effective_seo_description')
                    ->label('SEO Desc')
                    ->state(fn (ShopifyCollection $record): string => ShopifyCollectionResource::displayDraftValue($record->draft_seo_description, $record->seo_description))
                    ->limit(80)
                    ->description(fn (ShopifyCollection $record): ?string => ShopifyCollectionResource::draftChangeDescription($record->draft_seo_description, $record->seo_description, 'Shopify'))
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('missing_seo')
                    ->label('Missing SEO')
                    ->boolean()
                    ->state(fn (ShopifyCollection $record): bool => !ShopifyCollectionResource::canApproveRecord($record) && $record->deindex !== true)
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('approvals_current')
                    ->label('Approvals')
                    ->state(fn (ShopifyCollection $record): int => $record->approvalsForCurrentVersionCount())
                    ->formatStateUsing(fn (int $state): string => "{$state}/2")
                    ->badge()
                    ->color(fn (int $state): string => $state >= 2 ? 'success' : ($state === 1 ? 'warning' : 'gray')),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->url(fn (ShopifyCollection $record): string => ShopifyCollectionResource::getUrl('edit', ['record' => $record])),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ShopifyCollection $record): void {
                        $result = ShopifyCollectionResource::approveRecord($record, (int) Auth::id());

                        $notification = Notification::make()->title(match ($result['status']) {
                            'approved' => 'Collection approved',
                            'already-approved' => 'Already approved',
                            default => 'Approval blocked',
                        })->body($result['message']);

                        match ($result['status']) {
                            'approved' => $notification->success(),
                            'already-approved' => $notification->warning(),
                            default => $notification->danger(),
                        };

                        $notification->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-badge')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $approved = 0;
                            $alreadyApproved = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                if (!$record instanceof ShopifyCollection) {
                                    continue;
                                }

                                $result = ShopifyCollectionResource::approveRecord($record, (int) Auth::id());
                                if ($result['status'] === 'approved') {
                                    $approved++;
                                    continue;
                                }

                                if ($result['status'] === 'already-approved') {
                                    $alreadyApproved++;
                                    continue;
                                }

                                $blocked++;
                            }

                            $parts = [];
                            if ($approved > 0) {
                                $parts[] = "Approved {$approved}.";
                            }
                            if ($alreadyApproved > 0) {
                                $parts[] = "Skipped {$alreadyApproved} already approved by you.";
                            }
                            if ($blocked > 0) {
                                $parts[] = "Blocked {$blocked}.";
                            }

                            $notification = Notification::make()
                                ->title('Collection approval queue processed')
                                ->body($parts === [] ? 'No collections were processed.' : implode(' ', $parts));

                            if ($blocked > 0) {
                                $notification->warning();
                            } elseif ($approved > 0) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $importId = ShopifyCollectionResource::currentImportId();
        $userId = (int) Auth::id();

        if (!$importId || $userId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('import_id', $importId)
            ->whereRaw(
                '(select count(distinct user_id) from collection_approvals where collection_approvals.collection_id = collections.id and collection_approvals.approval_version = collections.approval_version) < 2'
            )
            ->whereNotExists(function ($sub) use ($userId): void {
                $sub->selectRaw('1')
                    ->from('collection_approvals')
                    ->whereColumn('collection_approvals.collection_id', 'collections.id')
                    ->whereColumn('collection_approvals.approval_version', 'collections.approval_version')
                    ->where('collection_approvals.user_id', $userId);
            });
    }

    public static function getNavigationBadge(): ?string
    {
        if (!Auth::check()) {
            return null;
        }

        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([
            RolesEnum::SuperAdmin->value,
            RolesEnum::Admin->value,
            RolesEnum::SeoReviewer->value,
        ]) ?? false;
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
            'index' => Pages\ListCollectionApprovalQueues::route('/'),
        ];
    }
}
