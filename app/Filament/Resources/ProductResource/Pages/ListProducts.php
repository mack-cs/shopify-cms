<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\AltTextExportStatus;
use App\Filament\Resources\ProductResource\Widgets\ProductStatusStats;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Services\DuplicateSkuAuditService;
use App\Services\DuplicateSkuCsvExporter;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageAltTextExportService;
use App\Services\LocalCatalogResetService;
use App\Services\ShopifyCollectionMembership;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    public ?string $altTextPreviewHash = null;

    protected $listeners = ['products-table-refresh' => '$refresh'];

    public function updatedPaginators($page, $pageName): void
    {
        $this->dispatch('scroll-to-top');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('downloadImageAltTextTemplate')
                    ->label('Export Alt Text CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Auth::user()?->can(PermissionEnum::ProductEdit->value) ?? false)
                    ->modalDescription('Queues a background export using the applied Products collection filter. Leave collections unfiltered for all active products. Stacks contribute only their owned first image.')
                    ->form([
                        Toggle::make('include_images')
                            ->label('Include the photographs in a ZIP')
                            ->default(false),
                    ])
                    ->action(function (array $data, ImageAltTextExportService $exports): void {
                        try {
                            $state = $this->getTableFilterState('collection') ?? [];
                            $export = $exports->request(Auth::user(), (array) ($state['values'] ?? []), (bool) ($data['include_images'] ?? false));
                            Notification::make()->title('Alt text export queued')
                                ->body('The export runs in the background. Progress and the download will appear below.')->success()->send();
                        } catch (\RuntimeException $exception) {
                            Notification::make()->title('Alt text export unavailable')->body($exception->getMessage())->danger()->send();
                        }
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
                    ])
                    ->modalSubmitActionLabel('Preview')
                    ->mountUsing(function (Form $form): void {
                        $this->altTextPreviewHash = null;
                        session()->forget('alt-text-preview.'.Auth::id());
                        $form->fill();
                    })
                    ->extraModalFooterActions(fn (Actions\Action $action): array => [
                        $action->makeModalSubmitAction('saveAltText', arguments: ['save' => true])
                            ->label('Save for review')
                            ->visible(fn (): bool => $this->altTextPreviewHash !== null),
                    ])
                    ->action(function (array $data, array $arguments, Actions\Action $action, ImageAltTextBackfillService $backfill): void {
                        $path = Storage::disk('local')->path((string) $data['file']);
                        $apply = (bool) ($arguments['save'] ?? false);
                        $hash = hash_file('sha256', $path);
                        $previewKey = 'alt-text-preview.'.Auth::id();
                        if ($apply && (! is_string($hash) || session()->get($previewKey) !== $hash)) {
                            Notification::make()->title('Preview this file before saving')->warning()->send();
                            $action->halt();
                        }
                        $result = $backfill->importFromPath($path, $apply);
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
                        if (! $apply) {
                            session()->put($previewKey, $hash);
                            $this->altTextPreviewHash = $hash;
                            $action->halt();
                        }
                        session()->forget($previewKey);
                        $this->altTextPreviewHash = null;
                    }),
            ])->label('Alt Text')->icon('heroicon-o-language')->color('gray')->button(),
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
            AltTextExportStatus::class,
            ProductStatusStats::class,
        ];
    }

    public function getHeading(): string
    {
        return 'Products';
    }

    public function getSubheading(): ?string
    {
        return 'Edit images and variants, sync approved changes to Shopify, and update URLs for redirects.';
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
            ->filter(fn ($row) => ! in_array($row->status_key, ['active', 'draft', 'archived'], true));

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
                    ->where('status', ProductPartialApprovalRequest::STATUS_APPROVED);
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

    public function appliedAltTextCollectionGids(): array
    {
        // Reads applied tableFilters, not unsubmitted deferred filter state.
        $state = $this->getTableFilterState('collection') ?? [];

        return app(ShopifyCollectionMembership::class)
            ->resolveCollectionGids((array) ($state['values'] ?? []));
    }
}
