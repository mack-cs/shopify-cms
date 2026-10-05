<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Jobs\PushShopYourVibeComplementaryProducts;
use App\Jobs\PushShopYourVibe;
use App\Jobs\PushShopYourVibeProductTags;
use App\Jobs\RefreshShopYourVibeDraft;
use App\Jobs\RefreshShopYourVibeParents;
use App\Models\Product;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\Variant;
use App\Services\ShopYourVibeAssignmentService;
use App\Services\ShopYourVibeComplementaryService;
use App\Services\ShopYourVibeSiblingService;
use App\Services\ShopYourVibeTagService;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeWorkflow;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Actions\Action;
use Filament\Forms\Get;
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

    public bool $newCollectionHandleOverridden = false;

    public string $productSearch = '';

    public string $assignmentProductSearch = '';

    public string $imageSearch = '';

    public bool $addingParent = false;

    public bool $addingCard = false;

    public bool $addingProducts = false;

    public bool $choosingImage = false;

    public bool $confirmingPush = false;

    public array $selectedProducts = [];

    public array $cardForm = [];

    public string $activeTab = 'products';

    #[Locked]
    public array $parentProducts = [];

    #[Locked]
    public array $vibeMappings = [];

    public ?string $managingProductGid = null;

    public array $selectedVibes = [];

    #[Locked]
    public array $originalSelectedVibes = [];

    public ?string $managingSiblingProductGid = null;

    public array $siblingOptions = [];

    public array $selectedSiblings = [];

    #[Locked]
    public array $parentSiblingOptions = [];

    public ?string $managingTagProductGid = null;

    public ?string $managingComplementaryProductGid = null;

    public array $complementarySelected = [];

    public string $complementarySearch = '';

    public array $complementarySearchResults = [];

    public array $complementaryErrors = [];

    public array $tagForm = [
        'materials_and_dimensions' => '',
        'color_string' => [],
        'jewelry_material' => [],
        'bead_colour_finish' => '',
    ];

    public array $tagOptions = [
        'materials_and_dimensions' => [],
        'color_string' => [],
        'jewelry_material' => [],
        'bead_colour_finish' => [],
    ];

    public bool $confirmingAssignments = false;

    public array $mappingForm = [];

    public array $mappingUpload = [];

    public bool $uploadingMappings = false;

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
                RefreshShopYourVibeParents::dispatch($this->parentCacheKey(), auth()->id());
                Notification::make()
                    ->title('Shopify refresh queued')
                    ->body('The collection list will refresh in the background.')
                    ->success()
                    ->send();

                return;
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
            $this->activeTab = 'products';
            $this->assignmentProductSearch = '';
            $this->loadAssignmentOverview();
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
        $this->parentProducts = [];
        $this->assignmentProductSearch = '';
        $this->vibeMappings = [];
        $this->managingProductGid = null;
        $this->managingSiblingProductGid = null;
        $this->managingTagProductGid = null;
        $this->resetComplementaryModalState();
        $this->tagForm = [
            'materials_and_dimensions' => '',
            'color_string' => [],
            'jewelry_material' => [],
            'bead_colour_finish' => '',
        ];
        $this->tagOptions = [
            'materials_and_dimensions' => [],
            'color_string' => [],
            'jewelry_material' => [],
            'bead_colour_finish' => [],
        ];
        $this->loadParents();
    }

    public function refreshDraft(bool $discard = false): void
    {
        $this->guard();
        $this->attempt(function () use ($discard): void {
            $this->activeCard = null;
            $this->cardForm = [];
            $this->confirmingPush = false;
            RefreshShopYourVibeDraft::dispatch($this->draftId, $this->revision, $discard, auth()->id());
            Notification::make()
                ->title('Shopify refresh queued')
                ->body('This Shop Your Vibe draft will reload from Shopify in the background.')
                ->success()
                ->send();
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
        if ($forCard) {
            $this->activeTab = 'vibes';
        }
        $this->addingParent = ! $forCard;
        $this->addingCard = $forCard;
        $this->selectedCollectionGid = '';
        $this->collectionMode = 'existing';
        $this->newCollectionHandleOverridden = false;
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
        $this->newCollectionHandleOverridden = false;
        $this->resetValidation();
    }

    protected function getForms(): array
    {
        return ['collectionPickerForm', 'newCollectionForm', 'mappingUploadForm', 'productTagForm'];
    }

    public function productTagForm(Form $form): Form
    {
        return $form->statePath('tagForm')->schema([
            Grid::make(2)->schema([
                Select::make('materials_and_dimensions')
                    ->label('Material & Dimensions')
                    ->placeholder('Select option')
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => $this->tagOptions['materials_and_dimensions'] ?? []),
                Select::make('bead_colour_finish')
                    ->label('Material Colour Finish')
                    ->placeholder('Select option')
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => $this->tagOptions['bead_colour_finish'] ?? []),
                Select::make('color_string')
                    ->label('Colour')
                    ->placeholder('Select option')
                    ->multiple()
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => $this->tagOptions['color_string'] ?? []),
                Select::make('jewelry_material')
                    ->label('Jewelry Material')
                    ->placeholder('Select option')
                    ->multiple()
                    ->native(false)
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => $this->tagOptions['jewelry_material'] ?? []),
            ]),
        ]);
    }

    public function mappingUploadForm(Form $form): Form
    {
        return $form->statePath('mappingUpload')->schema([
            FileUpload::make('file')
                ->label('Shop Your Vibe mapping CSV')
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                ->maxSize(5120)
                ->storeFiles(false)
                ->required(),
        ]);
    }

    public function openMappingUpload(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            if ($this->draftId) {
                $this->activeTab = 'vibes';
            }
            $this->uploadingMappings = true;
            $this->mappingUploadForm->fill();
            $this->dispatch('open-modal', id: 'bulk-vibe-mappings');
        });
    }

    public function closeMappingUpload(): void
    {
        $this->uploadingMappings = false;
        $this->mappingUpload = [];
        $this->resetValidation();
    }

    public function importMappingUpload(): void
    {
        $this->guard();
        abort_unless($this->uploadingMappings, 422);
        $data = $this->mappingUploadForm->getState();
        $this->attempt(function () use ($data): void {
            $rows = $this->readMappingCsv($data['file']);
            $updated = app(ShopYourVibeAssignmentService::class)->importMappingsGlobally($rows);
            if ($this->draftId) {
                $byId = collect($updated)->keyBy('id');
                foreach ($this->vibeMappings as &$mapping) {
                    if ($saved = $byId->get($mapping['id'])) {
                        $mapping = array_replace($mapping, $saved->toArray());
                    }
                }
                unset($mapping);
            }
            $this->closeMappingUpload();
            $this->dispatch('close-modal', id: 'bulk-vibe-mappings');
            $count = collect($updated)->pluck('shopify_collection_id')->unique()->count();
            Notification::make()->title($count.' Shop Your Vibe mappings updated')->success()->send();
        });
    }

    public function newCollectionForm(Form $form): Form
    {
        return $form->statePath('newCollection')->schema([
            TextInput::make('title')->label('Collection name')->required()->maxLength(255)
                ->extraInputAttributes([
                    'x-on:input' => "const prefix = \$el.dataset.parentHandle || ''; const titleSlug = (\$event.target.value || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-+/g, '-'); const slug = [prefix, titleSlug].filter(Boolean).join('-'); const handle = \$el.closest('[data-new-collection-form]')?.querySelector('[data-new-collection-handle]'); if (handle && (!handle.value || handle.dataset.autoSlug === 'true')) { handle.dataset.autoUpdating = 'true'; handle.value = slug; handle.dataset.autoSlug = 'true'; handle.dispatchEvent(new Event('input', { bubbles: true })); }",
                    'data-parent-handle' => $this->newCollectionParentHandle(),
                ]),
            TextInput::make('handle')->label('Slug (Shopify handle)')->required()->maxLength(255)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->extraInputAttributes([
                    'data-new-collection-handle' => true,
                    'data-auto-slug' => 'true',
                    'x-on:input' => "if (\$el.dataset.autoUpdating === 'true') { \$el.dataset.autoUpdating = 'false'; \$el.dataset.autoSlug = 'true'; } else { \$el.dataset.autoSlug = 'false'; }",
                ])
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

    public function updatedNewCollectionTitle(?string $value): void
    {
        if ($this->newCollectionHandleOverridden) {
            return;
        }

        $this->newCollection['handle'] = $this->contextualCollectionHandle((string) $value);
    }

    public function updatedNewCollectionHandle(?string $value): void
    {
        $expected = $this->contextualCollectionHandle((string) ($this->newCollection['title'] ?? ''));
        $this->newCollectionHandleOverridden = filled($value) && $value !== $expected;
    }

    private function contextualCollectionHandle(string $title): string
    {
        return collect([$this->newCollectionParentHandle(), Str::slug($title)])
            ->filter()
            ->implode('-');
    }

    private function newCollectionParentHandle(): string
    {
        $draft = $this->draft();
        if (! $draft) {
            return '';
        }

        $parent = $draft->desired['parent'] ?? $draft->snapshot['parent'] ?? [];
        $handle = trim((string) ($parent['handle'] ?? ''));
        if ($handle !== '') {
            return Str::slug($handle);
        }

        return Str::slug((string) ($parent['title'] ?? ''));
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
            $this->activeTab = 'vibes';
            $draft = $this->draft();
            $card = collect($draft->desired['cards'])->firstWhere('key', $key);
            abort_unless($card, 404);
            $this->activeCard = $key;
            $this->cardForm = array_intersect_key($card, array_flip(['name', 'image', 'image_url', 'link']));
            $mapping = collect($this->vibeMappings)->firstWhere('shopify_collection_id', $card['collection_gid']);
            $this->mappingForm = [
                'id' => $mapping['id'] ?? null,
                'membership_tag' => $mapping['membership_tag'] ?? '',
                'design_value' => $mapping['design_value'] ?? '',
                'colour_style_value' => $mapping['colour_style_value'] ?? '',
            ];
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
            if (! empty($this->mappingForm['id'])) {
                $savedMapping = app(ShopYourVibeAssignmentService::class)->configure((int) $this->mappingForm['id'], $this->mappingForm)->toArray();
                foreach ($this->vibeMappings as &$mapping) {
                    if ((int) $mapping['id'] === (int) $savedMapping['id']) {
                        $mapping = array_replace($mapping, $savedMapping);
                    }
                }
                unset($mapping);
            }
            $this->dispatch('vibe-form-saved');
            $this->confirmingPush = false;
        });
    }

    public function removeCard(string $key): void
    {
        $collectionGid = collect($this->draft()?->desired['cards'] ?? [])->firstWhere('key', $key)['collection_gid'] ?? null;
        $this->change('remove_card', ['key' => $key]);
        $this->deactivateCardMapping($collectionGid);
        if ($this->activeCard === $key) {
            $this->activeCard = null;
            $this->cardForm = [];
        }
    }

    public function removeVibeAction(): Action
    {
        return Action::make('removeVibe')->color('danger')->requiresConfirmation()
            ->modalHeading('Remove vibe')->modalSubmitActionLabel('Confirm removal')
            ->modalDescription('Choose what should happen when you push changes.')
            ->form([
                Radio::make('mode')->label('Removal option')->options([
                    'layout' => 'Remove locally from this preview',
                    'permanent' => 'Delete the Shop Your Vibe card from Shopify',
                    'collection' => 'Delete the Shop Your Vibe card and collection from Shopify',
                ])->descriptions([
                    'layout' => 'Only removes it from this main collection preview. Shopify is kept.',
                    'permanent' => 'Removes the SYV card in Shopify. The linked collection and products stay.',
                    'collection' => 'Removes the SYV card and linked Shopify collection. Products stay in Shopify.',
                ])->default('layout')->required(),
            ])
            ->action(function (array $arguments, array $data, Action $action): void {
                $this->guard();
                $this->attempt(function () use ($arguments, $data): void {
                    $key = $arguments['key'] ?? '';
                    $collectionGid = collect($this->draft()->desired['cards'])->firstWhere('key', $key)['collection_gid'] ?? null;
                    $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, 'remove_card', [
                        'key' => $key, 'delete_from_shopify' => in_array($data['mode'] ?? '', ['permanent', 'collection'], true),
                        'delete_collection' => ($data['mode'] ?? '') === 'collection',
                    ]));
                    $this->deactivateCardMapping($collectionGid);
                    if ($this->activeCard === $key) {
                        $this->activeCard = null;
                        $this->cardForm = [];
                    }
                    $this->confirmingPush = false;
                    $this->dispatch('vibe-draft-saved');
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
        if ($this->loadError) {
            return;
        }
        if ($gid === $this->draft()?->collection_gid) {
            $this->parentProducts = $this->reorderLocalProducts($this->parentProducts, $ids);
        }
        $this->skipRender();
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

    public function setActiveTab(string $tab): void
    {
        abort_unless(in_array($tab, ['products', 'vibes'], true), 422);
        $this->activeTab = $tab;
        $this->activeCard = null;
    }

    public function openProductAssignments(string $productGid): void
    {
        $this->guard();
        $product = collect($this->parentProducts)->firstWhere('id', $productGid);
        abort_unless($product, 404);
        $tags = collect($product['tags'])->map(fn ($tag) => mb_strtolower(trim($tag)));
        $this->managingProductGid = $productGid;
        $this->selectedVibes = collect($this->vibeMappings)
            ->filter(function ($mapping) use ($tags): bool {
                $membershipTag = data_get($mapping, 'membership_tag');

                return filled($membershipTag)
                    && $tags->contains(mb_strtolower(trim((string) $membershipTag)));
            })
            ->pluck('shopify_collection_id')->values()->all();
        $this->originalSelectedVibes = $this->selectedVibes;
        $this->confirmingAssignments = false;
        $this->dispatch('open-modal', id: 'manage-vibe-assignments');
    }

    public function openProductSiblings(string $productGid): void
    {
        $this->guard();
        $product = collect($this->parentProducts)->firstWhere('id', $productGid);
        abort_unless($product, 404);
        if ($this->parentSiblingOptions === []) {
            $this->parentSiblingOptions = app(ShopYourVibeSiblingService::class)
                ->optionsForParent($this->draft()?->desired['parent'] ?? []);
        }
        $this->siblingOptions = $this->parentSiblingOptions;
        $selected = app(ShopYourVibeSiblingService::class)->selectedForTags((array) ($product['tags'] ?? []), $this->siblingOptions);
        $this->managingSiblingProductGid = $productGid;
        $this->selectedSiblings = collect($selected)->pluck('tag')->values()->all();
        $this->dispatch('open-modal', id: 'manage-sibling-assignments');
    }

    public function saveProductSiblings(): void
    {
        $this->guard();
        abort_unless($this->draftId && $this->managingSiblingProductGid, 422);
        abort_unless(collect($this->parentProducts)->contains('id', $this->managingSiblingProductGid), 422);
        $this->attempt(function (): void {
            $confirmed = app(ShopYourVibeSiblingService::class)->assign(
                $this->draft()->desired['parent'] ?? [],
                $this->managingSiblingProductGid,
                $this->selectedSiblings,
            );
            foreach ($this->parentProducts as &$product) {
                if ($product['id'] === $this->managingSiblingProductGid) {
                    $product['tags'] = $confirmed['tags'];
                    $product['siblings'] = app(ShopYourVibeSiblingService::class)
                        ->selectedForTags((array) $confirmed['tags'], $this->siblingOptions);
                }
            }
            unset($product);
            $this->managingSiblingProductGid = null;
            $this->selectedSiblings = [];
            $this->siblingOptions = [];
            $this->dispatch('close-modal', id: 'manage-sibling-assignments');
            Notification::make()->title('Sibling assignments updated')->success()->send();
        });
    }

    public function openProductTags(string $productGid): void
    {
        $this->guard();
        abort_unless(collect($this->parentProducts)->contains('id', $productGid), 404);
        $this->attempt(function () use ($productGid): void {
            $service = app(ShopYourVibeTagService::class);
            $product = $service->productForGid($productGid);
            $state = $service->stateForProduct($product);

            $this->managingTagProductGid = $productGid;
            $this->tagForm = $state;
            $this->tagOptions = $service->optionsForProduct($product, $state);
            $this->productTagForm->fill($state);
            $this->dispatch('open-modal', id: 'manage-tag-assignments');
        });
    }

    public function saveProductTags(): void
    {
        $this->guard();
        abort_unless($this->draftId && $this->managingTagProductGid, 422);
        abort_unless(collect($this->parentProducts)->contains('id', $this->managingTagProductGid), 422);
        $this->attempt(function (): void {
            $this->tagForm = $this->productTagForm->getState();
            PushShopYourVibeProductTags::dispatch(
                $this->managingTagProductGid,
                $this->tagForm,
                auth()->id(),
            );

            $this->managingTagProductGid = null;
            $this->tagForm = [
                'materials_and_dimensions' => '',
                'color_string' => [],
                'jewelry_material' => [],
                'bead_colour_finish' => '',
            ];
            $this->tagOptions = [
                'materials_and_dimensions' => [],
                'color_string' => [],
                'jewelry_material' => [],
                'bead_colour_finish' => [],
            ];
            $this->dispatch('close-modal', id: 'manage-tag-assignments');
            Notification::make()
                ->title('Product metafield update queued')
                ->body('Shopify will update in the background.')
                ->success()
                ->send();
        });
    }

    public function openProductComplementary(string $productGid): void
    {
        $this->guard();
        abort_unless(collect($this->parentProducts)->contains('id', $productGid), 404);
        $this->attempt(function () use ($productGid): void {
            $service = app(ShopYourVibeComplementaryService::class);
            $state = $service->stateForProductGid($productGid);

            $this->managingComplementaryProductGid = $productGid;
            $this->complementarySelected = $state['selected'];
            $this->complementarySearch = '';
            $this->complementarySearchResults = [];
            $this->complementaryErrors = [];
            $this->dispatch('open-modal', id: 'manage-complementary-products');
        });
    }

    public function updatedComplementarySearch(): void
    {
        $this->refreshComplementarySearch();
    }

    public function addComplementaryProduct(string $productGid): void
    {
        $this->guard();
        abort_unless($this->managingComplementaryProductGid, 422);
        if ($productGid === $this->managingComplementaryProductGid) {
            $this->complementaryErrors = ['A product cannot complement itself.'];

            return;
        }
        if (collect($this->complementarySelected)->contains('id', $productGid)) {
            $this->complementaryErrors = ['This product is already selected.'];

            return;
        }

        $service = app(ShopYourVibeComplementaryService::class);
        $result = $service->addTokens(
            $this->managingComplementaryProductGid,
            collect($this->complementarySelected)->pluck('id')->all(),
            $productGid,
        );
        $this->complementarySelected = $result['selected'];
        $this->complementaryErrors = $result['errors'];
        $this->refreshComplementarySearch();
    }

    public function removeComplementaryProduct(string $productGid): void
    {
        $this->complementarySelected = collect($this->complementarySelected)
            ->reject(fn (array $product): bool => ($product['id'] ?? null) === $productGid)
            ->values()
            ->all();
        $this->refreshComplementarySelectionStatuses();
        $this->refreshComplementarySearch();
    }

    public function moveComplementaryProduct(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (! isset($this->complementarySelected[$index], $this->complementarySelected[$target])) {
            return;
        }

        $items = $this->complementarySelected;
        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        $this->complementarySelected = array_values($items);
        $this->refreshComplementarySelectionStatuses();
    }

    public function saveProductComplementary(): void
    {
        $this->guard();
        abort_unless($this->draftId && $this->managingComplementaryProductGid, 422);
        abort_unless(collect($this->parentProducts)->contains('id', $this->managingComplementaryProductGid), 422);
        $this->attempt(function (): void {
            $service = app(ShopYourVibeComplementaryService::class);
            $result = $service->saveLocal(
                $this->managingComplementaryProductGid,
                collect($this->complementarySelected)->pluck('id')->all(),
                auth()->id(),
            );

            PushShopYourVibeComplementaryProducts::dispatch($this->managingComplementaryProductGid, auth()->id());

            foreach ($this->parentProducts as &$product) {
                if ($product['id'] === $this->managingComplementaryProductGid) {
                    $product['complementary_count'] = count($result['selected']);
                }
            }
            unset($product);

            $this->resetComplementaryModalState();
            $this->dispatch('close-modal', id: 'manage-complementary-products');
            Notification::make()
                ->title('Complementary products queued')
                ->body('The full list was saved locally. Shopify will receive the first three sellable products in the background.')
                ->success()
                ->send();
        });
    }

    public function reviewProductAssignments(): void
    {
        $this->guard();
        abort_unless($this->draftId && $this->managingProductGid, 422);
        $this->confirmingAssignments = true;
        $this->dispatch('close-modal', id: 'manage-vibe-assignments');
        $this->dispatch('open-modal', id: 'confirm-vibe-assignments');
    }

    public function cancelProductAssignmentConfirmation(): void
    {
        $this->confirmingAssignments = false;
        $this->dispatch('close-modal', id: 'confirm-vibe-assignments');
        $this->dispatch('open-modal', id: 'manage-vibe-assignments');
    }

    public function saveProductAssignments(): void
    {
        $this->guard();
        abort_unless($this->draftId && $this->managingProductGid && $this->confirmingAssignments, 422);
        abort_unless(collect($this->parentProducts)->contains('id', $this->managingProductGid), 422);
        $this->attempt(function (): void {
            $confirmed = app(ShopYourVibeAssignmentService::class)->assign(
                $this->draft()->collection_gid,
                $this->managingProductGid,
                $this->selectedVibes,
            );
            foreach ($this->parentProducts as &$product) {
                if ($product['id'] === $this->managingProductGid) {
                    $product['tags'] = $confirmed['tags'];
                }
            }
            unset($product);
            $this->managingProductGid = null;
            $this->selectedVibes = [];
            $this->originalSelectedVibes = [];
            $this->confirmingAssignments = false;
            $this->dispatch('close-modal', id: 'confirm-vibe-assignments');
            $this->dispatch('close-modal', id: 'manage-vibe-assignments');
            Notification::make()->title('Shop Your Vibe assignments updated')->success()->send();
        });
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

    public function retryPush(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            $draft = $this->draft();
            if (! $draft || $draft->status !== 'pushing') {
                return;
            }

            PushShopYourVibe::dispatch($draft->id);
            Notification::make()
                ->title('Push check queued')
                ->body('Shopify confirmation is being retried. This page will update when the draft changes.')
                ->success()
                ->send();
        });
    }

    public function pollPush(): void
    {
        $this->guard();
        if ($draft = $this->draft()) {
            if ($draft->pending && $draft->status === 'pending') {
                $draft = app(ShopYourVibeWorkflow::class)->confirmCompletedPush($draft->id);
            }
            $this->accept($draft);
        }
    }

    private function change(string $operation, array $input): void
    {
        $this->guard();
        $this->attempt(function () use ($operation, $input): void {
            $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, $operation, $input));
            $this->confirmingPush = false;
            $this->dispatch('vibe-draft-saved');
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

    private function loadAssignmentOverview(bool $refreshProducts = true): void
    {
        $draft = $this->draft();
        if (! $draft) {
            return;
        }

        $service = app(ShopYourVibeAssignmentService::class);
        $shopify = app(ShopYourVibeShopify::class);
        $this->parentSiblingOptions = app(ShopYourVibeSiblingService::class)
            ->optionsForParent($draft->desired['parent'] ?? []);
        $mappings = [];
        foreach ($draft->desired['cards'] as $card) {
            $gid = $card['collection_gid'] ?? null;
            if (! $gid || str_starts_with($gid, 'new:')) {
                continue;
            }
            if (isset($mappings[$gid])) {
                continue;
            }
            $collection = $this->mappingCollectionSummary($draft, $gid, $card);
            $mapping = $service->syncMapping($draft->collection_gid, $card, $collection);
            // More than one preview card may link to the same Shopify collection.
            // Product assignment is collection-based, so show and process it only once.
            $mappings[$gid] = $mapping->toArray() + ['product_count' => $collection['product_count']];
        }
        $mappings = array_values($mappings);
        $service->deactivateMissing($draft->collection_gid, array_column($mappings, 'shopify_collection_id'));
        $this->vibeMappings = $mappings;
        if ($refreshProducts || $this->parentProducts === []) {
            $this->parentProducts = $this->decorateProductCards(
                $draft->desired['collections'][$draft->collection_gid]['products']
                    ?? $shopify->parentProducts($draft->collection_gid)
            );
        }
    }

    private function mappingCollectionSummary(ShopYourVibeDraft $draft, string $gid, array $card): array
    {
        $collection = $draft->desired['collections'][$gid]
            ?? $draft->snapshot['collections'][$gid]
            ?? null;
        if (is_array($collection)) {
            return [
                'gid' => $gid,
                'title' => $collection['title'] ?? $card['name'],
                'handle' => $collection['handle'] ?? basename((string) parse_url($card['link'] ?? '', PHP_URL_PATH)),
                'detected_membership_tag' => $collection['detected_membership_tag'] ?? null,
                'product_count' => (int) ($collection['product_count'] ?? count($collection['products'] ?? [])),
            ];
        }

        $local = ShopifyCollection::query()
            ->where('shopify_id', $gid)
            ->latest('id')
            ->first();

        return [
            'gid' => $gid,
            'title' => $local?->title ?: $card['name'],
            'handle' => $local?->handle ?: basename((string) parse_url($card['link'] ?? '', PHP_URL_PATH)),
            'detected_membership_tag' => null,
            'product_count' => 0,
        ];
    }

    private function decorateProductCards(array $products): array
    {
        $movementBySku = $this->movementClassificationsBySku($products);
        $variantInventoryBySku = $this->variantInventoryBySku($products);
        $beadColourFinishByGid = $this->beadColourFinishByGid($products);
        $complementaryCountByGid = app(ShopYourVibeComplementaryService::class)->countsByGid($products);
        $threshold = max(0, (int) config('shop_your_vibe.low_stock_threshold', 5));
        $siblingOptions = $this->parentSiblingOptions;

        return collect($products)->map(function (array $product) use ($movementBySku, $variantInventoryBySku, $beadColourFinishByGid, $complementaryCountByGid, $threshold, $siblingOptions): array {
            $inventoryTracked = $product['inventory_tracked'] ?? null;
            $quantity = $product['inventory_quantity'] ?? null;
            if (($inventoryTracked === null || $quantity === null) && filled($product['sku'] ?? null)) {
                $variant = $variantInventoryBySku[trim((string) $product['sku'])] ?? null;
                $inventoryTracked ??= $variant['inventory_tracked'] ?? null;
                $quantity ??= $variant['inventory_qty'] ?? null;
            }

            $tracked = $inventoryTracked === true || $inventoryTracked === 1 || $inventoryTracked === 'true';
            $quantity = $quantity === null ? null : (int) $quantity;
            $isSoldOut = $tracked && $quantity !== null && $quantity <= 0;

            $product['inventory_tracked'] = $tracked;
            $product['inventory_quantity'] = $quantity;
            $product['is_sold_out'] = $isSoldOut;
            $product['is_low_stock'] = $tracked && ! $isSoldOut && $quantity !== null && $quantity <= $threshold;
            $product['movement_classification'] = $movementBySku[trim((string) ($product['sku'] ?? ''))] ?? null;
            $product['siblings'] = app(ShopYourVibeSiblingService::class)->selectedForTags((array) ($product['tags'] ?? []), $siblingOptions);
            $product['bead_colour_finish'] = $beadColourFinishByGid[$product['id'] ?? ''] ?? null;
            $product['complementary_count'] = $complementaryCountByGid[$product['id'] ?? ''] ?? 0;
            $product['is_prelaunch_draft'] = (bool) ($product['is_prelaunch_draft'] ?? str_starts_with((string) ($product['id'] ?? ''), 'draft:'));

            return $product;
        })->all();
    }

    private function withPlacementInfo(?ShopYourVibeDraft $draft, array $products): array
    {
        if (! $draft) {
            return $products;
        }
        $collections = collect($draft->desired['collections'] ?? []);
        $placements = $draft->desired['draft_placements'] ?? [];

        return collect($products)->map(function (array $product) use ($collections, $placements): array {
            $id = (string) ($product['id'] ?? '');
            if ($id === '' || ! str_starts_with($id, 'draft:')) {
                return $product;
            }
            $associated = $collections
                ->filter(fn (array $collection): bool => collect($collection['products'] ?? [])->contains(fn (array $item): bool => ($item['id'] ?? null) === $id))
                ->map(function (array $collection, string $gid) use ($placements, $id): array {
                    return [
                        'gid' => $gid,
                        'name' => $collection['title'] ?? $collection['handle'] ?? $gid,
                        'complete' => data_get($placements, "{$id}.{$gid}.status") === 'complete',
                    ];
                })
                ->values()
                ->all();

            $product['collection_placements'] = $associated;
            $product['placement_complete_count'] = collect($associated)->where('complete', true)->count();
            $product['placement_total_count'] = count($associated);

            return $product;
        })->all();
    }

    private function resetComplementaryModalState(): void
    {
        $this->managingComplementaryProductGid = null;
        $this->complementarySelected = [];
        $this->complementarySearch = '';
        $this->complementarySearchResults = [];
        $this->complementaryErrors = [];
    }

    private function refreshComplementarySelectionStatuses(): void
    {
        if (! $this->managingComplementaryProductGid) {
            return;
        }

        $result = app(ShopYourVibeComplementaryService::class)->addTokens(
            $this->managingComplementaryProductGid,
            collect($this->complementarySelected)->pluck('id')->all(),
            '',
        );

        $this->complementarySelected = $result['selected'];
    }

    private function refreshComplementarySearch(): void
    {
        if (! $this->managingComplementaryProductGid) {
            $this->complementarySearchResults = [];

            return;
        }

        $this->complementarySearchResults = app(ShopYourVibeComplementaryService::class)->search(
            $this->complementarySearch,
            $this->managingComplementaryProductGid,
            collect($this->complementarySelected)->pluck('id')->all(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<string, string>
     */
    private function beadColourFinishByGid(array $products): array
    {
        $gids = collect($products)
            ->pluck('id')
            ->filter()
            ->map(fn ($gid): string => trim((string) $gid))
            ->filter()
            ->unique()
            ->values();

        if ($gids->isEmpty()) {
            return [];
        }

        return Product::query()
            ->whereIn('shopify_id', $gids->all())
            ->orderByDesc('id')
            ->get()
            ->unique('shopify_id')
            ->mapWithKeys(function (Product $product): array {
                $state = app(ShopYourVibeTagService::class)->stateForProduct($product);
                $finish = trim((string) ($state['bead_colour_finish'] ?? ''));

                return $finish !== '' ? [(string) $product->shopify_id => $finish] : [];
            })
            ->all();
    }

    private function variantInventoryBySku(array $products): array
    {
        $skus = collect($products)->pluck('sku')->filter()->map(fn ($sku) => trim((string) $sku))->unique()->values();
        if ($skus->isEmpty()) {
            return [];
        }

        return Variant::query()
            ->whereIn('sku', $skus)
            ->orderByDesc('id')
            ->get(['sku', 'inventory_tracked', 'inventory_qty'])
            ->unique('sku')
            ->mapWithKeys(fn (Variant $variant): array => [
                trim((string) $variant->sku) => [
                    'inventory_tracked' => $variant->inventory_tracked,
                    'inventory_qty' => $variant->inventory_qty,
                ],
            ])
            ->all();
    }

    private function reorderLocalProducts(array $products, array $ids): array
    {
        $byId = collect($products)->keyBy('id');

        return collect($ids)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->values()
            ->all();
    }

    private function movementClassificationsBySku(array $products): array
    {
        $skus = collect($products)->pluck('sku')->filter()->map(fn ($sku) => trim((string) $sku))->unique()->values();
        if ($skus->isEmpty()) {
            return [];
        }
        $runId = ProductMovementReportRun::query()
            ->where('status', ProductMovementReportRun::STATUS_COMPLETED)
            ->latest('completed_at')
            ->latest('id')
            ->value('id');
        if (! $runId) {
            return [];
        }

        return ProductMovementReportRow::query()
            ->where('product_movement_report_run_id', $runId)
            ->whereIn('sku', $skus)
            ->pluck('movement_classification', 'sku')
            ->map(fn ($value) => strtoupper((string) $value))
            ->all();
    }

    private function readMappingCsv(mixed $file): array
    {
        $path = is_object($file) && method_exists($file, 'getRealPath')
            ? $file->getRealPath()
            : (is_string($file) ? Storage::disk('local')->path($file) : null);
        if (! $path || ! is_readable($path)) {
            throw new \RuntimeException('The uploaded mapping CSV could not be read.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The uploaded mapping CSV could not be opened.');
        }
        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (! is_array($headers)) {
                throw new \RuntimeException('The mapping CSV is empty.');
            }
            $normalized = array_map(function ($header): string {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);

                return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $header)));
            }, $headers);
            $indexes = array_flip($normalized);
            $matchIndexes = array_values(array_filter([
                $indexes['handle'] ?? null,
                $indexes['collection handle'] ?? null,
                $indexes['collection name'] ?? null,
                $indexes['collection'] ?? null,
            ], fn ($index) => $index !== null));
            if ($matchIndexes === []) {
                throw new \RuntimeException('CSV headers must include Handle, Collection Handle, or Collection Name.');
            }

            $rows = [];
            $rowNumber = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rowNumber++;
                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                $match = '';
                foreach ($matchIndexes as $index) {
                    $match = trim((string) ($values[$index] ?? ''));
                    if ($match !== '') {
                        break;
                    }
                }
                $rows[] = [
                    'row' => $rowNumber,
                    'match' => $match,
                    'design_value' => isset($indexes['design']) ? trim((string) ($values[$indexes['design']] ?? '')) : null,
                    'colour_style_value' => isset($indexes['colour style'])
                        ? trim((string) ($values[$indexes['colour style']] ?? ''))
                        : (isset($indexes['color style']) ? trim((string) ($values[$indexes['color style']] ?? '')) : null),
                ];
            }
        } finally {
            fclose($handle);
        }
        if ($rows === []) {
            throw new \RuntimeException('The mapping CSV contains no data rows.');
        }

        return $rows;
    }

    private function deactivateCardMapping(?string $collectionGid): void
    {
        if (! $collectionGid || str_starts_with($collectionGid, 'new:')) {
            return;
        }
        ShopYourVibeCollectionMapping::query()
            ->where('parent_collection_id', $this->draft()->collection_gid)
            ->where('shopify_collection_id', $collectionGid)
            ->update(['is_active' => false]);
        $this->vibeMappings = array_values(array_filter(
            $this->vibeMappings,
            fn ($mapping) => $mapping['shopify_collection_id'] !== $collectionGid,
        ));
    }

    protected function getViewData(): array
    {
        $this->guard();
        $draft = $this->draft();
        $pendingDrafts = ShopYourVibeDraft::where('pending', true)->get()->keyBy('collection_gid');
        $parents = collect($this->parents)->keyBy('gid');
        foreach ($pendingDrafts as $gid => $pending) {
            if (! $parents->has($gid)) {
                $parents->put($gid, array_merge($pending->desired['parent'], ['count' => count($pending->desired['cards']),
                    'product_count' => (int) data_get($pending->desired, 'parent.product_count', 0), 'updated_at' => null]));
            }
        }
        $parents = $parents->filter(fn ($parent) => $this->search === '' || str_contains(strtolower($parent['title'].' '.$parent['handle']), strtolower($this->search)));
        $card = $draft ? collect($draft->desired['cards'])->firstWhere('key', $this->activeCard) : null;
        $collection = $card ? ($draft->desired['collections'][$card['collection_gid']] ?? null) : null;
        if ($collection) {
            $collection['products'] = $this->decorateProductCards($collection['products'] ?? []);
            $collection['products'] = $this->withPlacementInfo($draft, $collection['products']);
        }
        $products = collect();
        if ($this->addingProducts && $collection && $collection['membership_supported']) {
            $products = Product::with(['images', 'variants'])->whereNotNull('shopify_id')
                ->whereNotIn('shopify_id', array_column($collection['products'], 'id'))
                ->where(fn ($query) => $query->where('title', 'like', '%'.$this->productSearch.'%')
                    ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', '%'.$this->productSearch.'%')))
                ->orderBy('title')->limit(60)->get()->unique('shopify_id');
        }

        $showProductList = $this->activeTab === 'products'
            || filled($this->managingProductGid)
            || filled($this->managingSiblingProductGid)
            || filled($this->managingComplementaryProductGid);
        $managedProduct = $this->managingProductGid
            ? collect($this->parentProducts)->firstWhere('id', $this->managingProductGid)
            : null;
        $filteredParentProducts = [];
        if ($showProductList) {
            $membershipLabels = collect($this->vibeMappings)
                ->filter(fn ($mapping): bool => filled($mapping['membership_tag'] ?? null))
                ->mapWithKeys(fn ($mapping): array => [
                    mb_strtolower(trim((string) $mapping['membership_tag'])) => $mapping['collection_name'],
                ]);
            $needle = mb_strtolower(trim($this->assignmentProductSearch));
            $filteredParentProducts = collect($this->parentProducts)
                ->filter(function (array $product) use ($needle): bool {
                    if ($needle === '') {
                        return true;
                    }

                    return str_contains(mb_strtolower((string) ($product['title'] ?? '')), $needle)
                        || str_contains(mb_strtolower((string) ($product['sku'] ?? '')), $needle);
                })
                ->map(function (array $product) use ($membershipLabels): array {
                    $productTags = collect($product['tags'] ?? [])->map(fn ($tag): string => mb_strtolower(trim((string) $tag)));
                    $product['vibe_assignments'] = $membershipLabels
                        ->filter(fn ($_name, string $tag): bool => $productTags->contains($tag))
                        ->values()
                        ->all();

                    return $product;
                })
                ->pipe(fn ($products) => collect($this->withPlacementInfo($draft, $products->all())))
                ->values()
                ->all();
        }
        $selectedVibes = collect($this->selectedVibes);
        $originalVibes = collect($this->originalSelectedVibes);
        $assignmentAdds = collect($this->vibeMappings)
            ->whereIn('shopify_collection_id', $selectedVibes->diff($originalVibes))->pluck('collection_name')->values();
        $assignmentAddDetails = collect($this->vibeMappings)
            ->whereIn('shopify_collection_id', $selectedVibes->diff($originalVibes))
            ->map(fn ($mapping) => [
                'name' => $mapping['collection_name'],
                'membership_tag' => $mapping['membership_tag'] ?? null,
                'design_value' => $mapping['design_value'] ?? null,
                'colour_style_value' => $mapping['colour_style_value'] ?? null,
            ])->values();
        $assignmentRemovals = collect($this->vibeMappings)
            ->whereIn('shopify_collection_id', $originalVibes->diff($selectedVibes))->pluck('collection_name')->values();

        $complementaryProduct = $this->managingComplementaryProductGid
            ? collect($this->parentProducts)->firstWhere('id', $this->managingComplementaryProductGid)
            : null;

        return compact('draft', 'parents', 'pendingDrafts', 'card', 'collection', 'products', 'managedProduct', 'filteredParentProducts', 'assignmentAdds', 'assignmentAddDetails', 'assignmentRemovals', 'complementaryProduct') + [
            'summary' => $draft && $this->confirmingPush ? app(ShopYourVibeWorkflow::class)->summary($draft) : [],
        ];
    }
}
