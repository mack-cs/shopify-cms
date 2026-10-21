<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Jobs\ReportDirectShopifyInventoryCorrectionsJob;
use App\Models\InventoryAuditEntry;
use App\Models\InventoryAuditReview;
use App\Models\InventoryOperationAudit;
use App\Services\Inventory\DirectShopifyInventoryCorrectionReport;
use App\Services\Inventory\InventoryAuditHistoryService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryAuditHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Inventory Audit';

    protected static ?string $title = 'Inventory audit history';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.inventory-audit-history';

    public bool $directCorrectionOpen = false;

    public string $auditTab = 'adjustments';

    public string $appliedFromLabel = '';

    public string $appliedUntilLabel = '';

    /** @var array<string, mixed>|null */
    protected ?array $loadedReport = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user?->can(PermissionEnum::InventoryUpdate->value) === true
            || $user?->can(PermissionEnum::AuditViewLogs->value) === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function setAuditTab(string $tab): void
    {
        $this->auditTab = $tab === 'operations' ? 'operations' : 'adjustments';
        $this->bootedInteractsWithTable();
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => InventoryOperationAudit::query()->whereRaw('1 = 0'))
            ->searchPlaceholder('Product name or SKU')
            ->columns($this->auditTab === 'adjustments' ? [
                TextColumn::make('occurred_at')->label('Date')->searchable(false),
                TextColumn::make('product')->label('Product')->searchable()->wrap(),
                TextColumn::make('sku')->label('SKU')->searchable(),
                TextColumn::make('quantity_before')->label('Before'),
                TextColumn::make('quantity_after')->label('After'),
                TextColumn::make('quantity_difference')->label('Difference'),
                TextColumn::make('source_label')->label('Source'),
                TextColumn::make('triggered_by')->label('Person')->wrap(),
                TextColumn::make('confidence_label')->label('Match')->wrap(),
            ] : [
                TextColumn::make('occurred_at')->label('Date')->searchable(false),
                TextColumn::make('product')->label('Product')->searchable()->wrap(),
                TextColumn::make('sku')->label('SKU')->searchable(),
                TextColumn::make('activity')->label('Activity')->wrap(),
                TextColumn::make('change')->label('Change'),
                TextColumn::make('source_label')->label('Source'),
                TextColumn::make('app')->label('App')->toggleable(),
                TextColumn::make('triggered_by')->label('Triggered by')->wrap(),
                TextColumn::make('confidence_label')->label('Match')->wrap(),
            ])
            ->filters([
                Filter::make('dates')
                    ->label('Dates')
                    ->form([
                        DatePicker::make('from')->label('From')->default(now()->subDays(119)->toDateString()),
                        DatePicker::make('until')->label('Until')->default(now()->toDateString()),
                    ]),
                SelectFilter::make('activity')->label('Activity')->options([
                    'received' => 'Inventory received',
                    'reserved' => 'Inventory reserved',
                    'reservation_deleted' => 'Reservation released',
                    'consumed' => 'Reserved inventory consumed',
                    'correction' => 'Inventory correction',
                    'cms_edit' => 'Quantity edited in the CMS',
                    'manual_deduction' => 'Manual stock deduction',
                    'initialized' => 'New product initialization',
                    'order_created' => 'Order created',
                    'shopify_other' => 'Other Shopify adjustment',
                ]),
                SelectFilter::make('source')->label('Source')->options([
                    'cms' => 'CMS',
                    'shopify' => 'Shopify',
                    'online_store' => 'Online Store',
                    'automation' => 'Automation',
                ]),
                Filter::make('app')->label('Shopify app')->form([
                    TextInput::make('value')->label('Shopify app'),
                ]),
                Filter::make('triggered_by')->label('CMS user or job')->form([
                    TextInput::make('value')->label('CMS user or job'),
                ]),
                Filter::make('reason')->label('Reason')->form([
                    TextInput::make('value')->label('Reason'),
                ]),
                SelectFilter::make('state')->label('Inventory state')->options([
                    'available' => 'Available',
                    'reserved' => 'Reserved',
                    'committed' => 'Committed',
                    'on_hand' => 'On hand',
                ]),
                SelectFilter::make('direction')->label('Direction')->options([
                    'positive' => 'Positive change',
                    'negative' => 'Negative change',
                ]),
                SelectFilter::make('match')->label('Match')->options([
                    'confirmed' => 'Confirmed',
                    'probable' => 'Probable',
                    'unmatched' => 'Unmatched',
                    'local' => 'Local record',
                ]),
                Filter::make('location')->label('Location')->form([
                    TextInput::make('value')->label('Location'),
                ]),
                Filter::make('reference')->label('Purchase order, receipt, or stack')->form([
                    TextInput::make('value')->label('Purchase order, receipt, or stack'),
                ]),
                SelectFilter::make('investigation')->label('Scope')->options([
                    'all' => 'All adjustments',
                    'cms' => 'CMS changes',
                    'unexplained' => 'Unexplained',
                ]),
            ])
            ->filtersFormColumns(2)
            ->actions([
                Action::make('details')
                    ->label('Details')
                    ->modalHeading(fn (InventoryAuditEntry $record): string => trim($record->activity.' '.$record->sku))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (InventoryAuditEntry $record) => view('filament.pages.inventory-audit-detail', [
                        'row' => $record->getAttributes(),
                    ])),
                Action::make('review')
                    ->label('Review')
                    ->fillForm(fn (InventoryAuditEntry $record): array => [
                        'status' => $record->review_status ?: 'needs_investigation',
                        'note' => (string) ($record->review_note ?? ''),
                    ])
                    ->form([
                        Select::make('status')->label('Investigation status')->required()->options([
                            'needs_investigation' => 'Needs investigation',
                            'reviewed' => 'Reviewed',
                            'resolved' => 'Resolved',
                        ]),
                        Textarea::make('note')->label('Note')->rows(3),
                    ])
                    ->action(function (array $data, InventoryAuditEntry $record): void {
                        InventoryAuditReview::query()->updateOrCreate(
                            ['operation_key' => $record->getKey()],
                            [
                                'status' => $data['status'],
                                'note' => trim((string) ($data['note'] ?? '')) !== '' ? trim((string) $data['note']) : null,
                                'reviewed_by' => Auth::id(),
                                'reviewed_at' => now(),
                            ],
                        );
                        $this->loadedReport = null;
                        Notification::make()->title('Investigation status saved. This does not change stock.')->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export CSV')
                    ->action(fn (): StreamedResponse => $this->export()),
                Action::make('emailDirectCorrections')
                    ->label('Email 7-day direct corrections')
                    ->action(fn () => $this->emailDirectCorrectionReport()),
            ])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading($this->auditTab === 'adjustments' ? 'No inventory adjustments matched' : 'No inventory operations matched')
            ->emptyStateDescription($this->auditTab === 'adjustments'
                ? 'This tab lists quantity adjustments from Shopify and the CMS. Receipts, reservations, and sales are on Inventory operations.'
                : 'This tab lists stack movements, supplier receipts, and other inventory workflows.');
    }

    public function getFilteredTableQuery(): Builder
    {
        return InventoryOperationAudit::query()->whereRaw('1 = 0');
    }

    public function getFilteredSortedTableQuery(): Builder
    {
        return $this->getFilteredTableQuery();
    }

    public function getTableRecords(): LengthAwarePaginator
    {
        $rows = $this->visibleRows();
        $perPage = $this->getTableRecordsPerPage();
        $perPage = is_numeric($perPage) ? (int) $perPage : 25;
        $page = max(1, $this->getTablePage());
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return (new LengthAwarePaginator(
            array_map(fn (array $row): InventoryAuditEntry => $this->entry($row), $slice),
            count($rows),
            $perPage,
            $page,
            ['pageName' => $this->getTablePaginationPageName()],
        ))->onEachSide(0);
    }

    public function getAllTableRecordsCount(): int
    {
        return count($this->visibleRows());
    }

    protected function resolveTableRecord(?string $key): ?InventoryAuditEntry
    {
        if ($key === null || $key === '') {
            return null;
        }
        foreach ($this->visibleRows() as $row) {
            if (($row['key'] ?? '') === $key) {
                return $this->entry($row);
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public function auditSummary(): array
    {
        $rows = $this->visibleRows();
        if ($this->auditTab === 'adjustments') {
            return app(InventoryAuditHistoryService::class)->adjustmentSummary($rows);
        }

        return app(InventoryAuditHistoryService::class)->summary($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function visibleRows(): array
    {
        $rows = $this->auditReport()['rows'];
        if ($this->auditTab === 'operations') {
            return $rows;
        }

        return app(InventoryAuditHistoryService::class)->adjustments($rows);
    }

    public function appliedDateRange(): string
    {
        $this->auditReport();

        return trim($this->appliedFromLabel.' through '.$this->appliedUntilLabel);
    }

    public function shopifyNotice(): ?string
    {
        $message = trim((string) ($this->auditReport()['shopify_message'] ?? ''));

        return $message !== '' ? $message : null;
    }

    public function emailDirectCorrectionReport(): void
    {
        ReportDirectShopifyInventoryCorrectionsJob::dispatch();
        $this->directCorrectionOpen = true;
        $recipient = (string) config('services.shopify.direct_inventory_correction_email', 'shonayimack@gmail.com');
        Notification::make()
            ->title('7-day direct correction report queued')
            ->body('It will be emailed to '.$recipient.' and every active user. This page does not call Shopify.')
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

    public function export(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $rows = $this->visibleRows();
        $adjustments = $this->auditTab === 'adjustments';

        return response()->streamDownload(function () use ($rows, $adjustments): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $adjustments
                ? ['Date', 'SKU', 'Product', 'Before', 'After', 'Difference', 'Source', 'Person', 'Match', 'Activity', 'Detail', 'Review']
                : ['Date', 'SKU', 'Product', 'Activity', 'Change', 'States', 'Source', 'App', 'Triggered by', 'Process', 'Reason', 'Reference', 'Match', 'Flags', 'Review']);
            foreach ($rows as $row) {
                $states = [];
                foreach ((array) ($row['states'] ?? []) as $state) {
                    if (is_array($state)) {
                        $states[] = ((int) ($state['delta'] ?? 0)).' '.($state['state'] ?? '').' ('.($state['origin'] ?? '').')';
                    }
                }
                $detail = collect($row['detail'] ?? [])->map(fn ($line): string => is_array($line) ? ($line[0].': '.$line[1]) : '')->implode(' | ');
                fputcsv($out, $adjustments ? [
                    $row['occurred_at'] ?? '',
                    $row['sku'] ?? '',
                    $row['product'] ?? '',
                    $row['quantity_before'] ?? '',
                    $row['quantity_after'] ?? '',
                    $row['quantity_difference'] ?? '',
                    $row['source_label'] ?? '',
                    $row['triggered_by'] ?? '',
                    $row['confidence_label'] ?? '',
                    $row['activity'] ?? '',
                    $detail,
                    $row['review_status'] ?? '',
                ] : [
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

    /**
     * @return array{rows:array<int, array<string, mixed>>,shopify_status:string,shopify_message:?string,truncated:bool,incomplete:bool,retrieved_at:?string,summary:array<string, int>}
     */
    protected function auditReport(): array
    {
        if ($this->loadedReport !== null) {
            return $this->loadedReport;
        }

        $dates = (array) data_get($this->tableFilters, 'dates', []);
        $from = Carbon::parse((string) ($dates['from'] ?: now()->subDays(119)->toDateString()))->startOfDay();
        $until = Carbon::parse((string) ($dates['until'] ?: now()->toDateString()))->endOfDay();
        if ($until->lt($from) || $from->diffInDays($until) > 1095) {
            $from = now()->subDays(119)->startOfDay();
            $until = now()->endOfDay();
        }
        $this->appliedFromLabel = $from->format('M j, Y');
        $this->appliedUntilLabel = $until->format('M j, Y');

        $investigation = $this->selectFilter('investigation');
        if ($investigation === 'all') {
            $investigation = '';
        }

        $report = app(InventoryAuditHistoryService::class)->search([
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'query' => trim((string) $this->tableSearch),
            'activity' => $this->selectFilter('activity'),
            'source' => $this->selectFilter('source'),
            'app' => $this->textFilter('app'),
            'triggered_by' => $this->textFilter('triggered_by'),
            'reason' => $this->textFilter('reason'),
            'state' => $this->selectFilter('state'),
            'direction' => $this->selectFilter('direction'),
            'match' => $this->selectFilter('match'),
            'location' => $this->textFilter('location'),
            'reference' => $this->textFilter('reference'),
            'investigation' => $investigation,
            'storewide' => true,
            'live_shopify' => false,
        ]);

        $needle = strtolower(trim((string) $this->tableSearch));
        if ($needle !== '') {
            $report['rows'] = array_values(array_filter(
                $report['rows'],
                fn (array $row): bool => str_contains(strtolower(implode(' ', [
                    (string) ($row['sku'] ?? ''),
                    (string) ($row['product'] ?? ''),
                ])), $needle),
            ));
            $report['summary'] = app(InventoryAuditHistoryService::class)->summary($report['rows']);
        }

        return $this->loadedReport = $report;
    }

    private function selectFilter(string $name): string
    {
        return trim((string) data_get($this->tableFilters, $name.'.value', ''));
    }

    private function textFilter(string $name): string
    {
        return trim((string) data_get($this->tableFilters, $name.'.value', ''));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function entry(array $row): InventoryAuditEntry
    {
        $record = new InventoryAuditEntry;
        $record->forceFill([
            'key' => (string) ($row['key'] ?? ''),
            'occurred_at' => (string) ($row['occurred_at'] ?? ''),
            'product' => (string) ($row['product'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'activity' => (string) ($row['activity'] ?? ''),
            'change' => (string) ($row['change'] ?? ''),
            'quantity_before' => $row['quantity_before'] ?? null,
            'quantity_after' => $row['quantity_after'] ?? null,
            'quantity_difference' => isset($row['quantity_difference']) && $row['quantity_difference'] !== null
                ? (($row['quantity_difference'] > 0 ? '+' : '').$row['quantity_difference'])
                : '',
            'source_label' => (string) ($row['source_label'] ?? ''),
            'app' => (string) ($row['app'] ?? ''),
            'triggered_by' => (string) ($row['triggered_by'] ?? ''),
            'confidence_label' => (string) ($row['confidence_label'] ?? ''),
            'review_status' => (string) ($row['review_status'] ?? ''),
            'review_note' => (string) ($row['review_note'] ?? ''),
            'detail_text' => collect($row['detail'] ?? [])->map(fn ($line): string => is_array($line) ? ($line[0].': '.$line[1]) : '')->implode("\n"),
            'states_text' => collect($row['states'] ?? [])->map(function ($state): string {
                if (! is_array($state)) {
                    return '';
                }

                return (($state['delta'] ?? 0) > 0 ? '+' : '').($state['delta'] ?? 0).' '.($state['state'] ?? '').' ('.(($state['origin'] ?? '') === 'shopify' ? 'Shopify' : 'CMS').')';
            })->filter()->implode("\n"),
            'flag_text' => implode("\n", (array) ($row['flag_labels'] ?? [])),
        ]);
        $record->exists = true;

        return $record;
    }
}
