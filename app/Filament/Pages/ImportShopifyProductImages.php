<?php

namespace App\Filament\Pages;

use App\Enums\RolesEnum;
use App\Jobs\ImportShopifyProductImagesJob;
use App\Models\ShopifyImageImportBatch;
use App\Services\AdminNotification;
use App\Services\ShopifyImageImportService;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

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
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
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
                            ->helperText('Enter a date like 2026-07-06 or a full prefix like incoming/2026-07-06.'),
                        Placeholder::make('normalized_prefix')
                            ->label('Normalized prefix')
                            ->content(fn (): string => $this->normalizedPrefixPreview()),
                        Actions::make([
                            Action::make('runImport')
                                ->label('Run Import')
                                ->icon('heroicon-o-arrow-up-tray')
                                ->color('primary')
                                ->requiresConfirmation()
                                ->action(function (ShopifyImageImportService $service): void {
                                    $this->runImport($service);
                                }),
                        ]),
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

    public function runImport(ShopifyImageImportService $service): void
    {
        if (!static::canAccess()) {
            AdminNotification::send(
                Notification::make()
                    ->title('Super Admin required')
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

    private function normalizedPrefixPreview(): string
    {
        try {
            return app(ShopifyImageImportService::class)->normalizePrefix((string) ($this->data['s3_prefix'] ?? ''));
        } catch (\Throwable) {
            return 'Enter a folder or date.';
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
            $counts = e("{$batch->updated_count} updated / {$batch->failed_count} failed / {$batch->total_files} files");

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
