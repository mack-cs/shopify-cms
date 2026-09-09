<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Jobs\PushShopYourVibe;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeWorkflow;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Actions\Action;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Builder;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Throwable;

class ShopYourVibe extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Shop Your Vibe';

    protected static ?string $title = 'Shop Your Vibe';

    protected static string $view = 'filament.pages.shop-your-vibe';

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public array $parents = [];

    #[Locked]
    public ?string $activeCard = null;

    #[Locked]
    public array $images = [];

    #[Locked]
    public ?string $imageAfter = null;

    public string $search = '';

    public ?string $selectedCollectionGid = '';

    public string $collectionMode = 'existing';

    public array $newCollection = [];

    public string $productSearch = '';

    public string $imageSearch = '';

    public bool $addingParent = false;

    public bool $addingCard = false;

    public bool $addingProducts = false;

    public bool $choosingImage = false;

    public bool $confirmingPush = false;

    public array $selectedProducts = [];

    public array $cardForm = [];

    public ?string $loadError = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value])
            || ($user->can(PermissionEnum::ShopifyPushProducts->value) && $user->can(PermissionEnum::ProductEdit->value)));
    }

    public function mount(): void
    {
        $this->loadParents();
    }

    public function loadParents(bool $refresh = false): void
    {
        $this->guard();
        $this->attempt(function () use ($refresh): void {
            if ($refresh) {
                Cache::forget($this->parentCacheKey());
            }
            $this->parents = Cache::remember($this->parentCacheKey(), 300, fn () => app(ShopYourVibeShopify::class)->parents());
            $this->loadError = null;
        });
    }

    public function manage(string $gid): void
    {
        $this->guard();
        $this->attempt(function () use ($gid): void {
            $this->accept(app(ShopYourVibeWorkflow::class)->open($gid));
            $this->activeCard = null;
            $this->addingParent = false;
            $this->addingCard = false;
            $this->cardForm = [];
            $this->dispatch('close-modal', id: 'shop-your-vibe-collections');
            $this->dispatch('vibe-editor-opened');
        });
    }

    public function back(): void
    {
        $this->guard();
        $this->draftId = null;
        $this->activeCard = null;
        $this->cardForm = [];
        $this->loadParents();
    }

    public function refreshDraft(bool $discard = false): void
    {
        $this->guard();
        $this->attempt(function () use ($discard): void {
            $this->accept(app(ShopYourVibeWorkflow::class)->refresh($this->draftId, $this->revision, $discard));
            $this->activeCard = null;
            $this->cardForm = [];
            $this->confirmingPush = false;
            $this->dispatch('vibe-form-saved');
            Cache::forget($this->parentCacheKey());
        });
    }

    public function addCard(string $gid): void
    {
        $this->guard();
        $this->attempt(function () use ($gid): void {
            $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, 'add_card', ['collection_gid' => $gid]));
            $this->confirmingPush = false;
            $this->closeCollectionPicker();
            $this->dispatch('close-modal', id: 'shop-your-vibe-collections');
        });
    }

    public function openCollectionPicker(bool $forCard = false): void
    {
        $this->guard();
        abort_if($forCard && ! $this->draftId, 422);
        $this->addingParent = ! $forCard;
        $this->addingCard = $forCard;
        $this->selectedCollectionGid = '';
        $this->collectionMode = 'existing';
        $this->newCollectionForm->fill(['image_mode' => 'none']);
        $this->loadError = null;
        $this->dispatch('open-modal', id: 'shop-your-vibe-collections');
    }

    public function closeCollectionPicker(): void
    {
        $this->guard();
        $this->addingParent = false;
        $this->addingCard = false;
        $this->selectedCollectionGid = '';
        $this->collectionMode = 'existing';
        $this->newCollection = [];
        $this->resetValidation();
    }

    protected function getForms(): array
    {
        return ['collectionPickerForm', 'newCollectionForm'];
    }

    public function newCollectionForm(Form $form): Form
    {
        return $form->statePath('newCollection')->schema([
            TextInput::make('title')->label('Collection name')->required()->maxLength(255)->live(debounce: 300)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?string $old): void {
                    if (blank($get('handle')) || $get('handle') === Str::slug($old ?? '')) {
                        $set('handle', Str::slug($state ?? ''));
                    }
                }),
            TextInput::make('handle')->label('Slug (Shopify handle)')->required()->maxLength(255)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText('Automatically generated from the collection name. You can edit it; your changes will be kept. URL: /collections/your-slug'),
            Select::make('image_mode')->label('Collection image')->options([
                'none' => 'No image', 'upload' => 'Upload a new image', 'existing' => 'Choose an image from Shopify',
            ])->default('none')->required()->live(),
            FileUpload::make('upload')->label('Image')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(10240)->storeFiles(false)->required()->visible(fn (Get $get) => $get('image_mode') === 'upload'),
            Select::make('image_gid')->label('Shopify image')->searchable()->native(false)->required()
                ->visible(fn (Get $get) => $get('image_mode') === 'existing')
                ->options(fn () => $this->shopifyImageOptions(''))
                ->getSearchResultsUsing(fn (string $search) => $this->shopifyImageOptions($search))
                ->getOptionLabelUsing(function ($value): ?string {
                    $this->guard();

                    return $value ? (app(ShopYourVibeShopify::class)->image($value)['alt'] ?: $value) : null;
                }),
        ]);
    }

    protected function shopifyImageOptions(string $search): array
    {
        $this->guard();
        if (! $this->addingCard || $this->collectionMode !== 'new' || ($this->newCollection['image_mode'] ?? '') !== 'existing') {
            return [];
        }

        return collect(app(ShopYourVibeShopify::class)->images($search)['images'])
            ->mapWithKeys(fn ($image) => [$image['id'] => $image['alt'] ?: basename(parse_url($image['image']['url'], PHP_URL_PATH))])->all();
    }

    public function createCollectionVibe(): void
    {
        $this->guard();
        abort_unless($this->addingCard && $this->collectionMode === 'new' && $this->draftId, 422);
        $data = $this->newCollectionForm->getState();
        $this->attempt(function () use ($data): void {
            $input = ['title' => trim($data['title']), 'handle' => $data['handle']];
            if ($data['image_mode'] === 'upload') {
                $input['image_path'] = $data['upload']->store('shop-your-vibe', 'public');
                if (! $input['image_path']) {
                    throw new \RuntimeException('The image could not be saved. Please upload it again.');
                }
                $input['image_url'] = url(Storage::disk('public')->url($input['image_path']));
            } elseif ($data['image_mode'] === 'existing') {
                $image = app(ShopYourVibeShopify::class)->image($data['image_gid']);
                $input['image'] = $image['id'];
                $input['image_url'] = $image['image']['url'];
            }
            $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, 'create_collection', $input));
            $this->confirmingPush = false;
            $this->closeCollectionPicker();
            $this->dispatch('close-modal', id: 'shop-your-vibe-collections');
        });
    }

    public function collectionPickerForm(Form $form): Form
    {
        return $form->schema([
            Select::make('selectedCollectionGid')
                ->label('Collection')
                ->placeholder('Search and choose a collection…')
                ->searchable()
                ->native(false)
                ->live()
                ->searchDebounce(300)
                ->searchPrompt('Type a collection title or handle')
                ->noSearchResultsMessage('No eligible collections match your search.')
                ->options(fn (): array => $this->collectionOptions(''))
                ->getSearchResultsUsing(fn (string $search): array => $this->collectionOptions($search))
                ->getOptionLabelUsing(function ($value): ?string {
                    $collection = $this->collectionQuery()->where('shopify_id', $value)->first();

                    return $collection ? $collection->title.' — '.$collection->handle : null;
                }),
        ]);
    }

    protected function collectionQuery(): Builder
    {
        $this->guard();

        return ShopifyCollection::query()->whereNotNull('shopify_id')
            ->whereIn('id', ShopifyCollection::query()->selectRaw('MAX(id)')->groupBy('shopify_id'))
            ->when($this->addingParent, fn ($query) => $query->whereNotIn('shopify_id', array_column($this->parents, 'gid')));
    }

    protected function collectionOptions(string $search): array
    {
        return $this->collectionQuery()
            ->where(fn ($query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('handle', 'like', '%'.$search.'%'))
            ->orderBy('title')->limit(60)->get()
            ->mapWithKeys(fn ($collection): array => [$collection->shopify_id => $collection->title.' — '.$collection->handle])->all();
    }

    public function confirmCollectionSelection(): void
    {
        $this->guard();
        if ((! $this->addingParent && ! $this->addingCard) || blank($this->selectedCollectionGid)) {
            $this->loadError = 'Choose a collection from the dropdown first.';

            return;
        }
        if (! ShopifyCollection::where('shopify_id', $this->selectedCollectionGid)->exists()) {
            $this->loadError = 'This collection is no longer available. Choose another collection.';

            return;
        }
        if ($this->addingParent && in_array($this->selectedCollectionGid, array_column($this->parents, 'gid'), true)) {
            $this->loadError = 'This collection is already configured. Use its Manage button.';

            return;
        }
        if ($this->addingCard) {
            $this->addCard($this->selectedCollectionGid);
        } else {
            $this->manage($this->selectedCollectionGid);
        }
    }

    public function editCard(string $key): void
    {
        $this->guard();
        $this->attempt(function () use ($key): void {
            $draft = $this->draft();
            $card = collect($draft->desired['cards'])->firstWhere('key', $key);
            abort_unless($card, 404);
            $this->activeCard = $key;
            $this->cardForm = array_intersect_key($card, array_flip(['name', 'image', 'image_url', 'link']));
            if ($card['collection_gid']) {
                $this->accept(app(ShopYourVibeWorkflow::class)->loadProducts($draft->id, $this->revision, $key));
            }
            $this->addingProducts = false;
            $this->choosingImage = false;
        });
    }

    public function saveCard(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, 'edit_card',
                array_merge($this->cardForm, ['key' => $this->activeCard])));
            $this->dispatch('vibe-form-saved');
            $this->confirmingPush = false;
        });
    }

    public function removeCard(string $key): void
    {
        $this->change('remove_card', ['key' => $key]);
        if ($this->activeCard === $key) {
            $this->activeCard = null;
            $this->cardForm = [];
        }
    }

    public function removeVibeAction(): Action
    {
        return Action::make('removeVibe')->color('danger')->requiresConfirmation()
            ->modalHeading('Remove vibe')->modalSubmitActionLabel('Confirm removal')
            ->modalDescription('The selected removal will be saved to your draft and applied when you push changes to Shopify.')
            ->form([
                Radio::make('mode')->label('Removal option')->options([
                    'layout' => 'Remove from this preview layout only',
                    'permanent' => 'Permanently delete the vibe card from Shopify',
                    'collection' => 'Delete the vibe card and its collection from Shopify — keep all products',
                ])->descriptions([
                    'layout' => 'Keep the Shopify vibe card and its linked collection.',
                    'permanent' => 'Delete the Shopify vibe card. Its linked collection, products and images are kept. This cannot be undone after pushing.',
                    'collection' => 'Permanently delete the linked collection and its collection page, plus this vibe card. Every product stays in Shopify and in its other collections. Images are kept. Applied on the next push.',
                ])->default('layout')->required(),
            ])
            ->action(function (array $arguments, array $data, Action $action): void {
                $this->guard();
                $this->attempt(function () use ($arguments, $data): void {
                    $key = $arguments['key'] ?? '';
                    $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, 'remove_card', [
                        'key' => $key, 'delete_from_shopify' => in_array($data['mode'] ?? '', ['permanent', 'collection'], true),
                        'delete_collection' => ($data['mode'] ?? '') === 'collection',
                    ]));
                    if ($this->activeCard === $key) {
                        $this->activeCard = null;
                        $this->cardForm = [];
                    }
                    $this->confirmingPush = false;
                });
                if ($this->loadError) {
                    $action->halt();
                }
            });
    }

    public function reorderCards(array $keys): void
    {
        $this->change('reorder_cards', ['keys' => $keys]);
    }

    public function reorderProducts(string $gid, array $ids): void
    {
        $this->change('reorder_products', ['collection_gid' => $gid, 'ids' => $ids]);
    }

    public function enableManual(string $gid): void
    {
        $this->change('enable_manual', ['collection_gid' => $gid, 'confirmed' => true]);
    }

    public function addProducts(string $gid): void
    {
        $this->change('add_products', ['collection_gid' => $gid, 'ids' => $this->selectedProducts]);
        $this->selectedProducts = [];
        $this->addingProducts = false;
    }

    public function removeProduct(string $gid, string $productGid): void
    {
        $this->change('remove_product', ['collection_gid' => $gid, 'product_gid' => $productGid]);
    }

    public function findImages(bool $more = false): void
    {
        $this->guard();
        $this->attempt(function () use ($more): void {
            $result = app(ShopYourVibeShopify::class)->images($this->imageSearch, $more ? $this->imageAfter : null);
            $this->images = $more ? array_merge($this->images, $result['images']) : $result['images'];
            $this->imageAfter = $result['after'];
            $this->choosingImage = true;
        });
    }

    public function chooseImage(string $gid): void
    {
        $this->guard();
        $image = collect($this->images)->firstWhere('id', $gid);
        abort_unless($image, 422);
        $this->cardForm['image'] = $gid;
        $this->cardForm['image_url'] = data_get($image, 'image.url');
        $this->choosingImage = false;
        $this->saveCard();
    }

    public function reviewPush(): void
    {
        $this->guard();
        $this->confirmingPush = $this->draft()?->pending ?? false;
    }

    public function pushChanges(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            $draft = app(ShopYourVibeWorkflow::class)->queue($this->draftId, $this->revision);
            $this->accept($draft);
            try {
                PushShopYourVibe::dispatch($draft->id);
            } catch (Throwable $e) {
                $draft->update(['status' => 'failed', 'last_error' => 'The push could not be queued. Please retry.']);
                throw $e;
            }
            $this->confirmingPush = false;
            $this->dispatch('vibe-form-saved');
            Cache::forget($this->parentCacheKey());
        });
    }

    public function pollPush(): void
    {
        $this->guard();
        if ($draft = $this->draft()) {
            $this->accept($draft);
        }
    }

    private function change(string $operation, array $input): void
    {
        $this->guard();
        $this->attempt(function () use ($operation, $input): void {
            $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, $operation, $input));
            $this->confirmingPush = false;
        });
    }

    private function accept(ShopYourVibeDraft $draft): void
    {
        $this->draftId = $draft->id;
        $this->revision = $draft->revision;
    }

    private function draft(): ?ShopYourVibeDraft
    {
        return $this->draftId ? ShopYourVibeDraft::findOrFail($this->draftId) : null;
    }

    private function guard(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
            $this->loadError = null;
        } catch (Throwable $e) {
            report($e);
            $this->loadError = $e->getMessage();
            Notification::make()->title('Shop Your Vibe could not complete this action')->body($e->getMessage())->danger()->send();
        }
    }

    private function parentCacheKey(): string
    {
        return 'shop-your-vibe-parents:'.config('services.shopify.shop');
    }

    protected function getViewData(): array
    {
        $this->guard();
        $draft = $this->draft();
        $pendingDrafts = ShopYourVibeDraft::where('pending', true)->get()->keyBy('collection_gid');
        $parents = collect($this->parents)->keyBy('gid');
        foreach ($pendingDrafts as $gid => $pending) {
            if (! $parents->has($gid)) {
                $parents->put($gid, array_merge($pending->desired['parent'], ['count' => count($pending->desired['cards']), 'updated_at' => null]));
            }
        }
        $parents = $parents->filter(fn ($parent) => $this->search === '' || str_contains(strtolower($parent['title'].' '.$parent['handle']), strtolower($this->search)));
        $card = $draft ? collect($draft->desired['cards'])->firstWhere('key', $this->activeCard) : null;
        $collection = $card ? ($draft->desired['collections'][$card['collection_gid']] ?? null) : null;
        $products = collect();
        if ($this->addingProducts && $collection && $collection['membership_supported']) {
            $products = Product::with(['images', 'variants'])->whereNotNull('shopify_id')
                ->whereNotIn('shopify_id', array_column($collection['products'], 'id'))
                ->where(fn ($query) => $query->where('title', 'like', '%'.$this->productSearch.'%')
                    ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', '%'.$this->productSearch.'%')))
                ->orderBy('title')->limit(60)->get()->unique('shopify_id');
        }

        return compact('draft', 'parents', 'pendingDrafts', 'card', 'collection', 'products') + [
            'summary' => $draft && $this->confirmingPush ? app(ShopYourVibeWorkflow::class)->summary($draft) : [],
        ];
    }
}
