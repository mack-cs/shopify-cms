<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\ProductStatusStats;
use App\Filament\Resources\ProductResource\Widgets\PendingProductSyncBanner;
use App\Models\Import;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
    protected $listeners = ['products-table-refresh' => '$refresh'];

    public function updatedPaginators($page, $pageName): void
    {
        $this->dispatch('scroll-to-top');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTablePollingInterval(): ?string
    {
        return $this->isSyncRunning() ? '5s' : null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PendingProductSyncBanner::class,
            ProductStatusStats::class,
        ];
    }

    public function getHeading(): string|HtmlString
    {
        return new HtmlString(
            '<span style="display:inline-flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">' .
            '<span style="line-height:1;">Products</span>' .
            '<span style="color:#d1d5db;">|</span>' .
            '<span style="max-width:80rem;font-size:18px;line-height:20px;color:#1d4ed8;font-weight:400;padding-top:7px;">' .
            '<span style="font-weight:600;">In this section you can only:</span> ' .
            'Edit images, edit variants, sync back to Shopify, and update URLs for redirect.' .
            '</span>' .
            '</span>'
        );

    }

    public function getTabs(): array
    {
        $reportCounts = $this->reportTabCounts();
        $tabs = [
            'all' => Tab::make('All'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['active'])),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['draft'])),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['archived'])),
        ];

        $extraStatuses = Product::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->selectRaw('LOWER(status) as status_key, status as status_label')
            ->distinct()
            ->get()
            ->filter(fn ($row) => !in_array($row->status_key, ['active', 'draft', 'archived'], true));

        foreach ($extraStatuses as $row) {
            $label = Str::title(str_replace(['_', '-'], ' ', (string) $row->status_label));
            $key = $row->status_key;
            $tabs[$key] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', [$key]));
        }

        $tabs['partially_approved'] = Tab::make('Partially Approved')
            ->modifyQueryUsing(fn (Builder $query) => self::applyHeaderReportScope($query)
                ->whereHas('partialApprovalRequests', function (Builder $sub): void {
                    $sub->whereColumn('approval_version', 'products.approval_version')
                        ->where('status', \App\Models\ProductPartialApprovalRequest::STATUS_APPROVED);
                }));

        $tabs['approved'] = Tab::make('Approved')
            ->modifyQueryUsing(fn (Builder $query) => self::applyHeaderReportScope($query)
                ->whereRaw(
                    '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) >= 2'
                ));

        $tabs['pending_approval'] = Tab::make('Pending Approval')
            ->modifyQueryUsing(fn (Builder $query) => self::applyHeaderReportScope($query)
                ->whereRaw(
                    '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) = 1'
                ));

           if ($reportCounts['needs_title_update'] > 0) {
            $tabs['needs_title_update'] = Tab::make('Needs Title')
                ->badge((string) $reportCounts['needs_title_update'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => ProductResource::applyNeedsTitleUpdateFilter(
                    self::applyHeaderReportScope($query)
                ));
        }

        if ($reportCounts['good_title'] > 0) {
            $tabs['good_title'] = Tab::make('Good Title')
                ->badge((string) $reportCounts['good_title'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => ProductResource::applyGoodTitleFilter(
                    self::applyHeaderReportScope($query)
                ));
        }


        return $tabs;
    }

    private function reportTabCounts(): array
    {
        return [
            'needs_title_update' => ProductResource::applyNeedsTitleUpdateFilter(
                self::applyHeaderReportScope(Product::query())
            )->count(),
            'good_title' => ProductResource::applyGoodTitleFilter(
                self::applyHeaderReportScope(Product::query())
            )->count(),
        ];
    }

    private static function applyHeaderReportScope(Builder $query): Builder
    {
        return $query
            ->whereIn(\DB::raw('LOWER(status)'), ['active', 'draft'])
            ->whereRaw('LOWER(COALESCE(title, "")) NOT LIKE ?', ['%test%'])
            ->whereRaw('LOWER(COALESCE(handle, "")) NOT LIKE ?', ['%test%']);
    }

    private function isSyncRunning(): bool
    {
        $status = Import::query()
            ->where('is_current', true)
            ->value('status');

        return is_string($status) && strtolower(trim($status)) === 'processing';
    }
}
