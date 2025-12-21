<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;

class ChangeLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeLogs';

    protected static ?string $title = 'Change History';

    /**
     * Read-only: no form needed, but Filament requires a Form method.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            // No editable form fields because logs are immutable
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordTitleAttribute('created_at')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('changedBy.name')
                    ->label('Who')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('changedBy', function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                        });
                    })
                    ->toggleable(),

                TextColumn::make('model_type')
                    ->label('Area')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('field')
                    ->label('Field')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('old_value')
                    ->label('Old')
                    ->limit(60)
                    ->wrap()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('new_value')
                    ->label('New')
                    ->limit(60)
                    ->wrap()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('import_id')
                    ->label('Import')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('shopify_row_id')
                    ->label('Shopify Row')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('changed_by')
                    ->label('Changed By')
                    ->relationship('changedBy', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('model_type')
                    ->label('Area (Model Type)')
                    ->options(fn () => $this->getOwnerRecord()
                        ->changeLogs()
                        ->select('model_type')
                        ->whereNotNull('model_type')
                        ->distinct()
                        ->pluck('model_type', 'model_type')
                        ->mapWithKeys(fn ($v) => [$v => class_basename($v)])
                        ->toArray()
                    )
                    ->searchable(),

                SelectFilter::make('field')
                    ->label('Field')
                    ->options(fn () => $this->getOwnerRecord()
                        ->changeLogs()
                        ->select('field')
                        ->whereNotNull('field')
                        ->distinct()
                        ->orderBy('field')
                        ->pluck('field', 'field')
                        ->toArray()
                    )
                    ->searchable(),

                SelectFilter::make('import_id')
                    ->label('Import ID')
                    ->options(fn () => $this->getOwnerRecord()
                        ->changeLogs()
                        ->select('import_id')
                        ->whereNotNull('import_id')
                        ->distinct()
                        ->orderByDesc('import_id')
                        ->limit(200)
                        ->pluck('import_id', 'import_id')
                        ->toArray()
                    )
                    ->searchable(),

                Filter::make('date_range')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
                            ->when($data['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist(function (Infolist $infolist): Infolist {
                        return $infolist->schema([
                            Section::make('Change')
                                ->schema([
                                    TextEntry::make('created_at')
                                        ->label('When')
                                        ->dateTime('Y-m-d H:i'),
                                    TextEntry::make('changedBy.name')
                                        ->label('Changed By')
                                        ->placeholder('-'),
                                    TextEntry::make('model_type')
                                        ->label('Model')
                                        ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-'),
                                    TextEntry::make('field')
                                        ->label('Field')
                                        ->placeholder('-'),
                                    TextEntry::make('old_value')
                                        ->label('Old Value')
                                        ->placeholder('-')
                                        ->columnSpanFull(),
                                    TextEntry::make('new_value')
                                        ->label('New Value')
                                        ->placeholder('-')
                                        ->columnSpanFull(),
                                ])->columns(2),
                            Section::make('Links')
                                ->schema([
                                    TextEntry::make('import_id')
                                        ->label('Import ID')
                                        ->placeholder('-'),
                                    TextEntry::make('shopify_row_id')
                                        ->label('Shopify Row ID')
                                        ->placeholder('-'),
                                ])->columns(2),
                        ]);
                    }),
            ])
            ->headerActions([
                // No create action (immutable)
            ])
            ->bulkActions([
                // No bulk delete (immutable)
            ]);
    }

    /**
     * HARD read-only guards at the RelationManager level.
     */
    // public static function canCreate(): bool
    // {
    //     return false;
    // }
    

    public function isReadOnly(): bool
    {
        return true;
    }
}
