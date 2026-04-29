<?php

namespace App\Filament\Resources\NewProductDraftResource\Pages;

use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\NewProductDraftResource\Widgets\ShopifyMissingDraftBanner;
use App\Filament\Resources\NewProductDraftResource\Widgets\QuickCreateNewProductDraft;
use App\Models\NewProductDraft;
use App\Models\Status;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ListNewProductDrafts extends ListRecords
{
    protected static string $resource = NewProductDraftResource::class;
    protected $listeners = ['draft-created' => '$refresh'];

    public function updatedPaginators($page, $pageName): void
    {
        $this->dispatch('scroll-to-top');
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
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingSeoReportFilter($query));
        }

        if ($reportCounts['missing_uvp'] > 0) {
            $tabs['missing_uvp'] = Tab::make('No UVP')
                ->badge((string) $reportCounts['missing_uvp'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingUvpReportFilter($query));
        }

        if ($reportCounts['missing_siblings'] > 0) {
            $tabs['missing_siblings'] = Tab::make('No Siblings')
                ->badge((string) $reportCounts['missing_siblings'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingSiblingsReportFilter($query));
        }

        if ($reportCounts['missing_complementary'] > 0) {
            $tabs['missing_complementary'] = Tab::make('No Complementary')
                ->badge((string) $reportCounts['missing_complementary'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyMissingComplementaryProductsReportFilter($query));
        }

        if ($reportCounts['needs_title_update'] > 0) {
            $tabs['needs_title_update'] = Tab::make('Needs Title')
                ->badge((string) $reportCounts['needs_title_update'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyNeedsTitleUpdateFilter($query));
        }

        if ($reportCounts['good_title'] > 0) {
            $tabs['good_title'] = Tab::make('Good Title')
                ->badge((string) $reportCounts['good_title'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => NewProductDraftResource::applyGoodTitleFilter($query));
        }

        return $tabs;
    }

    /**
     * @return array<string, int>
     */
    private function reportTabCounts(): array
    {
        return [
            'missing_seo' => NewProductDraftResource::applyMissingSeoReportFilter(NewProductDraft::query())->count(),
            'missing_uvp' => NewProductDraftResource::applyMissingUvpReportFilter(NewProductDraft::query())->count(),
            'missing_siblings' => NewProductDraftResource::applyMissingSiblingsReportFilter(NewProductDraft::query())->count(),
            'missing_complementary' => NewProductDraftResource::applyMissingComplementaryProductsReportFilter(NewProductDraft::query())->count(),
            'needs_title_update' => NewProductDraftResource::applyNeedsTitleUpdateFilter(NewProductDraft::query())->count(),
            'good_title' => NewProductDraftResource::applyGoodTitleFilter(NewProductDraft::query())->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function resolvedStatusTabs(): array
    {
        $preferred = ['active', 'draft', 'archived', 'unlisted'];
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
            $resolved[$key] = $resolved[$key] ?? self::formatStatusTabLabel($label);
        }

        foreach ($presentStatuses as $key => $label) {
            $resolved[$key] = $resolved[$key] ?? self::formatStatusTabLabel($label);
        }

        return $resolved;
    }

    private static function formatStatusTabLabel(string $label): string
    {
        $trimmed = trim($label);

        return $trimmed === '' ? $label : Str::title(str_replace(['_', '-'], ' ', $trimmed));
    }
}
