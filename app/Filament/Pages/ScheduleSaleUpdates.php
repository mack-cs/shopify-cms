<?php

namespace App\Filament\Pages;

use App\Enums\RolesEnum;
use App\Models\SaleImportBatch;
use App\Models\SaleImportItem;
use App\Models\SaleProductUpdate;
use App\Models\ScheduledJob;
use App\Services\AdminNotification;
use App\Services\SaleProductSchedulingService;
use Carbon\Carbon;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ScheduleSaleUpdates extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Product Data';
    protected static ?string $navigationLabel = 'Schedule Sale Updates';
    protected static ?int $navigationSort = 9;
    protected static string $view = 'filament.pages.schedule-sale-updates';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public function mount(): void
    {
        $service = app(SaleProductSchedulingService::class);

        $this->form->fill([
            'scheduled_at' => now($service->timezone())->addDay()->startOfDay()->format('Y-m-d H:i:s'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Approved Sale Products')
                    ->schema([
                        Placeholder::make('summary')
                            ->label('')
                            ->content(fn (): HtmlString => new HtmlString($this->summaryHtml())),
                        Placeholder::make('approved_preview')
                            ->label('')
                            ->content(fn (): HtmlString => new HtmlString($this->approvedUpdatesHtml())),
                    ]),
                Section::make('Create Scheduled Sale Job')
                    ->schema([
                        DateTimePicker::make('scheduled_at')
                            ->label('Execution date and time')
                            ->seconds(false)
                            ->required()
                            ->helperText(fn (): string => 'Times are interpreted as ' . app(SaleProductSchedulingService::class)->timezone() . '.'),
                        Actions::make([
                            Action::make('scheduleSaleJob')
                                ->label('Schedule Approved Sale Updates')
                                ->icon('heroicon-o-clock')
                                ->color('primary')
                                ->requiresConfirmation()
                                ->modalHeading('Schedule approved sale updates?')
                                ->modalDescription('Only sale-approved products are included. The job updates Shopify sale tags and variant sale prices at the scheduled time.')
                                ->disabled(function (): bool {
                                    $service = app(SaleProductSchedulingService::class);

                                    return !$service->tablesReady() || $service->approvedCount() === 0;
                                })
                                ->action(function (SaleProductSchedulingService $service): void {
                                    $this->scheduleSaleJob($service);
                                }),
                        ]),
                    ])
                    ->columns(1),
                Section::make('Recent Sale Imports')
                    ->schema([
                        Placeholder::make('recent_imports')
                            ->label('')
                            ->content(fn (): HtmlString => new HtmlString($this->recentImportsHtml())),
                    ]),
                Section::make('Recent Scheduled Jobs')
                    ->schema([
                        Placeholder::make('recent_jobs')
                            ->label('')
                            ->content(fn (): HtmlString => new HtmlString($this->recentJobsHtml())),
                    ]),
            ]);
    }

    public function scheduleSaleJob(SaleProductSchedulingService $service): void
    {
        if (!static::canAccess()) {
            AdminNotification::send(Notification::make()->title('Super Admin required')->danger());
            return;
        }

        $state = $this->form->getState();
        $timezone = $service->timezone();
        $scheduledAt = Carbon::parse((string) ($state['scheduled_at'] ?? ''), $timezone);

        try {
            $job = $service->createSaleJob($scheduledAt, Auth::id());

            AdminNotification::send(
                Notification::make()
                    ->title('Sale update scheduled')
                    ->body("Scheduled job #{$job->id} with {$job->total_items} product(s) for " . $scheduledAt->format('Y-m-d H:i') . " {$timezone}.")
                    ->success()
            );
        } catch (\Throwable $e) {
            AdminNotification::send(
                Notification::make()
                    ->title('Sale update scheduling failed')
                    ->body($e->getMessage())
                    ->danger()
            );
        }
    }

    private function summaryHtml(): string
    {
        $service = app(SaleProductSchedulingService::class);
        if (!$service->tablesReady()) {
            return $this->missingTablesHtml();
        }

        $pending = $service->pendingCount();
        $approved = $service->approvedCount();

        return <<<HTML
<div style="display:flex;gap:12px;flex-wrap:wrap;font-size:14px;">
    <div><strong>{$pending}</strong> pending sale approval</div>
    <div><strong>{$approved}</strong> approved for scheduling</div>
</div>
HTML;
    }

    private function approvedUpdatesHtml(): string
    {
        if (!app(SaleProductSchedulingService::class)->tablesReady()) {
            return '<div class="text-sm text-gray-500">Run php artisan migrate to create the sale scheduling tables.</div>';
        }

        $updates = SaleProductUpdate::query()
            ->with(['product:id,handle,title', 'variant:id,sku,price,compare_at_price'])
            ->approvedForScheduling()
            ->latest('approved_at')
            ->limit(25)
            ->get();

        if ($updates->isEmpty()) {
            return '<div class="text-sm text-gray-500">No sale-approved products are waiting to be scheduled.</div>';
        }

        $rows = $updates->map(function (SaleProductUpdate $update): string {
            $product = e((string) ($update->product?->handle ?: $update->product?->title ?: 'Product #' . $update->product_id));
            $sku = e($update->sku);
            $current = e((string) ($update->current_price ?? $update->variant?->price ?? '-'));
            $sale = e((string) $update->sale_price);
            $compare = e((string) $update->compare_at_price);
            $tags = e((string) $update->prepared_tags);

            return <<<HTML
<tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$product}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$sku}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$current}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$sale}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$compare}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$tags}</td>
</tr>
HTML;
        })->implode('');

        $count = SaleProductUpdate::query()->approvedForScheduling()->count();
        $note = $count > 25 ? '<div style="margin-top:8px;font-size:12px;color:#6b7280;">Showing the latest 25 approved sale updates.</div>' : '';

        return <<<HTML
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Product</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">SKU</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Current</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Sale</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Compare-at</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Prepared tags</th>
            </tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>
    {$note}
