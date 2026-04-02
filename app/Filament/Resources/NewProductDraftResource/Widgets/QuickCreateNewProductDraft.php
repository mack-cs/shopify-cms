<?php

namespace App\Filament\Resources\NewProductDraftResource\Widgets;

use App\Models\NewProductDraft;
use App\Models\Variant;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class QuickCreateNewProductDraft extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.widgets.quick-create-new-product-draft';

    protected int|string|array $columnSpan = 'full';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(24)->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(['default' => 24, 'xl' => 5]),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->maxLength(255)
                        ->rules([
                            function () {
                                return function (string $attribute, $value, $fail): void {
                                    $sku = trim((string) $value);
                                    if ($sku === '') {
                                        return;
                                    }

                                    $draftQuery = NewProductDraft::query()->where('sku', $sku);
                                    if ($draftQuery->exists() || Variant::where('sku', $sku)->exists()) {
                                        $fail('SKU must be unique across new products and existing products.');
                                    }
                                };
                            },
                        ])
                        ->columnSpan(['default' => 24, 'xl' => 4]),
                    Forms\Components\FileUpload::make('image_path')
                        ->label('Primary Image')
                        ->disk('public')
                        ->directory('new-product-images')
                        ->preserveFilenames()
                        ->getUploadedFileNameForStorageUsing(function ($file): string {
                            $disk = Storage::disk('public');
                            $directory = 'new-product-images';
                            $original = $file->getClientOriginalName();
                            $name = pathinfo($original, PATHINFO_FILENAME);
                            $ext = strtolower($file->getClientOriginalExtension());
                            $ext = $ext !== '' ? ".{$ext}" : '';

                            $candidate = "{$name}{$ext}";
                            $path = "{$directory}/{$candidate}";
                            $suffix = 1;

                            while ($disk->exists($path)) {
                                $candidate = "{$name}-{$suffix}{$ext}";
                                $path = "{$directory}/{$candidate}";
                                $suffix++;
                            }

                            return $candidate;
                        })
                        ->image()
                        ->imageEditor()
                        ->imagePreviewHeight('90')
                        ->maxSize(5120)
                        ->columnSpan(['default' => 24, 'xl' => 9]),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('create')
                            ->label('Add Draft')
                            ->color('success')
                            ->extraAttributes(['style' => 'white-space: nowrap;'])
                            ->action(fn () => $this->createDraft()),
                    ])->columnSpan(['default' => 24, 'xl' => 3])->alignEnd(),
                ]),
            ])
            ->statePath('data');
    }

    public function createDraft(): void
    {
        $state = $this->form->getState();

        $draft = NewProductDraft::create([
            'title' => $state['title'],
            'sku' => $state['sku'] ?? null,
            'image_path' => $state['image_path'] ?? null,
            'status' => 'draft',
            'variant_inventory_policy' => 'deny',
            'variant_fulfillment_service' => 'manual',
            'batch' => 'batch' . now()->format('Ymd'),
            'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
            'created_by' => Auth::id(),
        ]);

        $this->form->fill();

        Notification::make()
            ->title('Draft created')
            ->body("{$draft->title} was added.")
            ->success()
            ->send();

        $this->dispatch('draft-created');
    }
}
