<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\NewProductDraftResource\Widgets\ShopifyMissingDraftBanner;
use App\Filament\Resources\NewProductDraftResource\Widgets\QuickCreateNewProductDraft;
use App\Models\NewProductDraft;
use App\Models\Status;
use App\Services\LocalCatalogResetService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ListNewProductDrafts extends ListRecords
{
    protected static string $resource = NewProductDraftResource::class;
    protected $listeners = ['draft-created' => 'handleDraftCreated'];

    public function updatedPaginators($page, $pageName): void
    {
        $this->dispatch('scroll-to-top');
    }

    public function handleDraftCreated(): void
    {
        $this->resetTable();
        $this->dispatch('scroll-to-top');
    }

    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        $search = filled($this->tableSearch) ? trim((string) $this->tableSearch) : '';

        if ($search === '') {
            return $query;
        }

        $terms = array_values(array_filter(
            preg_split('/\s+/', $search) ?: [],
            static fn (string $term): bool => $term !== ''
        ));

        return $query->where(function (Builder $searchQuery) use ($search, $terms): void {
            $this->applyDraftSearchMatch($searchQuery, $search);

            if (count($terms) > 1) {
                $searchQuery->orWhere(function (Builder $tokenQuery) use ($terms): void {
                    foreach ($terms as $term) {
                        $tokenQuery->where(function (Builder $termQuery) use ($term): void {
                            $this->applyDraftSearchMatch($termQuery, $term);
                        });
                    }
                });
            }
        });
    }

    private function applyDraftSearchMatch(Builder $query, string $search): void
    {
        $query
            ->where('new_product_drafts.handle', 'like', "%{$search}%")
            ->orWhere('new_product_drafts.title', 'like', "%{$search}%")
            ->orWhere('new_product_drafts.sku', 'like', "%{$search}%")
            ->orWhereHas('product.variants', fn (Builder $variantQuery): Builder => $variantQuery->where('sku', 'like', "%{$search}%"));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('resetLocalCatalog')
                ->label('Reset Local Catalog')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => app()->isLocal() && (Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false))
                ->requiresConfirmation()
                ->modalHeading('Reset local products and drafts?')
                ->modalDescription('This clears local products, drafts, rows, approvals, audits, variants, images, and related product data. Users and your login session are kept.')
                ->action(function (): void {
                    $summary = app(LocalCatalogResetService::class)->reset();

                    Notification::make()
                        ->title('Local catalog reset complete')
                        ->body("Cleared {$summary['products']} product(s) and {$summary['drafts']} draft(s). Your login session was kept.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ShopifyMissingDraftBanner::class,
            QuickCreateNewProductDraft::class,
        ];
    }

    public function getHeading(): string|HtmlString
    {
        return new HtmlString(
            '<span style="display:inline-flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">' .
            '<span style="line-height:1;">New Products</span>' .
            '<span style="color:#d1d5db;">|</span>' .
            '<span style="max-width:80rem;font-size:18px;line-height:20px;color:#1d4ed8;font-weight:400;padding-top:7px;">' .
            '<span style="font-weight:600;">In this section you can only:</span> ' .
            'Add, update, delete products, accept or reject changes from Shopify.' .
            '</span>' .
            '</span>'
        );

    }

    public function getTabs(): array
    {
        $reportCounts = $this->reportTabCounts();

        $tabs = [
            'all' => Tab::make('All'),
        ];

        foreach ($this->resolvedStatusTabs() as $key => $label) {
            $tabs[$key] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', [$key]));
        }

        if ($reportCounts['missing_seo'] > 0) {
            $tabs['missing_seo'] = Tab::make('No SEO')
                ->badge((string) $reportCounts['missing_seo'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingSeoReportFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['missing_uvp'] > 0) {
            $tabs['missing_uvp'] = Tab::make('No UVP')
                ->badge((string) $reportCounts['missing_uvp'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingUvpReportFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['shopify_clash'] > 0) {
            $tabs['shopify_clash'] = Tab::make('Shopify Clash')
                ->badge((string) $reportCounts['shopify_clash'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => self::applyShopifyClashFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['missing_siblings'] > 0) {
            $tabs['missing_siblings'] = Tab::make('No Siblings')
                ->badge((string) $reportCounts['missing_siblings'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingSiblingsReportFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['missing_complementary'] > 0) {
            $tabs['missing_complementary'] = Tab::make('No Complementary')
                ->badge((string) $reportCounts['missing_complementary'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingComplementaryProductsReportFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['needs_title_update'] > 0) {
            $tabs['needs_title_update'] = Tab::make('Needs Title')
                ->badge((string) $reportCounts['needs_title_update'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyNeedsTitleUpdateFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['good_title'] > 0) {
            $tabs['good_title'] = Tab::make('Good Title')
                ->badge((string) $reportCounts['good_title'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyGoodTitleFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        return $tabs;
    }

    /**
     * @return array<string, int>
     */
    private function reportTabCounts(): array
    {
        return [
            'missing_seo' => NewProductDraftResource::applyMissingSeoReportFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
            'missing_uvp' => NewProductDraftResource::applyMissingUvpReportFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
            'shopify_clash' => self::applyShopifyClashFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
            'missing_siblings' => NewProductDraftResource::applyMissingSiblingsReportFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
            'missing_complementary' => NewProductDraftResource::applyMissingComplementaryProductsReportFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
            'needs_title_update' => NewProductDraftResource::applyNeedsTitleUpdateFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
            'good_title' => NewProductDraftResource::applyGoodTitleFilter(
                self::applyHeaderReportScope(NewProductDraft::query())
            )->count(),
        ];
    }

    private static function applyHeaderReportScope(Builder $query): Builder
    {
        return NewProductDraftResource::applyWorkingDraftStatuses($query)
            ->whereRaw('LOWER(COALESCE(title, "")) NOT LIKE ?', ['%test%'])
            ->whereRaw('LOWER(COALESCE(handle, "")) NOT LIKE ?', ['%test%']);
    }

    /**
     * @return array<string, string>
     */
    private function resolvedStatusTabs(): array
    {
        $preferred = ['active', 'draft'];
        $excluded = ['archived', 'unlisted'];
        $resolved = [];

        $configuredStatuses = Status::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $presentStatuses = NewProductDraft::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->selectRaw('LOWER(status) as status_key, MIN(status) as status_label')
            ->groupByRaw('LOWER(status)')
            ->orderByRaw('LOWER(status)')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->status_key => (string) $row->status_label,
            ])
            ->all();

        foreach ($preferred as $status) {
            if (isset($presentStatuses[$status])) {
                $resolved[$status] = self::formatStatusTabLabel($presentStatuses[$status]);
                continue;
            }

            foreach ($configuredStatuses as $configured) {
                if (strtolower(trim((string) $configured)) === $status) {
                    $resolved[$status] = self::formatStatusTabLabel((string) $configured);
                    break;
                }
            }
        }

        foreach ($configuredStatuses as $configured) {
            $label = trim((string) $configured);
            if ($label === '') {
                continue;
            }

            $key = strtolower($label);
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $resolved[$key] = $resolved[$key] ?? self::formatStatusTabLabel($label);
        }

        foreach ($presentStatuses as $key => $label) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            $resolved[$key] = $resolved[$key] ?? self::formatStatusTabLabel($label);
        }

        return $resolved;
    }

    private static function applyShopifyClashFilter(Builder $query): Builder
    {
        return NewProductDraftResource::applyWorkingDraftStatuses($query)
            ->whereNotNull('shopify_sync_warnings')
            ->where('shopify_sync_warnings', '!=', '[]');
    }

    private static function formatStatusTabLabel(string $label): string
    {
        $trimmed = trim($label);

        return $trimmed === '' ? $label : Str::title(str_replace(['_', '-'], ' ', $trimmed));
    }
}
