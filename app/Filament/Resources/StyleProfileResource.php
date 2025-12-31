<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StyleProfileResource\Pages;
use App\Enums\RolesEnum;
use App\Models\Product;
use App\Models\StyleProfile;
use App\Services\StyleProfileCsvImporter;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Illuminate\Support\Facades\Storage;
use App\Filament\Exports\StyleProfileExporter;

class StyleProfileResource extends Resource
{
    protected static ?string $model = StyleProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Catalog';
    protected static ?string $navigationLabel = 'Style Profiles';
    protected static ?int $navigationSort = 4;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Section::make('Link')
                ->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->relationship('product', 'handle')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->getOptionLabelFromRecordUsing(fn (Product $record) => trim($record->handle . ' - ' . ($record->title ?? ''))),
                    TextInput::make('sku')->required()->maxLength(80),
                    TextInput::make('image_url')->label('Image')->maxLength(2048),
                ])->columns(2),
            Section::make('Inputs')
                ->schema([
                    TextInput::make('style_type')->label('Style')->maxLength(120),
                    TextInput::make('materials')->maxLength(255),
                    TextInput::make('components')->maxLength(255),
                    Textarea::make('colour_prompt')->rows(2),
                ])->columns(2),
            Section::make('Draft Outputs')
                ->schema([
                    TextInput::make('draft_title')->label('Title')->maxLength(255),
                    Textarea::make('draft_description')->label('Description')->rows(5),
                    TextInput::make('draft_seo_title')
                        ->label('SEO Title')
                        ->maxLength(255),
                    Textarea::make('draft_seo_description')
                        ->label('SEO Description (160 chars)')
                        ->rows(2)
                        ->maxLength(160),
                    Textarea::make('draft_image_alt_text')
                        ->label('Image Alt Text (125 chars)')
                        ->rows(2)
                        ->maxLength(125),
                ])->columns(2),
            Section::make('SEO Sync')
                ->schema([
                    Select::make('seo_sync_status')
                        ->label('Sync Status')
                        ->options([
                            'draft' => 'Draft',
                            'ready' => 'Ready to sync',
                        ])
                        ->required()
                        ->default('draft'),
                    TextInput::make('seo_synced_at')
                        ->label('Last Synced')
                        ->disabled()
                        ->dehydrated(false),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Image')
                    ->square()
                    ->size(40)
                    ->checkFileExistence(false)
                    ->getStateUsing(function (StyleProfile $record): ?string {
                        $productImage = $record->product?->images()
                            ->orderBy('position')
                            ->value('src');

                        $source = $productImage ?: $record->image_url;

                        return self::normalizeImageUrl($source);
                    }),
                TextColumn::make('product.title')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('handle')
                    ->label('Handle')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sku')->searchable(),
                TextColumn::make('materials')->searchable(),
                TextColumn::make('style_type')->label('Style')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('draft_seo_title')->label('SEO Title')->limit(60)->wrap()->toggleable(),
                TextColumn::make('draft_seo_description')
                    ->label('SEO Desc')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('seo_sync_status')
                    ->label('Sync Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'warning',
                        'synced' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('seo_synced_at')
                    ->label('Synced At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applied_at')
                    ->dateTime()
                    ->label('Applied')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'handle')
                    ->searchable(),
                TernaryFilter::make('unlinked')
                    ->label('Unlinked')
                    ->queries(
                        true: fn ($query) => $query->whereNull('product_id'),
                        false: fn ($query) => $query->whereNotNull('product_id'),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalHeading('Edit Style Profile'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalHeading('Add Style Profile')
                    ->color('success')
                    ->createAnother(false),
                Tables\Actions\Action::make('importCsv')
                    ->label('Import CSV')
                    ->color('info')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('CSV File')
                            ->required()
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                    ])
                    ->action(function (array $data, StyleProfileCsvImporter $importer): void {
                        $path = Storage::disk('local')->path($data['file']);
                        $result = $importer->importFromPath($path);

                        Notification::make()
                            ->title('Import complete')
                            ->body(
                                "Total: {$result['total']}, Imported: {$result['imported']}, " .
                                "No Handle: {$result['skipped_no_handle']}, No SKU: {$result['skipped_no_sku']}, " .
                                "No product link: {$result['unlinked_no_product']}"
                            )
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('pushReadySeo')
                        ->label('Push SEO (Ready)')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $synced = 0;
                            foreach ($records as $record) {
                                if ($record->seo_sync_status !== 'ready') {
                                    continue;
                                }
                                $product = $record->product;
                                if (!$product) {
                                    continue;
                                }

                                $payload = [];
                                if ($record->draft_seo_title) {
                                    $payload['seo_title'] = $record->draft_seo_title;
                                }
                                if ($record->draft_seo_description) {
                                    $payload['seo_description'] = $record->draft_seo_description;
                                }

                                if (!$payload) {
                                    continue;
                                }

                                $product->update($payload);
                                $record->update([
                                    'seo_sync_status' => 'synced',
                                    'seo_synced_at' => Carbon::now(),
                                ]);
                                $synced++;
                            }

                            Notification::make()
                                ->title('SEO sync complete')
                                ->body("Synced {$synced} style(s).")
                                ->success()
                                ->send();
                        }),
                    ExportBulkAction::make()
                        ->exporter(StyleProfileExporter::class)
                        ->visible(fn (): bool => Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false),
                ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStyleProfiles::route('/'),
        ];
    }

    private static function normalizeImageUrl(?string $src): ?string
    {
        if ($src === null) {
            return null;
        }

        $trimmed = trim($src);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
    }
}
