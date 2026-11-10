<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Jobs\ImportShopifyProductImagesJob;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Services\AdminNotification;
use App\Services\ShopifyImageImportService;
use App\Services\StackImageSourceRepairService;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportShopifyProductImages extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Product Data';

    protected static ?string $navigationLabel = 'Import Shopify Product Images';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.import-shopify-product-images';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(PermissionEnum::ShopifyImageImportAccess->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            's3_prefix' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Import Shopify Product Images')
                    ->schema([
                        TextInput::make('s3_prefix')
                            ->label('S3 folder or date')
                            ->placeholder('2026-07-06')
                            ->required()
                            ->live(onBlur: true)
                            ->maxLength(255)
                            ->helperText('Enter a date like 2026-07-06 or a full prefix like incoming/2026-07-06. Alt text is optional. Download a template, then upload the completed alt-text.csv to this same folder.'),
                        Placeholder::make('normalized_prefix')
                            ->label('Normalized prefix')
                            ->content(fn (): string => $this->normalizedPrefixPreview()),
                        Placeholder::make('alt_text_csv_status')
                            ->label('Alt text CSV')
                            ->content(fn (): HtmlString => $this->altTextCsvStatusHtml()),
                        Actions::make([
                            Action::make('downloadAltTextTemplate')
                                ->label('Download Alt Text Template')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->action(function (ShopifyImageImportService $service): ?StreamedResponse {
                                    return $this->downloadAltTextTemplate($service);
                                }),
                            Action::make('runImport')
                                ->label('Run Import')
                                ->icon('heroicon-o-arrow-up-tray')
                                ->color('primary')
                                ->requiresConfirmation()
                                ->action(function (ShopifyImageImportService $service): void {
                                    $this->runImport($service);
                                }),
                            Action::make('rebuildStackComponentImages')
                                ->label('Rebuild Stacks')
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalHeading('Stack image maintenance')
                                ->modalDescription('Source links only preserves photographs, alt text, prices and inventory. The full rebuild option replaces component image rows and recalculates compare-at prices for all linked stacks.')
                                ->form([
                                    Select::make('mode')->label('Operation')->options([
                                        'source_links' => 'Repair source links only — selected stacks',
                                        'rebuild' => 'Full image and compare-at rebuild — all linked stacks',
                                    ])->default('source_links')->required()->live(),
                                    Select::make('stack_ids')->label('Stacks')->multiple()->searchable()
                                        ->visible(fn (Get $get): bool => $get('mode') === 'source_links')
                                        ->required(fn (Get $get): bool => $get('mode') === 'source_links')
                                        ->options(fn (): array => Product::query()->activeStatus()->orderBy('title')->get()
                                            ->filter(fn (Product $product): bool => $product->isStackProduct())
                                            ->mapWithKeys(fn (Product $product): array => [$product->id => $product->title.' (#'.$product->id.')'])->all()),
                                ])
                                ->action(function (array $data, ShopifyImageImportService $service): void {
                                    $this->runStackComponentRebuild($service, $data);
                                }),
                        ]),
                        Placeholder::make('alt_text_csv_instructions')
                            ->label('How to add alt text')
                            ->content(fn (): HtmlString => $this->altTextInstructionsHtml()),
                    ])
                    ->columns(1),
                Section::make('Recent Image Imports')
                    ->schema([
                        Placeholder::make('recent_batches')
                            ->label('')
                            ->content(fn (): HtmlString => new HtmlString($this->recentBatchesHtml())),
                    ]),
            ]);
    }

    public function downloadAltTextTemplate(ShopifyImageImportService $service): ?StreamedResponse
    {
        if (! static::canAccess()) {
            AdminNotification::send(
                Notification::make()
                    ->title('Image import permission required')
                    ->danger()
            );

            return null;
        }

        try {
            $template = $service->altTextTemplate((string) ($this->data['s3_prefix'] ?? ''));
        } catch (\Throwable $exception) {
            AdminNotification::send(
                Notification::make()
                    ->title('Unable to create the alt text template')
                    ->body($exception->getMessage())
                    ->danger()
            );

            return null;
        }

        if (! $template['ok']) {
            AdminNotification::send(
                Notification::make()
                    ->title('Unable to Check CSV')
                    ->body($template['message'] ?? 'S3 could not be accessed or checked.')
                    ->danger()
            );

            return null;
        }

        if ($template['message'] !== null) {
            AdminNotification::send(
                Notification::make()
                    ->title('Alt text template')
                    ->body($template['message'])
                    ->warning()
            );
        }

        $csv = $template['csv'];

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, 'alt-text.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function runImport(ShopifyImageImportService $service): void
    {
        if (! static::canAccess()) {
            AdminNotification::send(
                Notification::make()
                    ->title('Image import permission required')
                    ->danger()
            );

            return;
        }

        $state = $this->form->getState();
        $prefix = $service->normalizePrefix((string) ($state['s3_prefix'] ?? ''));

        $batch = ShopifyImageImportBatch::create([
            's3_prefix' => $prefix,
            'status' => ShopifyImageImportBatch::STATUS_PENDING,
            'created_by' => Auth::id(),
        ]);

        ImportShopifyProductImagesJob::dispatch($batch->id, Auth::id());

        AdminNotification::send(
            Notification::make()
                ->title('Shopify image import queued')
                ->body("Batch #{$batch->id} will import images from {$prefix}.")
                ->success()
        );

        $this->form->fill([
            's3_prefix' => $prefix,
        ]);
    }

    public function runStackComponentRebuild(ShopifyImageImportService $service, array $options = []): void
    {
        if (! static::canAccess()) {
            AdminNotification::send(
                Notification::make()
                    ->title('Image import permission required')
                    ->danger()
            );

            return;
        }

        if (($options['mode'] ?? 'source_links') === 'source_links') {
            $this->repairSelectedSourceLinks((array) ($options['stack_ids'] ?? []));

            return;
        }

        if (($options['mode'] ?? '') !== 'rebuild') {
            return;
        }

        $stackProductIds = $service->stackProductIdsForAllLinkedComponents();
        if ($stackProductIds === []) {
            AdminNotification::send(
                Notification::make()
                    ->title('No stack component images queued')
                    ->body('No stacks with linked components were found.')
                    ->warning()
            );

            return;
        }

        $batch = ShopifyImageImportBatch::create([
            's3_prefix' => 'manual-stack-component-rebuild',
            'status' => ShopifyImageImportBatch::STATUS_PENDING,
            'total_files' => 0,
            'matched_count' => count($stackProductIds),
            'created_by' => Auth::id(),
        ]);

        RebuildShopifyStackImagesJob::dispatch($batch->id, $stackProductIds, Auth::id(), true);

        AdminNotification::send(
            Notification::make()
                ->title('Stack rebuild queued')
                ->body('Batch #'.$batch->id.' will rebuild images and compare-at prices for '.count($stackProductIds).' stack(s).')
                ->success()
        );
    }

    private function normalizedPrefixPreview(): string
    {
        try {
            return app(ShopifyImageImportService::class)->normalizePrefix((string) ($this->data['s3_prefix'] ?? ''));
        } catch (\Throwable) {
            return 'Enter a folder or date.';
        }
    }

    private function altTextCsvStatusHtml(): HtmlString
    {
        $status = app(ShopifyImageImportService::class)->altTextCsvStatus((string) ($this->data['s3_prefix'] ?? ''));
        [$label, $detail, $color] = match ($status) {
            'found' => ['Alt Text CSV Found', 'The file exists in the selected S3 folder.', '#047857'],
            'missing' => ['Alt Text CSV Missing', 'No CSV exists; image-only import is still supported.', '#b45309'],
            'unavailable' => ['Unable to Check CSV', 'S3 could not be accessed or checked.', '#b91c1c'],
            default => ['Select an S3 folder to check for alt-text.csv.', 'The status updates when the folder changes.', '#4b5563'],
        };

        return new HtmlString(
            '<div data-alt-csv-status="'.e($status).'">'
            .'<div style="font-weight:600;color:'.$color.';">'.e($label).'</div>'
            .'<div style="margin-top:2px;font-size:13px;color:#4b5563;">'.e($detail).'</div>'
            .'</div>'
        );
    }

    private function altTextInstructionsHtml(): HtmlString
    {
        $example = e(implode("\n", [
            'filename,alt_text',
            'LA0550.jpg,"Elegant gold necklace, with café detailing"',
            'LA0550-2.jpg,',
        ]));

        return new HtmlString(<<<HTML
<ol style="margin:0;padding-left:1.25rem;font-size:14px;line-height:1.6;">
    <li>Select the AWS S3 folder containing the product images.</li>
    <li>Download the Alt Text CSV Template.</li>
    <li>Complete the <code>alt_text</code> column.</li>
    <li>Upload the completed file as <code>alt-text.csv</code> into the same S3 folder.</li>
    <li>Confirm the CMS displays <strong>Alt Text CSV Found</strong>.</li>
    <li>Click <strong>Run Import</strong>.</li>
</ol>
<pre style="margin-top:12px;overflow-x:auto;border-radius:6px;background:#f9fafb;padding:12px;font-size:12px;line-height:1.5;">{$example}</pre>
HTML);
    }

    private function repairSelectedSourceLinks(array $stackIds): void
    {
        $stackIds = array_values(array_unique(array_filter(array_map('intval', $stackIds))));
        if ($stackIds === []) {
            AdminNotification::send(Notification::make()->title('Select stacks to check source links')->warning());

            return;
        }
        $batch = ShopifyImageImportBatch::create([
            's3_prefix' => 'stack-source-links-only',
            'status' => ShopifyImageImportBatch::STATUS_RUNNING,
            'matched_count' => count($stackIds), 'started_at' => now(), 'created_by' => Auth::id(),
        ]);
        try {
            $result = app(StackImageSourceRepairService::class)->review($stackIds, true);
            $blocked = collect($result['stacks'])->filter(fn (array $stack): bool => $stack['blocked'] !== []);
            $messages = $blocked->map(fn (array $stack): string => '#'.$stack['product_id'].': '.implode(' ', $stack['blocked']))->implode("\n");
            $batch->update([
                'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
                'updated_count' => count($result['stacks']) - $blocked->count(),
                'failed_count' => $blocked->count(), 'error_message' => $messages ?: null, 'completed_at' => now(),
            ]);
            AdminNotification::send(Notification::make()->title('Stack source checks complete')
                ->body('Batch #'.$batch->id.': '.$batch->updated_count.' repaired, '.$batch->failed_count.' blocked. '.$messages)
                ->status($blocked->isEmpty() ? 'success' : 'warning'));
        } catch (\Throwable $exception) {
            $batch->update(['status' => ShopifyImageImportBatch::STATUS_FAILED, 'error_message' => $exception->getMessage(),
                'failed_count' => count($stackIds), 'completed_at' => now()]);
            AdminNotification::send(Notification::make()->title('Stack source checks failed')->body($exception->getMessage())->danger());
        }
    }

    private function recentBatchesHtml(): string
    {
        $batches = ShopifyImageImportBatch::query()
            ->latest('created_at')
            ->limit(8)
            ->get();

        if ($batches->isEmpty()) {
            return '<div class="text-sm text-gray-500">No image imports have been queued yet.</div>';
        }

        $rows = $batches->map(function (ShopifyImageImportBatch $batch): string {
            $status = e(str_replace('_', ' ', $batch->status));
            $prefix = e($batch->s3_prefix);
            $created = e($batch->created_at?->format('Y-m-d H:i') ?? '-');
            $completed = e($batch->completed_at?->format('Y-m-d H:i') ?? '-');
            $counts = e($batch->resultSummary());

            return <<<HTML
<tr>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">#{$batch->id}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$prefix}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$status}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$counts}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$created}</td>
    <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{$completed}</td>
</tr>
HTML;
        })->implode('');

        return <<<HTML
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Batch</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Prefix</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Status</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Counts</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Queued</th>
                <th style="text-align:left;padding:8px;border-bottom:1px solid #d1d5db;">Completed</th>
            </tr>
        </thead>
        <tbody>{$rows}</tbody>
    </table>
</div>
HTML;
    }
}
