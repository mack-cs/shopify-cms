<?php

namespace App\Filament\Resources\NewProductDraftResource\RelationManagers;

use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\StyleProfile;
use App\Services\HeaderStore;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class StyleProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'styleProfiles';

    protected static ?string $title = 'SEO Draft';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('sku')
                ->maxLength(80)
                ->disabled(),

            Forms\Components\TextInput::make('style_type')
                ->label('Product Type')
                ->maxLength(120)
                ->disabled()
                ->dehydrated(false)
                ->afterStateHydrated(function (Forms\Components\TextInput $component, ?StyleProfile $record): void {
                    $component->state($this->resolvedProduct($record)?->type);
                }),

            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('product_colors')
                        ->label('Colors')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?StyleProfile $record): void {
                            $component->state($this->resolvedProduct($record)?->color_string);
                        }),
                    Forms\Components\TextInput::make('jewelry_material_display')
                        ->label('Jewelry material')
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?StyleProfile $record): void {
                            $product = $this->resolvedProduct($record);
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
                        ->afterStateHydrated(function (Forms\Components\Textarea $component, ?StyleProfile $record): void {
                            $raw = $this->resolvedProduct($record)?->body_html ?? '';
                            $component->state(trim(strip_tags((string) $raw)));
                        }),
                ])
                ->columnSpanFull(),

            Forms\Components\TextInput::make('draft_seo_title')
                ->label('SEO Title')
                ->live(debounce: 500)
                ->helperText(fn (Forms\Get $get): string => StyleProfile::seoTitleLengthHint($get('draft_seo_title')))
                ->maxLength(StyleProfile::SEO_TITLE_RECOMMENDED_MAX)
                ->rules([
                    function (): \Closure {
                        return function (string $attribute, $value, $fail): void {
                            $length = StyleProfile::trimmedLength($value);
                            if ($length > 0 && $length < StyleProfile::SEO_TITLE_RECOMMENDED_MIN) {
                                $fail('SEO title is too short. Use at least ' . StyleProfile::SEO_TITLE_RECOMMENDED_MIN . ' characters.');
                            }
                        };
                    },
                ])
                ->columnSpanFull(),

            Forms\Components\Textarea::make('draft_seo_description')
                ->label('SEO Description (150-160 chars)')
                ->live(debounce: 500)
                ->helperText(fn (Forms\Get $get): string => StyleProfile::seoDescriptionLengthHint($get('draft_seo_description')))
                ->rows(2)
                ->maxLength(StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MAX)
                ->rules([
                    function (): \Closure {
                        return function (string $attribute, $value, $fail): void {
                            $length = StyleProfile::trimmedLength($value);
                            if ($length > 0 && $length < StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MIN) {
                                $fail('SEO description is too short. Use at least ' . StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MIN . ' characters.');
                            }
                        };
                    },
                ])
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('image_url')
                ->label('Image')
                ->square()
                ->size(40)
                ->checkFileExistence(false)
                ->getStateUsing(function (StyleProfile $record): ?string {
                    $productImage = $this->resolvedProduct($record)?->images()
                        ->orderBy('position')
                        ->value('src');

                    $source = $productImage ?: $record->image_url;

                    return self::normalizeImageUrl($source);
                }),
            Tables\Columns\TextColumn::make('sku')->searchable(),
            Tables\Columns\TextColumn::make('product.color_string')->label('Colors')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('draft_seo_title')->label('SEO Title')->limit(60)->wrap(),
            Tables\Columns\TextColumn::make('draft_seo_description')->label('SEO Desc')->limit(80)->wrap(),
            Tables\Columns\TextColumn::make('applied_at')->dateTime()->label('Applied')->toggleable(),
        ])->headerActions([
            Tables\Actions\CreateAction::make()
                ->visible(fn (): bool => !(bool) $this->getOwnerRecord()?->styleProfiles()->exists())
                ->mutateFormDataUsing(function (array $data): array {
                    return $this->normalizeFormData($data);
                }),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->modalHeading(function (?StyleProfile $record): string|HtmlString {
                    $title = $this->resolvedProduct($record)?->title
                        ?? $this->getOwnerRecord()?->title;

                    if (!$title) {
                        return 'Edit SEO Draft';
                    }

                    return new HtmlString('Edit SEO Draft for <em>' . e($title) . '</em>');
                })
                ->mutateFormDataUsing(function (array $data): array {
                    return $this->normalizeFormData($data);
                }),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    private function normalizeFormData(array $data): array
    {
        $owner = $this->getOwnerRecord();
        $product = Product::query()
            ->where('handle', $owner?->handle)
            ->with('variants')
            ->first();

        $data['handle'] = $owner?->handle;
        $data['product_id'] = $product?->id;

        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            $sku = trim((string) ($product?->variants->first()?->sku ?? $owner?->sku ?? $owner?->handle));
        }

        $data['sku'] = $sku !== '' ? $sku : null;

        if (empty($data['image_url'])) {
            $imageUrl = $product?->images()
                ->orderBy('position')
                ->value('src');

            if ($imageUrl) {
                $data['image_url'] = $imageUrl;
            }
        }

        return $data;
    }

    private function resolvedProduct(?StyleProfile $record): ?Product
    {
        $owner = $this->getOwnerRecord();

        if ($record?->relationLoaded('product') && $record->product) {
            return $record->product;
        }

        if ($record?->product) {
            return $record->product;
        }

        $handle = trim((string) ($record?->handle ?? $owner?->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        return Product::query()
            ->where('handle', $handle)
            ->first();
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
