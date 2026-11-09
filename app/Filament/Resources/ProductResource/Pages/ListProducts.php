<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\ProductStatusStats;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Services\DuplicateSkuCsvExporter;
use App\Services\DuplicateSkuAuditService;
use App\Services\ImageAltTextBackfillService;
use App\Services\StackImageSourceRepairService;
use App\Services\LocalCatalogResetService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
        return [
            Actions\Action::make('downloadImageAltTextTemplate')
                ->label('Download Alt Text CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false)
                ->modalDescription('Exports active products. Leave collections empty for every active product. A stack contributes only its own first image. Inherited component images stay on their component products.')
                ->form([
                    $this->altTextCollectionSelect('Membership is read from Shopify. Product tags are not used.'),
                    Toggle::make('include_images')
                        ->label('Include the photographs in a ZIP')
                        ->default(false),
                ])
                ->action(function (array $data, ImageAltTextBackfillService $backfill) {
                    $export = $backfill->export(
                        array_values(array_filter((array) ($data['collection_ids'] ?? []))),
                        (bool) ($data['include_images'] ?? false),
                    );
                    $exceptions = collect($export['exceptions']);
                    if ($exceptions->isNotEmpty()) {
                        Notification::make()
                            ->title('Some images were left out of the alt text CSV')
                            ->body($exceptions->take(8)->pluck('message')->implode("\n"))
                            ->warning()
                            ->persistent()
                            ->send();
                    }

                    return $export['response'];
                }),
            Actions\Action::make('backfillImageAltText')
                ->label('Import Alt Text CSV')
                ->icon('heroicon-o-language')
                ->color('info')
                ->visible(fn (): bool => Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false)
                ->modalDescription('Upload the alt text CSV. Rows match on image id. Blank alt text is skipped. Inherited stack images are rejected. Saving writes only to the CMS. Request partial approval for the product before anything can be pushed to Shopify.')
                ->form([
                    FileUpload::make('file')
                        ->label('Alt text CSV')
                        ->required()
                        ->disk('local')
                        ->directory('imports/image-alt-text')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/zip',
                        ]),
                    Toggle::make('apply')
                        ->label('Save alt text in the CMS')
                        ->helperText('Leave this off to preview the file. Saving does not update Shopify.')
                        ->default(false),
                ])
                ->action(function (array $data, ImageAltTextBackfillService $backfill): void {
                    $path = Storage::disk('local')->path((string) $data['file']);
                    $result = $backfill->importFromPath($path, (bool) ($data['apply'] ?? false));
                    $failed = collect($result['failed'])->take(8)->map(
                        fn (array $row): string => "Row {$row['row']}: {$row['reason']}"
                    )->implode("\n");
                    $mode = $result['applied'] ? 'Saved for review' : 'Preview';
                    $body = "{$mode}. Pending review {$result['updated']}. Unchanged {$result['unchanged']}. Blank {$result['skipped_blank']}. Inherited stack rows {$result['excluded']}. Failed ".count($result['failed']).'.';
                    if ($failed !== '') {
                        $body .= "\n".$failed;
                    }

                    $notification = Notification::make()
                        ->title($result['applied'] ? 'Alt text saved for review' : 'Alt text import preview')
                        ->body($body)
                        ->persistent();
                    $result['failed'] === [] ? $notification->success() : $notification->warning();
                    $notification->send();
                }),
            Actions\Action::make('pushApprovedImageAltText')
                ->label('Push Approved Alt Text')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('primary')
                ->visible(fn (): bool => Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false)
                ->requiresConfirmation()
                ->modalDescription('Sends alt text that was approved through partial approval. This does not approve anything. Photographs, prices, inventory, and image order are not changed. Component alt text is copied only to verified inherited stack images during this push.')
                ->form([
                    $this->altTextCollectionSelect('Leave this empty to push every approved alt text on an active product.'),
                ])
                ->action(function (array $data, ImageAltTextBackfillService $backfill): void {
                    $result = $backfill->publishApproved(array_values(array_filter((array) ($data['collection_ids'] ?? []))));
                    $failed = collect($result['shopify_failed'])->take(8)->map(
                        fn (array $row): string => "Image {$row['image_id']}: {$row['reason']}"
                    )->implode("\n");
                    $body = "Published {$result['published']}. Inherited copies {$result['propagated']}. Failed ".count($result['shopify_failed']).'.';
                    if ($failed !== '') {
                        $body .= "\n".$failed;
                    }
                    $notification = Notification::make()
                        ->title('Approved alt text push complete')
                        ->body($body)
                        ->persistent();
                    $result['shopify_failed'] === [] ? $notification->success() : $notification->warning();
                    $notification->send();
                }),
            Actions\Action::make('retryImageAltText')
                ->label('Retry Failed Alt Text')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false)
                ->requiresConfirmation()
                ->modalDescription('Retries alt text Shopify did not confirm. The approved CMS alt text is kept. Image files are not uploaded again.')
                ->form([
                    $this->altTextCollectionSelect('Leave this empty to retry every failed alt text push on an active product.'),
                ])
                ->action(function (array $data, ImageAltTextBackfillService $backfill): void {
                    $result = $backfill->retryFailed(array_values(array_filter((array) ($data['collection_ids'] ?? []))));
                    $failed = collect($result['shopify_failed'])->take(8)->map(
                        fn (array $row): string => "Image {$row['image_id']}: {$row['reason']}"
                    )->implode("\n");
                    $notification = Notification::make()
                        ->title('Failed alt text retry complete')
                        ->body("Retried {$result['retried']}. Still failing ".count($result['shopify_failed']).($failed !== '' ? "\n".$failed : ''))
                        ->persistent();
                    $result['shopify_failed'] === [] ? $notification->success() : $notification->warning();
                    $notification->send();
                }),
            Actions\Action::make('repairStackImageSources')
                ->label('Repair Stack Image Sources')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn (): bool => Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false)
                ->modalDescription('Links existing stack photographs to their component images. It does not change alt text, prices, inventory, image order, or upload photographs. Alt text reaches Shopify only through Push Approved Alt Text. Leave the save switch off to preview.')
                ->form([
                    Select::make('stack_ids')
                        ->label('Stacks')
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->options(function (): array {
                            return Product::query()->activeStatus()->with(['variants' => fn ($query) => $query->orderBy('id')])->orderBy('id')->get()
                                ->filter(fn (Product $product): bool => $product->isStackProduct())
                                ->mapWithKeys(fn (Product $product): array => [
                                    $product->id => trim((string) ($product->variants->first()?->sku ?? '#'.$product->id)).' — '.$product->title,
                                ])->all();
                        }),
                    Toggle::make('apply')
                        ->label('Save source links in the CMS')
                        ->helperText('Off previews the links and does not write.')
                        ->default(false),
                ])
                ->action(function (array $data, StackImageSourceRepairService $repair): void {
                    $apply = (bool) ($data['apply'] ?? false);
                    $result = $repair->review(
                        array_values(array_map('intval', (array) ($data['stack_ids'] ?? []))),
                        $apply,
                    );
                    $blocked = collect($result['stacks'])->filter(fn (array $stack): bool => ($stack['blocked'] ?? []) !== []);
                    $ready = collect($result['stacks'])->filter(fn (array $stack): bool => ($stack['mode'] ?? '') === 'metadata');
                    $lines = $blocked->take(8)->map(fn (array $stack): string => ($stack['sku'] !== '' ? $stack['sku'] : '#'.$stack['product_id']).': '.$stack['blocked'][0])->implode("\n");
                    $body = ($apply ? 'Saved' : 'Preview').'. Ready '.$ready->count().'. Blocked '.$blocked->count().'.';
                    if ($lines !== '') {
                        $body .= "\n".$lines;
                    }
                    $notification = Notification::make()
                        ->title($apply ? 'Stack image source repair saved' : 'Stack image source preview')
                        ->body($body)
                        ->persistent();
                    $blocked->isEmpty() ? $notification->success() : $notification->warning();
                    $notification->send();
                }),
            Actions\Action::make('exportDuplicateSkus')
                ->label('Export Duplicate SKUs')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->tooltip('Download every product and variant sharing a SKU with another product.')
                ->action(fn (DuplicateSkuCsvExporter $exporter) => $exporter->download()),
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

    protected function getTablePollingInterval(): ?string
    {
        return $this->isSyncRunning() ? '5s' : null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
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
        $statusCounts = $this->statusTabCounts();
        $archivedDuplicateIds = app(DuplicateSkuAuditService::class)->conflictingProductIds('archived');
        $tabs = [
            'all' => Tab::make('All'),
        ];

        foreach (['active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived'] as $key => $label) {
            if (($statusCounts[$key] ?? 0) <= 0) {
                continue;
            }

            $tabs[$key] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', [$key]));
        }

        $tabs['archived_duplicate_skus'] = Tab::make('Archived Duplicate SKUs')
            ->badge((string) count($archivedDuplicateIds))
            ->badgeColor($archivedDuplicateIds === [] ? 'gray' : 'danger')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereKey($archivedDuplicateIds));

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

        if ($reportCounts['partially_approved'] > 0) {
            $tabs['partially_approved'] = Tab::make('Partially Approved')
                ->badge((string) $reportCounts['partially_approved'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => self::applyPartiallyApprovedFilter($query));
        }

        if ($reportCounts['approved'] > 0) {
            $tabs['approved'] = Tab::make('Approved')
                ->badge((string) $reportCounts['approved'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => self::applyApprovedFilter($query));
        }

        if ($reportCounts['synced'] > 0) {
            $tabs['synced'] = Tab::make('Synced')
                ->badge((string) $reportCounts['synced'])
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => self::applySyncedFilter($query));
        }

        if ($reportCounts['pending_approval'] > 0) {
            $tabs['pending_approval'] = Tab::make('Pending Approval')
                ->badge((string) $reportCounts['pending_approval'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => self::applyPendingApprovalFilter($query));
        }

        if ($reportCounts['missing_image_alt_text'] > 0) {
            $tabs['missing_image_alt_text'] = Tab::make('No Alt Text')
                ->badge((string) $reportCounts['missing_image_alt_text'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->activeMissingImageAltText());
        }

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
            'partially_approved' => self::applyPartiallyApprovedFilter(Product::query())->count(),
            'approved' => self::applyApprovedFilter(Product::query())->count(),
            'synced' => self::applySyncedFilter(Product::query())->count(),
            'pending_approval' => self::applyPendingApprovalFilter(Product::query())->count(),
            'missing_image_alt_text' => Product::query()->activeMissingImageAltText()->count(),
            'needs_title_update' => ProductResource::applyNeedsTitleUpdateFilter(
                self::applyHeaderReportScope(Product::query())
            )->count(),
            'good_title' => ProductResource::applyGoodTitleFilter(
                self::applyHeaderReportScope(Product::query())
            )->count(),
        ];
    }

    private function statusTabCounts(): array
    {
        return Product::query()
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->selectRaw('LOWER(status) as status_key, COUNT(*) as total')
            ->groupByRaw('LOWER(status)')
            ->pluck('total', 'status_key')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    private static function applyHeaderReportScope(Builder $query): Builder
    {
        return self::applyWorkflowStatusScope($query)
            ->whereRaw('LOWER(COALESCE(title, "")) NOT LIKE ?', ['%test%'])
            ->whereRaw('LOWER(COALESCE(handle, "")) NOT LIKE ?', ['%test%']);
    }

    private static function applyWorkflowStatusScope(Builder $query): Builder
    {
        return $query->whereIn(\DB::raw('LOWER(status)'), ['active', 'draft']);
    }

    private static function applyPartiallyApprovedFilter(Builder $query): Builder
    {
        return self::applyWorkflowStatusScope($query)
            ->whereHas('partialApprovalRequests', function (Builder $sub): void {
                $sub->whereColumn('approval_version', 'products.approval_version')
                    ->where('status', \App\Models\ProductPartialApprovalRequest::STATUS_APPROVED);
            })
            ->whereRaw(
                '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) < 2'
            );
    }

    private static function applyApprovedFilter(Builder $query): Builder
    {
        return self::applyWorkflowStatusScope($query)
            ->whereRaw(
                '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) >= 2'
            );
    }

    private static function applySyncedFilter(Builder $query): Builder
    {
        return self::applyWorkflowStatusScope($query)
            ->whereNotNull('last_synced_at')
            ->whereColumn('updated_at', '<=', 'last_synced_at');
    }

    private static function applyPendingApprovalFilter(Builder $query): Builder
    {
        return self::applyWorkflowStatusScope($query)
            ->whereRaw(
                '(select count(distinct user_id) from approvals where approvals.product_id = products.id and approvals.approval_version = products.approval_version) = 1'
            );
    }

    private function isSyncRunning(): bool
    {
        $status = Import::query()
            ->where('is_current', true)
            ->value('status');

        return is_string($status) && strtolower(trim($status)) === 'processing';
    }

    private function altTextCollectionSelect(string $helperText): Select
    {
        return Select::make('collection_ids')
            ->label('Shopify collections')
            ->multiple()
            ->searchable()
            ->options(fn (): array => ShopifyCollection::query()
                ->whereNotNull('shopify_id')
                ->orderBy('title')
                ->pluck('title', 'shopify_id')
                ->all())
            ->helperText($helperText);
    }
}