</div>
HTML;
    }

    private function recentJobsHtml(): string
    {
        if (!app(SaleProductSchedulingService::class)->tablesReady()) {
            return '<div class="text-sm text-gray-500">Run php artisan migrate to create the sale scheduling tables.</div>';
        }

        $jobs = ScheduledJob::query()
            ->where('type', ScheduledJob::TYPE_SALE_PRODUCT_UPDATE)
            ->latest('created_at')
            ->limit(10)
            ->get();

        if ($jobs->isEmpty()) {
            return '<div class="text-sm text-gray-500">No sale jobs have been scheduled yet.</div>';
        }

        $timezone = app(SaleProductSchedulingService::class)->timezone();
        $rows = $jobs->map(function (ScheduledJob $job) use ($timezone): string {
            $scheduled = e($job->scheduled_at?->timezone($timezone)->format('Y-m-d H:i') ?? '-');
            $status = e(str_replace('_', ' ', $job->status));
            $counts = e("{$job->succeeded_items}/{$job->total_items} succeeded, {$job->failed_items} failed");
            $error = e((string) ($job->error_summary ?? ''));

            return <<<HTML
<tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">#{$job->id}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$scheduled}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$status}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$counts}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$error}</td>
</tr>
HTML;
        })->implode('');

        return <<<HTML
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Job</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Scheduled</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Status</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Results</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Errors</th>
            </tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>
</div>
HTML;
    }

    private function recentImportsHtml(): string
    {
        if (!app(SaleProductSchedulingService::class)->tablesReady()) {
            return '<div class="text-sm text-gray-500">Run php artisan migrate to create the sale scheduling tables.</div>';
        }

        $batches = SaleImportBatch::query()
            ->latest('created_at')
            ->limit(8)
            ->get();

        if ($batches->isEmpty()) {
            return '<div class="text-sm text-gray-500">No sale imports have been run yet.</div>';
        }

        $rows = $batches->map(function (SaleImportBatch $batch): string {
            $created = e($batch->created_at?->format('Y-m-d H:i') ?? '-');
            $filename = e((string) ($batch->filename ?? 'sale import'));
            $counts = e("{$batch->matched_count} matched, {$batch->unmatched_count} unmatched, {$batch->failed_count} failed");
            $unmatched = SaleImportItem::query()
                ->where('sale_import_batch_id', $batch->id)
                ->where('status', SaleImportItem::STATUS_UNMATCHED)
                ->limit(10)
                ->pluck('sku')
                ->filter()
                ->implode(', ');
            $unmatched = e($unmatched ?: '-');

            return <<<HTML
<tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">#{$batch->id}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$filename}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$counts}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$unmatched}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$created}</td>
</tr>
HTML;
        })->implode('');

        return <<<HTML
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Batch</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">File</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Counts</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Unmatched SKUs</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Imported</th>
            </tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>
</div>
HTML;
    }

    private function missingTablesHtml(): string
    {
        return '<div class="text-sm text-amber-700">Sale scheduling tables are not installed yet. Run <code>php artisan migrate</code>, then reload this page.</div>';
    }
}
