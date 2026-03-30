<?php

namespace App\Filament\Resources\NewProductDraftResource\Widgets;

use App\Models\NewProductDraft;
use App\Models\Variant;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

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
                Forms\Components\Grid::make(12)->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(5),
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
                        ->columnSpan(4),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('create')
                            ->label('Add Draft')
                            ->color('success')
                            ->action(fn () => $this->createDraft()),
                    ])->columnSpan(3)->alignEnd(),
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
