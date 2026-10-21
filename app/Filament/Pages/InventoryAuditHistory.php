<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Models\InventoryAuditReview;
use App\Jobs\ReportDirectShopifyInventoryCorrectionsJob;
use App\Services\Inventory\DirectShopifyInventoryCorrectionReport;
use App\Services\Inventory\InventoryAuditHistoryService;
use App\Services\Inventory\ShopifyInventoryHistoryQuery;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class InventoryAuditHistory extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Inventory Audit';

    protected static ?string $title = 'Inventory audit history';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.inventory-audit-history';

    public ?array $data = [];

    public array $applied = [];

    public bool $searched = false;

    public int $pageNumber = 1;

    public ?string $selectedKey = null;

    public string $reviewStatus = 'needs_investigation';

    public string $reviewNote = '';

    public bool $directCorrectionOpen = false;

    public array $tableFilters = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->can(PermissionEnum::InventoryUpdate->value) === true
            || $user?->can(PermissionEnum::AuditViewLogs->value) === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->tableFilters = $this->emptyTableFilters();
        $this->form->fill([
            'query' => '',
            'investigation' => 'all',
        ]);
        $this->applied = array_merge($this->tableFilters, [
            'query' => '',
            'preset' => '7',
            'investigation' => 'all',
            'storewide' => true,
            'live_shopify' => false,
        ]);
        $this->searched = true;
    }

    /**
     * @return array<string, string>
     */
    private function emptyTableFilters(): array
    {
        return [
            'from' => now()->subDays(6)->toDateString(),
            'until' => now()->toDateString(),
            'activity' => '',
            'source' => '',
            'app' => '',
            'triggered_by' => '',
            'reason' => '',
            'state' => '',
            'direction' => '',
            'match' => '',
            'location' => '',
            'reference' => '',
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Search inventory history')
                    ->description('Search stored records by product name or SKU. Leave it blank to include every product in the selected dates. Table filters narrow the rows already loaded.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('query')->label('Product name or SKU'),
                            Actions::make([
                                Action::make('search')->label('Search')->submit('search'),
                            ]),
                        ]),
                    ]),
            ]);
    }

    public function search(): void
    {
        $state = $this->form->getState();
        $this->applied['query'] = trim((string) ($state['query'] ?? ''));
        $this->applied['storewide'] = true;
        $this->applied['live_shopify'] = false;
        $this->data['query'] = $this->applied['query'];
        $this->searched = true;
        $this->pageNumber = 1;
        $this->selectedKey = null;
    }

    public function updatedTableFilters(): void
    {
        $fromText = trim((string) ($this->tableFilters['from'] ?? ''));
        $untilText = trim((string) ($this->tableFilters['until'] ?? ''));
        if ($fromText === '' || $untilText === '') {
            return;
        }
        $from = Carbon::parse($fromText)->startOfDay();
        $until = Carbon::parse($untilText)->endOfDay();
        if ($until->lt($from)) {
            Notification::make()->title('The end date is before the start date.')->danger()->send();

            return;
        }
        if ($from->diffInDays($until) > 1095) {
            Notification::make()->title('Keep a date range to three years or less.')->danger()->send();

            return;
        }

        $filters = $this->tableFilters;
        if (($filters['source'] ?? '') === 'cms' && trim((string) ($filters['app'] ?? '')) === '') {
            $filters['app'] = 'la-cms-access';
        }
        if (in_array($this->applied['investigation'] ?? 'all', ['cms', 'unexplained'], true) && trim((string) ($filters['app'] ?? '')) === '') {
            $filters['app'] = 'la-cms-access';
        }
        $this->applied = array_merge($this->applied, $filters, [
            'storewide' => true,
            'live_shopify' => false,
        ]);
        $this->pageNumber = 1;
        $this->selectedKey = null;
    }

    public function emailDirectCorrectionReport(): void
    {
        ReportDirectShopifyInventoryCorrectionsJob::dispatch();
        $this->directCorrectionOpen = true;
        $recipient = (string) config('services.shopify.direct_inventory_correction_email', 'shonayimack@gmail.com');
        Notification::make()
            ->title('7-day direct correction report queued')
            ->body('It will be emailed to '.$recipient.' and every active user. The list below is what is already stored. This page does not call Shopify.')
            ->success()
            ->send();
    }

    /**
     * @return array{from:\Illuminate\Support\Carbon,until:\Illuminate\Support\Carbon,corrections:int,groups:array<int, array{user:string,rows:array<int, array<string, mixed>>}>}
     */
    public function directCorrectionPreview(): array
    {
        return app(DirectShopifyInventoryCorrectionReport::class)->preview();
    }

    public function recheckShopify(): void
    {
        app(ShopifyInventoryHistoryQuery::class)->forgetAccessDenial();
        $this->selectedKey = null;
    }

    public function applyPreset(string $preset): void
    {
        $until = now();
        $from = match ($preset) {
            'today' => now(),
            'yesterday' => now()->subDay(),
            '30' => now()->subDays(29),
            '90' => now()->subDays(89),
            '180' => now()->subDays(179),
            default => now()->subDays(6),
        };
        if ($preset === 'yesterday') {
            $until = now()->subDay();
        }
        $this->data['preset'] = $preset;
        $this->tableFilters['from'] = $from->toDateString();
        $this->tableFilters['until'] = $until->toDateString();
        if ($this->searched) {
            $this->applied['from'] = $this->tableFilters['from'];
            $this->applied['until'] = $this->tableFilters['until'];
            $this->applied['preset'] = $preset;
            $this->applied['live_shopify'] = false;
            $this->pageNumber = 1;
            $this->selectedKey = null;
        }
    }

    public function setInvestigation(string $investigation): void
    {
        if (! in_array($investigation, ['all', 'cms', 'unexplained'], true)) {
            return;
        }
        $this->data['investigation'] = $investigation;
        if ($this->searched) {
            $this->applied['investigation'] = $investigation;
            $typedApp = trim((string) ($this->tableFilters['app'] ?? ''));
            if (in_array($investigation, ['cms', 'unexplained'], true) && $typedApp === '') {
                $this->tableFilters['app'] = 'la-cms-access';
                $this->applied['app'] = 'la-cms-access';
            }
            if ($investigation === 'all') {
                $this->applied['app'] = $typedApp;
                if (($this->tableFilters['source'] ?? '') === 'cms' && $this->applied['app'] === '') {
                    $this->applied['app'] = 'la-cms-access';
                }
            }
            $this->pageNumber = 1;
            $this->selectedKey = null;
        }
    }

    public function openSku(string $sku): void
    {
        $sku = trim($sku);
        if ($sku === '') {
            return;
        }
        $this->data['query'] = $sku;
        $this->applied['query'] = $sku;
        $this->applied['storewide'] = true;
        $this->applied['live_shopify'] = false;
        $this->searched = true;
        $this->pageNumber = 1;
        $this->selectedKey = null;
    }

    public function show(string $key): void
    {
        $this->selectedKey = $key;
        $review = InventoryAuditReview::query()->where('operation_key', $key)->first();
        $this->reviewStatus = (string) ($review->status ?? 'needs_investigation');
        $this->reviewNote = (string) ($review->note ?? '');
    }

    public function saveReview(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->selectedKey === null || ! in_array($this->reviewStatus, ['reviewed', 'needs_investigation', 'resolved'], true)) {
            return;
        }

        InventoryAuditReview::query()->updateOrCreate(
            ['operation_key' => $this->selectedKey],
            [
                'status' => $this->reviewStatus,
                'note' => trim($this->reviewNote) !== '' ? trim($this->reviewNote) : null,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ],
        );
        Notification::make()->title('Investigation status saved.')->success()->send();
    }

    public function export()
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->searched) {
            return null;
        }
        $report = app(InventoryAuditHistoryService::class)->search($this->applied);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'SKU', 'Product', 'Activity', 'Change', 'States', 'Source', 'App', 'Triggered by', 'Process', 'Reason', 'Reference', 'Match', 'Flags', 'Review']);
            foreach ($report['rows'] as $row) {
                $states = [];
                foreach ((array) ($row['states'] ?? []) as $state) {
                    if (is_array($state)) {
                        $states[] = ((int) ($state['delta'] ?? 0)).' '.($state['state'] ?? '').' ('.($state['origin'] ?? '').')';
                    }
                }
                fputcsv($out, [
                    $row['occurred_at'] ?? '',
                    $row['sku'] ?? '',
                    $row['product'] ?? '',
                    $row['activity'] ?? '',
                    $row['change'] ?? '',
                    implode('; ', $states),
                    $row['source_label'] ?? '',
                    $row['app'] ?? '',
                    $row['triggered_by'] ?? '',
                    $row['process'] ?? '',
                    $row['reason'] ?? '',
                    implode(' ', (array) ($row['identifiers'] ?? [])),
                    $row['confidence_label'] ?? '',
                    implode(' | ', (array) ($row['flag_labels'] ?? [])),
                    $row['review_status'] ?? '',
                ]);
            }
            fclose($out);
        }, 'inventory-audit.csv');
    }

    public function previousPage(): void
    {
        $this->pageNumber = max(1, $this->pageNumber - 1);
        $this->selectedKey = null;
    }

    public function nextPage(): void
    {
        $this->pageNumber++;
        $this->selectedKey = null;
    }

    /**
     * @return array{rows:array<int, array<string, mixed>>,shopify_status:string,shopify_message:?string,truncated:bool,page_rows:array<int, array<string, mixed>>,selected:?array<string, mixed>,page_count:int}
     */
    public function report(): array
    {
        if (! $this->searched) {
            return [
                'rows' => [],
                'page_rows' => [],
                'selected' => null,
                'shopify_status' => 'skipped',
                'shopify_message' => null,
                'truncated' => false,
                'incomplete' => false,
                'retrieved_at' => null,
                'summary' => ['operations' => 0, 'cms_operations' => 0, 'shopify_operations' => 0, 'unexplained' => 0, 'products' => 0, 'positive_lines' => 0, 'negative_lines' => 0],
                'page_count' => 1,
            ];
        }

        $report = app(InventoryAuditHistoryService::class)->search($this->applied);
        $perPage = 25;
        $pageCount = max(1, (int) ceil(count($report['rows']) / $perPage));
        $this->pageNumber = min($this->pageNumber, $pageCount);
        $pageRows = array_slice($report['rows'], ($this->pageNumber - 1) * $perPage, $perPage);
        $selected = null;
        foreach ($report['rows'] as $row) {
            if (($row['key'] ?? '') === $this->selectedKey) {
                $selected = $row;
                break;
            }
        }

        return $report + [
            'page_rows' => $pageRows,
            'selected' => $selected,
            'page_count' => $pageCount,
        ];
    }
}
