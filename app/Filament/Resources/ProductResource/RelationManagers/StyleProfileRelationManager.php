<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\Builder;
use App\Models\StyleProfile;
use App\Models\ShopifyRow;
use App\Services\HeaderStore;

class StyleProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'styleProfiles';

    protected static ?string $title = 'SEO Draft';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            // Forms\Components\TextInput::make('image_url')->label('Image')->maxLength(2048),
            Forms\Components\TextInput::make('sku')
                ->maxLength(80)
                ->disabled(),

            Forms\Components\TextInput::make('style_type')
                ->label('Product Type')
                ->maxLength(120)
                ->disabled()
                ->dehydrated(false)
                ->afterStateHydrated(function (Forms\Components\TextInput $component, $record): void {
                    $component->state($record?->product?->type);
                }),
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('product_colors')
                        ->label('Colors')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, $record): void {
                            $component->state($record?->product?->color_string);
                        }),
                    Forms\Components\TextInput::make('jewelry_material_display')
                        ->label('Jewelry material')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, $record): void {
                            $product = $record?->product;
                            if (!$product) {
                                $component->state(null);
                                return;
                            }

                            $row = ShopifyRow::where('import_id', $product->import_id)
                                ->where('handle', $product->handle)
                                ->where('row_type', 'product_primary')
                                ->first();

                            $component->state((string) ($row?->get(HeaderStore::JEWELRY_MATERIAL, '') ?? ''));
                        }),
                ])
                ->columnSpanFull(),
            Forms\Components\TextInput::make('materials')->maxLength(255),
            Forms\Components\TextInput::make('components')->maxLength(255),
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\Textarea::make('colour_prompt')
                        ->rows(4),
                    Forms\Components\Textarea::make('product_description')
                        ->label('Description')
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\Textarea $component, $record): void {
                            $raw = $record?->product?->body_html ?? '';
                            $component->state(trim(strip_tags((string) $raw)));
                        }),
                ])
                ->columnSpanFull(),

            Forms\Components\TextInput::make('draft_seo_title')
                ->label('SEO Title')
                ->helperText(fn (Forms\Get $get): string => StyleProfile::seoTitleLengthHint($get('draft_seo_title')))
                ->maxLength(StyleProfile::SEO_TITLE_RECOMMENDED_MAX)
                ->columnSpanFull(),

            Forms\Components\Textarea::make('draft_seo_description')
                ->label('SEO Description (150-160 chars)')
                ->helperText(fn (Forms\Get $get): string => StyleProfile::seoDescriptionLengthHint($get('draft_seo_description')))
                ->rows(2)
                ->maxLength(StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MAX)
                ->columnSpanFull(),
        ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            ImageColumn::make('image_url')
                ->label('Image')
                ->square()
                ->size(40)
                ->checkFileExistence(false)
                ->getStateUsing(function ($record): ?string {
                    $productImage = $record->product?->images()
                        ->orderBy('position')
                        ->value('src');

                    $source = $productImage ?: $record->image_url;

                    return self::normalizeImageUrl($source);
                }),
            Tables\Columns\TextColumn::make('sku')->searchable(),
            Tables\Columns\TextColumn::make('product.color_string')->label('Colors')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('draft_seo_title')->label('SEO Title')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('draft_seo_description')->label('SEO Desc')->limit(80)->wrap(),
            Tables\Columns\TextColumn::make('seo_updated_at')->dateTime()->label('Draft Updated')->toggleable(),
            Tables\Columns\TextColumn::make('seo_approved_at')->dateTime()->label('Approved')->toggleable(),
            Tables\Columns\TextColumn::make('seo_synced_at')->dateTime()->label('Synced')->toggleable(),
            Tables\Columns\TextColumn::make('applied_at')->dateTime()->label('Applied To Product')->toggleable(),
        ])->filters([
            Filter::make('seo_updated_at')
                ->label('SEO Draft Updated Date')
                ->form([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('to')->label('To'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when($data['from'] ?? null, fn (Builder $sub, $from): Builder => $sub->whereDate('seo_updated_at', '>=', $from))
                        ->when($data['to'] ?? null, fn (Builder $sub, $to): Builder => $sub->whereDate('seo_updated_at', '<=', $to));
                }),
        ])->headerActions([
            // Read-only on Products. Edit SEO drafts from New Products instead.
        ])->actions([
            Tables\Actions\ViewAction::make()
                ->modalHeading(function ($record): string|HtmlString {
                    $title = $record?->product?->title;
                    if (!$title) {
                        return 'View SEO Draft';
                    }

                    return new HtmlString('View SEO Draft for <em>' . e($title) . '</em>');
                }),
        ]);
    }

    public function isReadOnly(): bool
    {
        return true;
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
