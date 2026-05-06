<?php

namespace App\Filament\Resources\NewProductDraftResource\RelationManagers;

use App\Filament\Resources\NewProductDraftResource;
use App\Models\Product;
use App\Models\StyleProfile;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class StyleProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'styleProfiles';

    protected static ?string $title = 'SEO Draft';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema(NewProductDraftResource::seoDraftFormSchema($this->getOwnerRecord()));
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
            Tables\Actions\CreateAction::make()
                ->visible(fn (): bool => filled(trim((string) ($this->getOwnerRecord()?->handle ?? '')))
                    && !(bool) $this->getOwnerRecord()?->styleProfiles()->exists())
                ->mutateFormDataUsing(function (array $data): array {
                    return NewProductDraftResource::normalizeSeoDraftFormData($this->getOwnerRecord(), $data);
                }),
        ])->actions([
            Tables\Actions\EditAction::make()
                ->modalHeading(function (?StyleProfile $record): string|HtmlString {
                    return NewProductDraftResource::seoDraftModalHeading($this->getOwnerRecord(), $record);
                })
                ->mutateFormDataUsing(function (array $data): array {
                    return NewProductDraftResource::normalizeSeoDraftFormData($this->getOwnerRecord(), $data);
                }),
            Tables\Actions\DeleteAction::make(),
        ]);
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
