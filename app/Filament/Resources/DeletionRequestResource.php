<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeletionRequestResource\Pages;
use App\Models\DeletionRequest;
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
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['requester', 'completedBy', 'rejectedBy', 'approvals']);
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
}
