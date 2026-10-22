<?php

namespace App\Filament\Pages;

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Jobs\ApplyShopYourVibeOperation;
use App\Jobs\HydrateShopYourVibeDraft;
use App\Jobs\PushShopYourVibe;
use App\Jobs\PushShopYourVibeComplementaryProducts;
use App\Jobs\PushShopYourVibeProductSiblings;
use App\Jobs\PushShopYourVibeProductTags;
use App\Jobs\RefreshShopYourVibeDraft;
use App\Jobs\RefreshShopYourVibeParents;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use App\Models\ShopifyCollection;
use App\Models\ShopifyRow;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\ShopYourVibeDraft;
use App\Models\Variant;
use App\Services\HeaderStore;
use App\Services\ShopYourVibeAssignmentService;
use App\Services\ShopYourVibeComplementaryService;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeSiblingService;
use App\Services\ShopYourVibeTagService;
use App\Services\ShopYourVibeWorkflow;
use App\Services\SiblingCollectionResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class ShopYourVibe extends Page
{
    use WithFileUploads;

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

    #[Url(as: 'collection')]
    public ?string $selectedCollection = null;

    public ?string $selectedCollectionGid = '';

    public string $collectionMode = 'existing';

    public array $newCollection = [];

    public ?string $vibeNameWarning = null;

    public bool $vibeNameBlocked = false;

    private bool $committingVibeName = false;

    public string $productSearch = '';

    public string $imageSearch = '';

    public bool $addingParent = false;

    public bool $addingCard = false;

    public bool $addingProducts = false;

    public bool $choosingImage = false;

    public bool $confirmingPush = false;

    public bool $confirmingAdd = false;

    #[Locked]
    public array $pendingVibe = [];

    #[Locked]
    public ?string $observedStatus = null;

    public array $selectedProducts = [];

    public array $cardForm = [];

    public ?TemporaryUploadedFile $cardImageUpload = null;

    public string $activeTab = 'products';

    /** @var array<string, array<string, mixed>> */
    public array $productPatches = [];

    public bool $hydratingCollection = false;

    public bool $refreshingCollection = false;

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

    public bool $siblingsResolved = false;

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
        if (filled($this->selectedCollection)) {
            $this->manage($this->selectedCollection);
        }
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
            $existing = ShopYourVibeDraft::query()
                ->select(['id', 'revision', 'status', 'collection_gid', 'pending', 'desired'])
                ->where('collection_gid', $gid)
                ->first();
            $this->selectedCollection = $gid;
            $this->activeTab = 'products';
            $this->productPatches = [];
            $this->parentSiblingOptions = [];
            $this->siblingsResolved = false;
            $this->activeCard = null;
            $this->addingParent = false;
            $this->addingCard = false;
            $this->cardForm = [];
            if (! $existing) {
                $this->draftId = null;
                $this->revision = 0;
                $this->vibeMappings = [];
                $this->beginProductHydration($gid);
            } else {
                $this->accept($existing);
                $this->loadAssignmentOverview();
                $loaded = ShopYourVibeWorkflow::productsAreLoaded($existing->desired, $gid);
                if ($loaded || $existing->pending) {
                    $this->hydratingCollection = false;
                } else {
                    $this->beginProductHydration($gid);
                }
            }
            $this->dispatch('close-modal', id: 'shop-your-vibe-collections');
            $this->dispatch('vibe-editor-opened');
        });
    }

    private function beginProductHydration(string $gid, bool $force = false): void
    {
        $progress = Cache::get(ShopYourVibeWorkflow::progressKey($gid));
        $state = is_array($progress) ? ($progress['state'] ?? null) : null;
        if (! $force && in_array($state, ['running', 'failed'], true)) {
            $this->hydratingCollection = true;

            return;
        }
        if ($force) {
            HydrateShopYourVibeDraft::releaseUniqueLock($gid);
        }
        Cache::put(ShopYourVibeWorkflow::progressKey($gid), [
            'state' => 'running',
            'done' => null,
            'total' => null,
            'message' => ShopYourVibeWorkflow::LOADING_PRODUCTS_MESSAGE,
        ], 600);
        $this->hydratingCollection = true;
        HydrateShopYourVibeDraft::dispatch($gid, auth()->id());
    }

    public function back(): void
    {
        $this->guard();
        $this->selectedCollection = null;
        $this->draftId = null;
        $this->activeCard = null;
        $this->cardForm = [];
        $this->productPatches = [];
        $this->hydratingCollection = false;
        $this->parentSiblingOptions = [];
        $this->siblingsResolved = false;
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
            if ($discard && ($draft = $this->draft(true)) && empty($draft->remote_jobs)) {
                if (in_array($draft->status, ['pushing', 'creating', 'deleting'], true)) {
                    throw new \RuntimeException('Wait for the Shopify operation to finish before refreshing.');
                }
                $snapshot = $draft->snapshot;
                $draft->update([
                    'desired' => $snapshot,
                    'pending' => false,
                    'status' => 'synced',
                    'last_error' => null,
                    'progress' => [],
                    'remote_jobs' => [],
                    'operation' => null,
                    'revision' => $draft->revision + 1,
                ]);
                $this->accept($draft->refresh());
                $this->dispatch('vibe-form-saved');
            }
            if ($gid = $this->draft()?->collection_gid) {
                $this->refreshingCollection = true;
                Cache::put(ShopYourVibeWorkflow::progressKey($gid), [
                    'state' => 'running',
                    'done' => null,
                    'total' => null,
                    'message' => 'Refreshing collection products',
                ], 600);
            }
            RefreshShopYourVibeDraft::dispatch($this->draftId, $this->revision, $discard, auth()->id());
            if ($fresh = $this->draft()) {
                $this->accept($fresh);
            }
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
        $this->confirmingAdd = false;
        $this->pendingVibe = [];
        $this->selectedCollectionGid = '';
        $this->collectionMode = 'existing';
        $this->vibeNameWarning = null;
        $this->vibeNameBlocked = false;
        $this->newCollectionForm->fill([
            'image_mode' => 'none',
            'parent_title' => $this->newCollectionParentTitle(),
            'vibe_name' => '',
            'generated_title' => '',
            'handle' => '',
        ]);
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
        $this->vibeNameWarning = null;
        $this->vibeNameBlocked = false;
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
            TextInput::make('parent_title')->label('Collection name')->readOnly()->dehydrated(false)
                ->extraInputAttributes(['data-parent-title' => $this->newCollectionParentTitle()]),
            TextInput::make('vibe_name')->label('Vibe name')->required()->maxLength(255)
                ->helperText(fn (): ?string => filled($this->vibeNameWarning) ? $this->vibeNameWarning : null)
                ->extraInputAttributes([
                    'data-vibe-name' => true,
                    'data-parent-title' => $this->newCollectionParentTitle(),
                    'data-parent-handle' => $this->newCollectionParentHandle(),
                    'x-on:input' => 'window.syvVibeInput($el, $wire)',
                    'x-on:blur' => 'window.syvVibeBlur($el, $wire)',
                ]),
            TextInput::make('generated_title')->label('Generated Shopify collection name')->readOnly()->dehydrated(false)
                ->extraInputAttributes(['data-generated-title' => true]),
            TextInput::make('handle')->label('Generated Shopify handle')->readOnly()->dehydrated(false)
                ->extraInputAttributes(['data-generated-handle' => true])
                ->helperText('Generated from the collection and vibe name. URL: /collections/your-slug'),
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
        $rawVibe = (string) ($data['vibe_name'] ?? '');
        $normalized = ShopYourVibeWorkflow::normalizeVibeName($rawVibe, $this->newCollectionParentTitle());
        if ($message = ShopYourVibeWorkflow::vibeNameError($normalized)) {
            $this->rejectVibeName($normalized, $message);

            throw ValidationException::withMessages(['newCollection.vibe_name' => $message]);
        }
        $this->vibeNameBlocked = false;
        $this->vibeNameWarning = null;
        $this->attempt(function () use ($data, $normalized): void {
            $pending = [
                'mode' => 'new',
                'vibe_name' => $normalized['name'],
                'preview_name' => $normalized['name'],
                'preview_title' => (string) ($data['generated_title'] ?? $this->newCollection['generated_title'] ?? ''),
                'preview_handle' => (string) ($data['handle'] ?? $this->newCollection['handle'] ?? ''),
            ];
            if ($data['image_mode'] === 'upload') {
                $pending['image_path'] = $data['upload']->store('shop-your-vibe', 'public');
                if (! $pending['image_path']) {
                    throw new \RuntimeException('The image could not be saved. Please upload it again.');
                }
                $pending['image_url'] = url(Storage::disk('public')->url($pending['image_path']));
            } elseif ($data['image_mode'] === 'existing') {
                $image = app(ShopYourVibeShopify::class)->image($data['image_gid']);
                $pending['image'] = $image['id'];
                $pending['image_url'] = $image['image']['url'];
            }
            $this->pendingVibe = $pending;
            $this->confirmingAdd = true;
            $this->dispatch('open-modal', id: 'confirm-add-vibe');
        });
    }

    public function confirmAddVibe(): void
    {
        $this->guard();
        abort_unless($this->confirmingAdd && $this->pendingVibe !== [] && $this->draftId, 422);
        $this->attempt(function (): void {
            $draft = app(ShopYourVibeWorkflow::class)->queueCreateVibe($this->draftId, $this->revision, $this->pendingVibe);
            $this->accept($draft);
            $this->dispatchImmediate($draft);
            $draft = $draft->fresh();
            $this->accept($draft);
            if ($draft->status === 'failed') {
                throw new \RuntimeException($draft->last_error ?: 'Shopify could not create this Vibe.');
            }
            $this->confirmingAdd = false;
            $this->pendingVibe = [];
            $this->closeCollectionPicker();
            $this->dispatch('close-modal', id: 'confirm-add-vibe');
            $this->dispatch('close-modal', id: 'shop-your-vibe-collections');
            $this->activeTab = 'vibes';
            $this->loadAssignmentOverview();
            Cache::forget($this->parentCacheKey());
            $completed = $draft->status === 'synced';
            Notification::make()
                ->title($completed ? 'Vibe added to Shopify' : 'Vibe queued for Shopify')
                ->body($completed
                    ? 'Shopify confirmed the new Vibe.'
                    : 'Shopify is creating the collection, image, and preview card. This page will update when it finishes.')
                ->success()
                ->send();
        });
    }

    public function cancelAddVibe(): void
    {
        $this->guard();
        $this->confirmingAdd = false;
        $this->dispatch('close-modal', id: 'confirm-add-vibe');
    }

    public function retryImmediateOperation(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            $draft = app(ShopYourVibeWorkflow::class)->resumeOperation($this->draftId);
            $this->accept($draft);
            $this->dispatchImmediate($draft);
            $draft = $draft->fresh();
            $this->accept($draft);
            if ($draft->status === 'failed') {
                throw new \RuntimeException($draft->last_error ?: 'The Shopify operation failed.');
            }
            $this->loadAssignmentOverview();
            Cache::forget($this->parentCacheKey());
            $completed = $draft->status === 'synced';
            Notification::make()
                ->title($completed ? 'Shopify operation finished' : 'Shopify operation queued')
                ->body($completed
                    ? 'Shopify confirmed the operation.'
                    : 'The operation is running again from the last completed step.')
                ->success()
                ->send();
        });
    }

    public function updatedNewCollectionVibeName(?string $value): void
    {
        $typed = ShopYourVibeWorkflow::typingVibeName((string) $value);
        if ($typed !== (string) $value) {
            $this->newCollection['vibe_name'] = $typed;
            $value = $typed;
        }
        if (! $this->committingVibeName && ($this->vibeNameWarning !== null || $this->vibeNameBlocked)) {
            $this->vibeNameWarning = null;
            $this->vibeNameBlocked = false;
            $this->resetValidation('newCollection.vibe_name');
        }
        $normalized = ShopYourVibeWorkflow::normalizeVibeName((string) $value, $this->newCollectionParentTitle());
        $this->fillVibePreview($normalized['name']);
    }

    public function commitNewVibeName(?string $value = null): void
    {
        $this->guard();
        abort_unless($this->addingCard && $this->collectionMode === 'new', 422);
        $this->committingVibeName = true;
        try {
            $normalized = ShopYourVibeWorkflow::normalizeVibeName(
                $value ?? (string) ($this->newCollection['vibe_name'] ?? ''),
                $this->newCollectionParentTitle(),
            );
            $this->newCollection['vibe_name'] = $normalized['name'];
            $this->fillVibePreview($normalized['name']);
            if ($message = ShopYourVibeWorkflow::vibeNameError($normalized)) {
                $this->rejectVibeName($normalized, $message);

                return;
            }
            $this->vibeNameBlocked = false;
            $this->resetValidation('newCollection.vibe_name');
            $this->vibeNameWarning = $normalized['removed_parent'] ? ShopYourVibeWorkflow::PARENT_PREFIX_WARNING : null;
        } finally {
            $this->committingVibeName = false;
        }
    }

    public function clearVibeNameNotice(): void
    {
        $this->guard();
        $this->vibeNameWarning = null;
        $this->vibeNameBlocked = false;
        $this->resetValidation('newCollection.vibe_name');
    }

    private function rejectVibeName(array $normalized, string $message): void
    {
        $this->newCollection['vibe_name'] = $normalized['name'];
        $this->fillVibePreview($normalized['name']);
        $this->vibeNameWarning = null;
        $this->vibeNameBlocked = (bool) $normalized['removed_parent'] && $normalized['name'] === '';
        $this->addError('newCollection.vibe_name', $message);
    }

    private function fillVibePreview(string $normalizedVibe): void
    {
        $this->newCollection['generated_title'] = ShopYourVibeWorkflow::shopifyCollectionTitle(
            $this->newCollectionParentTitle(),
            $normalizedVibe,
        );
        $this->newCollection['handle'] = ShopYourVibeWorkflow::contextualHandle(
            $this->newCollectionParentHandle(),
            $normalizedVibe,
        );
    }

    private function newCollectionParent(): array
    {
        $draft = $this->draft();
        if (! $draft) {
            return [];
        }

        return $draft->desired['parent'] ?? [];
    }

    private function newCollectionParentTitle(): string
    {
        return trim((string) ($this->newCollectionParent()['title'] ?? ''));
    }

    private function newCollectionParentHandle(): string
    {
        return ShopYourVibeWorkflow::parentHandle($this->newCollectionParent());
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
            $this->stageExistingVibe($this->selectedCollectionGid);
        } else {
            $this->manage($this->selectedCollectionGid);
        }
    }

    public function stageExistingVibe(string $gid): void
    {
        $this->guard();
        $this->attempt(function () use ($gid): void {
            $collection = ShopifyCollection::where('shopify_id', $gid)->firstOrFail();
            $this->pendingVibe = [
                'mode' => 'existing',
                'collection_gid' => $collection->shopify_id,
                'preview_name' => $collection->title,
                'preview_title' => $collection->title,
                'preview_handle' => $collection->handle,
            ];
            $this->confirmingAdd = true;
            $this->dispatch('open-modal', id: 'confirm-add-vibe');
        });
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
            $this->cardForm = array_intersect_key($card, array_flip(['name', 'image', 'image_path', 'image_url', 'link']));
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
            $this->cardImageUpload = null;
        });
    }

    public function saveCard(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            $before = collect($this->draft(true)?->snapshot['cards'] ?? [])->firstWhere('key', $this->activeCard);
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
            $this->queueImagePush($before);
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
            ->modalDescription('Choose what should happen in Shopify. This runs after you confirm and does not use Push Changes.')
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
                    $mode = (string) ($data['mode'] ?? 'layout');
                    $draft = app(ShopYourVibeWorkflow::class)->queueDeleteVibe($this->draftId, $this->revision, [
                        'key' => $key,
                        'mode' => $mode,
                    ]);
                    $this->accept($draft);
                    $this->dispatchImmediate($draft);
                    $draft = $draft->fresh();
                    $this->accept($draft);
                    if ($draft->status === 'failed') {
                        throw new \RuntimeException($draft->last_error ?: 'Shopify could not remove this Vibe.');
                    }
                    $this->deactivateCardMapping($collectionGid);
                    if ($this->activeCard === $key) {
                        $this->activeCard = null;
                        $this->cardForm = [];
                    }
                    $this->confirmingPush = false;
                    $this->loadAssignmentOverview();
                    Cache::forget($this->parentCacheKey());
                    $completed = $draft->status === 'synced';
                    Notification::make()
                        ->title($completed ? 'Vibe removal finished' : 'Vibe removal queued')
                        ->body($completed
                            ? 'Shopify confirmed the removal. Products were kept.'
                            : 'Shopify is removing this Vibe. This page will update when it finishes.')
                        ->success()
                        ->send();
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

    /**
     * @return array{applied: bool, ids: array<int, string>}
     */
    public function reorderProducts(string $gid, array $ids, int $epoch = 0, string $session = ''): array
    {
        $applied = $this->applyProductOrder($gid, $ids, $epoch, $session);

        return [
            'applied' => $applied,
            'ids' => $this->savedProductIds($gid),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function savedProductOrder(string $gid): array
    {
        $this->guard();
        $this->skipRender();

        return $this->savedProductIds($gid);
    }

    public function moveProductToPosition(string $gid, string $productGid, int $position, int $epoch = 0, string $session = ''): void
    {
        $this->guard();
        $this->attempt(function () use ($gid, $productGid, $position, $epoch, $session): void {
            $key = $this->orderEpochKey($gid, $session);
            Cache::lock($this->orderLockKey($gid), 15)->block(8, function () use ($key, $gid, $productGid, $position, $epoch): void {
                if ($this->orderEpochIsStale($key, $epoch)) {
                    return;
                }
                $this->rememberOrderEpoch($key, $epoch);
                $draft = $this->draft();
                if (! $draft) {
                    return;
                }
                $this->accept(app(ShopYourVibeWorkflow::class)->placeProduct($draft->id, $this->revision, $gid, $productGid, $position));
                $this->confirmingPush = false;
                $this->dispatch('vibe-draft-saved');
            });
        });
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
        $product = $this->collectionProduct($productGid);
        abort_unless($product, 404);
        $tags = collect($product['tags'] ?? [])->map(fn ($tag) => mb_strtolower(trim($tag)));
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
        $product = $this->collectionProduct($productGid);
        abort_unless($product, 404);
        if (! $this->siblingsResolved) {
            $this->parentSiblingOptions = app(ShopYourVibeSiblingService::class)
                ->optionsForParent($this->draft()?->desired['parent'] ?? []);
            $this->siblingsResolved = true;
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
        abort_unless($this->collectionProduct($this->managingSiblingProductGid), 422);
        $this->attempt(function (): void {
            $parent = $this->draft()->desired['parent'] ?? [];
            PushShopYourVibeProductSiblings::dispatch(
                $parent,
                $this->managingSiblingProductGid,
                $this->selectedSiblings,
                auth()->id(),
            );
            $selected = app(ShopYourVibeSiblingService::class)->selectedForTags(
                $this->selectedSiblings,
                $this->siblingOptions,
            );
            $selectedTags = collect($selected)->pluck('tag')->values()->all();
            $managedTags = collect($this->siblingOptions)
                ->pluck('tag')
                ->map(fn (mixed $tag): string => mb_strtolower(trim((string) $tag)))
                ->filter()
                ->all();
            $current = $this->collectionProduct($this->managingSiblingProductGid) ?? [];
            $existingTags = collect((array) ($current['tags'] ?? []))
                ->reject(fn (mixed $tag): bool => in_array(mb_strtolower(trim((string) $tag)), $managedTags, true))
                ->values()
                ->all();
            $this->patchProduct($this->managingSiblingProductGid, [
                'tags' => array_values(array_unique([...$existingTags, ...$selectedTags])),
                'siblings' => $selected,
            ]);
            $this->managingSiblingProductGid = null;
            $this->selectedSiblings = [];
            $this->siblingOptions = [];
            $this->dispatch('close-modal', id: 'manage-sibling-assignments');
            Notification::make()->title('Sibling assignments queued')->success()->send();
        });
    }

    public function openProductTags(string $productGid): void
    {
        $this->guard();
        abort_unless($this->collectionProduct($productGid), 404);
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
        abort_unless($this->collectionProduct($this->managingTagProductGid), 422);
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
        abort_unless($this->collectionProduct($productGid), 404);
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
        abort_unless($this->collectionProduct($this->managingComplementaryProductGid), 422);
        $this->attempt(function (): void {
            $service = app(ShopYourVibeComplementaryService::class);
            $result = $service->saveLocal(
                $this->managingComplementaryProductGid,
                collect($this->complementarySelected)->pluck('id')->all(),
                auth()->id(),
            );

            PushShopYourVibeComplementaryProducts::dispatch($this->managingComplementaryProductGid, auth()->id());

            $this->patchProduct($this->managingComplementaryProductGid, [
                'complementary_count' => count($result['selected']),
            ]);

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
        abort_unless($this->collectionProduct($this->managingProductGid), 422);
        $this->attempt(function (): void {
            $confirmed = app(ShopYourVibeAssignmentService::class)->assign(
                $this->draft()->collection_gid,
                $this->managingProductGid,
                $this->selectedVibes,
            );
            $this->patchProduct($this->managingProductGid, [
                'tags' => $confirmed['tags'],
            ]);
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
        unset($this->cardForm['image_path']);
        $this->cardForm['image_url'] = data_get($image, 'image.url');
        $this->choosingImage = false;
        $this->saveCard();
    }

    public function uploadCardImage(): void
    {
        $this->guard();
        abort_unless($this->activeCard, 422);
        $this->validate([
            'cardImageUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $this->attempt(function (): void {
            $path = $this->cardImageUpload?->store('shop-your-vibe', 'public');
            if (! $path) {
                throw new \RuntimeException('The image could not be saved. Please upload it again.');
            }
            $this->cardForm['image'] = '';
            $this->cardForm['image_path'] = $path;
            $this->cardForm['image_url'] = url(Storage::disk('public')->url($path));
            $this->cardImageUpload = null;
            $this->choosingImage = false;
            $this->saveCard();
        });
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
            $this->queueDraftPush();
            $this->confirmingPush = false;
            $this->dispatch('vibe-form-saved');
            Cache::forget($this->parentCacheKey());
        });
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function queueImagePush(?array $before): void
    {
        $draft = $this->draft();
        $card = collect($draft?->desired['cards'] ?? [])->firstWhere('key', $this->activeCard);
        if (! $draft || ! $card || ! $draft->pending || $draft->status === 'pushing') {
            return;
        }

        $imageChanged = (string) ($card['image'] ?? '') !== (string) ($before['image'] ?? '')
            || (string) ($card['image_path'] ?? '') !== (string) ($before['image_path'] ?? '');
        if (! $imageChanged) {
            return;
        }

        $this->queueDraftPush();
        Notification::make()
            ->title('Image queued for Shopify')
            ->body('The new image is being sent to Shopify now and will update this Shop Your Vibe card. You can change the image again after this update finishes.')
            ->success()
            ->send();
    }

    private function queueDraftPush(): void
    {
        $draft = app(ShopYourVibeWorkflow::class)->queue($this->draftId, $this->revision);
        $this->accept($draft);
        try {
            PushShopYourVibe::dispatch($draft->id);
        } catch (Throwable $e) {
            $draft->update(['status' => 'failed', 'last_error' => 'The push could not be queued. Please retry.']);
            throw $e;
        }
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

    public function retryHydration(): void
    {
        $this->guard();
        $this->attempt(function (): void {
            $gid = $this->selectedCollection;
            if (! is_string($gid) || $gid === '') {
                return;
            }
            $draft = $this->draftId ? $this->draft() : null;
            if ($draft && ($draft->pending || ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $draft->collection_gid))) {
                return;
            }
            $this->beginProductHydration($gid, true);
        });
    }

    public function pollPush(): void
    {
        $this->guard();
        if ($this->refreshingCollection && is_string($this->selectedCollection)) {
            $refreshProgress = Cache::get(ShopYourVibeWorkflow::progressKey($this->selectedCollection));
            $refreshState = is_array($refreshProgress) ? ($refreshProgress['state'] ?? null) : null;
            if (in_array($refreshState, ['success', 'failed', 'skipped'], true)) {
                $this->refreshingCollection = false;
            }
        }
        if ($this->hydratingCollection && is_string($this->selectedCollection)) {
            $progress = Cache::get(ShopYourVibeWorkflow::progressKey($this->selectedCollection));
            if (is_array($progress) && ($progress['state'] ?? null) === 'failed') {
                return;
            }
            $ready = ShopYourVibeDraft::query()->where('collection_gid', $this->selectedCollection)->first();
            if ($ready && ShopYourVibeWorkflow::productsAreLoaded($ready->desired, $ready->collection_gid)) {
                $this->hydratingCollection = false;
                $this->accept($ready);
                $this->loadAssignmentOverview();
                $this->loadError = null;

                return;
            }
            $this->skipRender();

            return;
        }
        if ($draft = $this->draft()) {
            $previous = $this->observedStatus;
            if ($draft->pending && $draft->status === 'pending') {
                try {
                    $draft = app(ShopYourVibeWorkflow::class)->confirmCompletedPush($draft->id);
                } catch (Throwable $e) {
                    report($e);
                    $draft->update([
                        'pending' => true,
                        'status' => 'failed',
                        'last_error' => $e->getMessage(),
                        'revision' => $draft->revision + 1,
                    ]);
                    $draft->refresh();
                }
            }
            $this->accept($draft);
            if ($previous && $previous !== $draft->status && $draft->status === 'synced' && in_array($previous, ['creating', 'deleting', 'pushing'], true)) {
                $this->loadAssignmentOverview();
                Notification::make()
                    ->id('syv-operation-finished')
                    ->title('Shopify operation finished')
                    ->body('The Shop Your Vibe page is up to date.')
                    ->success()
                    ->send();
            }
            if ($previous && $previous !== 'failed' && $draft->status === 'failed' && filled($draft->last_error)) {
                $this->loadError = $draft->last_error;
                Notification::make()
                    ->id('syv-'.md5($draft->last_error))
                    ->title('Shop Your Vibe operation failed')
                    ->body($draft->last_error)
                    ->danger()
                    ->send();
            }
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
        $this->loadedDraft = null;
        $this->loadedDraftHasSnapshot = false;
        $this->draftId = $draft->id;
        $this->revision = $draft->revision;
        $this->observedStatus = $draft->status;
    }

    private function reconcileRevision(): void
    {
        if (! $this->draftId) {
            return;
        }
        $draft = ShopYourVibeDraft::find($this->draftId);
        if (! $draft || $draft->revision === $this->revision) {
            return;
        }
        if ($draft->status === 'synced' && ! $draft->pending && empty($draft->remote_jobs) && empty($draft->operation)) {
            $this->revision = $draft->revision;
            $this->observedStatus = $draft->status;
        }
    }

    private function dispatchImmediate(ShopYourVibeDraft $draft): void
    {
        $attempt = (string) ($draft->operation['attempt'] ?? '');
        if ($attempt === '') {
            throw new \RuntimeException('The Shopify operation could not be queued.');
        }
        try {
            ApplyShopYourVibeOperation::dispatch($draft->id, $attempt);
        } catch (Throwable $e) {
            $draft->update([
                'status' => 'failed',
                'pending' => false,
                'last_error' => 'The operation could not be queued. Please retry.',
                'revision' => $draft->revision + 1,
            ]);
            throw $e;
        }
    }

    private ?ShopYourVibeDraft $loadedDraft = null;

    private bool $loadedDraftHasSnapshot = false;

    private function draft(bool $withSnapshot = false): ?ShopYourVibeDraft
    {
        if (! $this->draftId) {
            return null;
        }
        if ($this->loadedDraft && ($this->loadedDraftHasSnapshot || ! $withSnapshot)) {
            return $this->loadedDraft;
        }
        $query = ShopYourVibeDraft::query();
        if (! $withSnapshot) {
            $query->select([
                'id', 'collection_gid', 'desired', 'revision', 'pending', 'status', 'last_error',
                'progress', 'remote_jobs', 'operation', 'refreshed_at', 'created_at', 'updated_at',
            ]);
        }
        $this->loadedDraft = $query->findOrFail($this->draftId);
        $this->loadedDraftHasSnapshot = $withSnapshot;

        return $this->loadedDraft;
    }

    private function guard(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function attempt(callable $callback): void
    {
        try {
            $this->reconcileRevision();
            $callback();
            $this->loadError = null;
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $message = $e->getMessage();
            if ($this->loadError !== $message) {
                $this->loadError = $message;
                Notification::make()
                    ->id('syv-'.md5($message))
                    ->title('Shop Your Vibe could not complete this action')
                    ->body($message)
                    ->danger()
                    ->send();
            }
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
        $existing = ShopYourVibeCollectionMapping::query()
            ->where('parent_collection_id', $draft->collection_gid)
            ->get()
            ->keyBy('shopify_collection_id');
        $mappings = [];
        foreach ($draft->desired['cards'] as $card) {
            $gid = $card['collection_gid'] ?? null;
            if (! $gid || str_starts_with($gid, 'new:') || isset($mappings[$gid])) {
                continue;
            }
            $collection = $this->mappingCollectionSummary($draft, $gid, $card);
            $mapping = $existing->get($gid);
            if (! $mapping) {
                $mapping = $service->syncMapping($draft->collection_gid, $card, $collection);
            }
            $mappings[$gid] = $mapping->toArray() + ['product_count' => $collection['product_count']];
        }
        $represented = array_keys($mappings);
        $missing = $existing->where('is_active', true)->keys()->diff($represented);
        if ($missing->isNotEmpty()) {
            $service->deactivateMissing($draft->collection_gid, $represented);
        }
        $this->vibeMappings = array_values($mappings);
    }

    private function mappingCollectionSummary(ShopYourVibeDraft $draft, string $gid, array $card): array
    {
        $collection = $draft->desired['collections'][$gid] ?? null;
        if (! is_array($collection) && array_key_exists('snapshot', $draft->getAttributes())) {
            $collection = $draft->snapshot['collections'][$gid] ?? null;
        }
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
        $products = $this->withResolvedSkus($products);
        $this->rememberCachedSiblingOptions();
        $movementBySku = $this->movementClassificationsBySku($products);
        $needsInventory = collect($products)->contains(fn (array $product): bool => ($product['inventory_tracked'] ?? null) === null || ($product['inventory_quantity'] ?? null) === null);
        $variantInventoryBySku = $needsInventory ? $this->variantInventoryBySku($products) : [];
        $beadColourFinishByGid = $this->beadColourFinishByGid($products);
        $complementaryCountByGid = app(ShopYourVibeComplementaryService::class)->countsByGid($products);
        $threshold = max(0, (int) config('shop_your_vibe.low_stock_threshold', 5));
        $siblingOptions = $this->parentSiblingOptions;
        $siblings = app(ShopYourVibeSiblingService::class);

        return collect($products)->map(function (array $product) use ($movementBySku, $variantInventoryBySku, $beadColourFinishByGid, $complementaryCountByGid, $threshold, $siblingOptions, $siblings): array {
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
            $product['siblings'] = $siblings->selectedForTags((array) ($product['tags'] ?? []), $siblingOptions);
            $product['bead_colour_finish'] = $beadColourFinishByGid[$product['id'] ?? ''] ?? null;
            $product['complementary_count'] = $complementaryCountByGid[$product['id'] ?? ''] ?? 0;
            $product['is_prelaunch_draft'] = (bool) ($product['is_prelaunch_draft'] ?? str_starts_with((string) ($product['id'] ?? ''), 'draft:'));
            unset($product['shopify_metafields']);

            return $product;
        })->all();
    }

    private function withResolvedSkus(array $products): array
    {
        $missing = collect($products)
            ->filter(fn (array $product): bool => blank($product['sku'] ?? null))
            ->values();

        if ($missing->isEmpty()) {
            return $products;
        }

        $shopifyIds = $missing
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && str_starts_with($id, 'gid://shopify/Product/'))
            ->unique()
            ->values();
        $draftIds = $missing
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && str_starts_with($id, 'draft:'))
            ->map(fn (string $id): int => (int) substr($id, 6))
            ->filter()
            ->unique()
            ->values();

        $skuByProductGid = $shopifyIds->isEmpty()
            ? collect()
            : Product::query()
                ->whereIn('shopify_id', $shopifyIds->all())
                ->with(['variants' => fn ($query) => $query->orderBy('id')])
                ->get()
                ->mapWithKeys(fn (Product $product): array => [
                    (string) $product->shopify_id => trim((string) ($product->variants->first()?->sku ?? '')),
                ]);

        $skuByDraftId = $draftIds->isEmpty()
            ? collect()
            : NewProductDraft::query()
                ->whereKey($draftIds->all())
                ->pluck('sku', 'id')
                ->map(fn (mixed $sku): string => trim((string) $sku));

        return collect($products)->map(function (array $product) use ($skuByProductGid, $skuByDraftId): array {
            if (filled($product['sku'] ?? null)) {
                return $product;
            }

            $id = (string) ($product['id'] ?? '');
            $sku = str_starts_with($id, 'draft:')
                ? $skuByDraftId->get((int) substr($id, 6), '')
                : $skuByProductGid->get($id, '');

            if (filled($sku)) {
                $product['sku'] = $sku;
            }

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
     * @param  array<int, array<string, mixed>>  $products
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

        $models = Product::query()
            ->whereIn('shopify_id', $gids->all())
            ->orderByDesc('id')
            ->get(['id', 'shopify_id', 'import_id', 'handle'])
            ->unique('shopify_id');
        if ($models->isEmpty()) {
            return [];
        }
        $handles = $models->pluck('handle')->map(fn ($handle): string => trim((string) $handle))->filter()->unique();
        $importIds = $models->pluck('import_id')->filter()->unique();
        if ($handles->isEmpty() || $importIds->isEmpty()) {
            return [];
        }
        $rows = ShopifyRow::query()
            ->where('row_type', 'product_primary')
            ->whereIn('import_id', $importIds->all())
            ->whereIn('handle', $handles->all())
            ->orderByDesc('id')
            ->get()
            ->unique(fn (ShopifyRow $row): string => $row->import_id.'|'.$row->handle)
            ->keyBy(fn (ShopifyRow $row): string => $row->import_id.'|'.$row->handle);

        $finishes = [];
        foreach ($models as $product) {
            $row = $rows->get($product->import_id.'|'.$product->handle);
            $finish = trim((string) ($row?->get(HeaderStore::BEAD_COLOUR_FINISH, '') ?? ''));
            if ($finish !== '') {
                $finishes[(string) $product->shopify_id] = $finish;
            }
        }

        return $finishes;
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

    public function statusSnapshot(): array
    {
        $this->guard();
        $this->skipRender();
        $gid = $this->selectedCollection;
        $progress = is_string($gid) ? Cache::get(ShopYourVibeWorkflow::progressKey($gid)) : null;
        $columns = ['id', 'collection_gid', 'revision', 'pending', 'status', 'progress', 'remote_jobs'];
        $draft = $this->draftId ? ShopYourVibeDraft::query()->select($columns)->find($this->draftId) : null;
        if (! $draft && $this->hydratingCollection && is_string($gid)) {
            $draft = ShopYourVibeDraft::query()->where('collection_gid', $gid)->first();
        }
        if ($draft && $draft->pending && $draft->status === 'pending' && empty($draft->remote_jobs) && filled($draft->progress)) {
            try {
                $draft = app(ShopYourVibeWorkflow::class)->confirmCompletedPush($draft->id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $status = $draft->status ?? ($this->hydratingCollection ? 'hydrating' : null);
        $state = is_array($progress) ? ($progress['state'] ?? null) : null;
        $terminal = in_array($state, ['success', 'failed', 'skipped'], true);
        $changed = $draft && (
            ($this->hydratingCollection && ! $this->draftId)
            || ($this->hydratingCollection && $terminal)
            || ($this->refreshingCollection && $terminal)
            || $this->observedStatus !== $draft->status
            || ($this->draftId && $this->revision !== $draft->revision)
        );
        $polling = ($this->hydratingCollection && ! $terminal)
            || ($this->refreshingCollection && ! $terminal)
            || in_array($status, ['pushing', 'creating', 'deleting'], true);
        $done = is_array($progress) ? ($progress['done'] ?? null) : null;
        $total = is_array($progress) ? ($progress['total'] ?? null) : null;

        return [
            'polling' => $polling,
            'changed' => (bool) $changed,
            'message' => is_array($progress) ? ($progress['message'] ?? null) : null,
            'done' => is_int($done) ? $done : null,
            'total' => is_int($total) && $total > 0 ? $total : null,
            'state' => $state,
        ];
    }

    private function applyProductOrder(string $gid, array $ids, int $epoch, string $session = ''): bool
    {
        $applied = false;
        $this->guard();
        $this->attempt(function () use ($gid, $ids, $epoch, $session, &$applied): void {
            $key = $this->orderEpochKey($gid, $session);
            try {
                Cache::lock($this->orderLockKey($gid), 15)->block(8, function () use ($key, $gid, $ids, $epoch, &$applied): void {
                    if ($this->orderEpochIsStale($key, $epoch)) {
                        return;
                    }
                    $this->rememberOrderEpoch($key, $epoch);
                    $this->accept(app(ShopYourVibeWorkflow::class)->edit($this->draftId, $this->revision, 'reorder_products', [
                        'collection_gid' => $gid,
                        'ids' => $ids,
                    ]));
                    $applied = true;
                    $this->confirmingPush = false;
                    $this->dispatch('vibe-draft-saved');
                });
            } catch (LockTimeoutException) {
                throw new \RuntimeException('The product order is still saving. Try again.');
            }
        });
        $this->skipRender();

        return $applied && blank($this->loadError);
    }

    /**
     * @return array<int, string>
     */
    private function savedProductIds(string $gid): array
    {
        if (! $this->draftId) {
            return [];
        }
        $draft = ShopYourVibeDraft::query()->select(['id', 'desired'])->find($this->draftId);
        $products = $draft->desired['collections'][$gid]['products'] ?? [];

        return array_values(array_column(is_array($products) ? $products : [], 'id'));
    }

    private function orderEpochKey(string $gid, string $session = ''): string
    {
        $session = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $session) ?? '', 0, 64);

        return 'syv-order-epoch:'.$this->draftId.':'.$gid.($session !== '' ? ':'.$session : '');
    }

    private function orderLockKey(string $gid): string
    {
        return $this->orderEpochKey($gid).':lock';
    }

    private function orderEpochIsStale(string $key, int $epoch): bool
    {
        return $epoch > 0 && $epoch < (int) Cache::get($key, 0);
    }

    private function rememberOrderEpoch(string $key, int $epoch): void
    {
        if ($epoch > 0) {
            Cache::put($key, $epoch, now()->addHour());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collectionProduct(string $productGid): ?array
    {
        $draft = $this->draft();
        if (! $draft) {
            return null;
        }
        foreach ($draft->desired['collections'][$draft->collection_gid]['products'] ?? [] as $product) {
            if (($product['id'] ?? null) === $productGid) {
                return array_replace($product, $this->productPatches[$productGid] ?? []);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchProduct(string $productGid, array $patch): void
    {
        $this->productPatches[$productGid] = array_replace($this->productPatches[$productGid] ?? [], $patch);
    }

    private function rememberCachedSiblingOptions(): void
    {
        if ($this->siblingsResolved || ! Cache::has(SiblingCollectionResolver::cacheKey())) {
            return;
        }
        $parent = $this->draft()?->desired['parent'] ?? null;
        if (! is_array($parent)) {
            return;
        }
        $this->parentSiblingOptions = app(ShopYourVibeSiblingService::class)->optionsForParent($parent);
        $this->siblingsResolved = true;
    }

    protected function getViewData(): array
    {
        $this->guard();
        $draft = $this->confirmingPush ? $this->draft(true) : $this->draft();
        $pendingDrafts = collect();
        $operationDrafts = collect();
        if (! $draft) {
            $pendingDrafts = ShopYourVibeDraft::query()->where('pending', true)->get(['collection_gid', 'pending', 'status', 'desired'])->keyBy('collection_gid');
            $operationDrafts = ShopYourVibeDraft::query()
                ->whereIn('status', ['creating', 'deleting', 'pushing', 'failed'])
                ->get(['collection_gid', 'pending', 'status', 'desired'])
                ->keyBy('collection_gid');
        }
        $parents = collect($this->parents)->keyBy('gid');
        foreach ($pendingDrafts as $gid => $pending) {
            if (! $parents->has($gid)) {
                $parents->put($gid, array_merge($pending->desired['parent'] ?? [], ['count' => count($pending->desired['cards'] ?? []),
                    'product_count' => (int) data_get($pending->desired, 'parent.product_count', 0), 'updated_at' => null]));
            }
        }
        $parents = $parents->filter(fn ($parent) => $this->search === '' || str_contains(strtolower(($parent['title'] ?? '').' '.($parent['handle'] ?? '')), strtolower($this->search)));
        $card = $draft ? collect($draft->desired['cards'])->firstWhere('key', $this->activeCard) : null;
        $collection = $card ? ($draft->desired['collections'][$card['collection_gid']] ?? null) : null;
        if ($collection) {
            $collection['products'] = $this->presentProducts($draft, $collection['products'] ?? []);
        }
        $products = collect();
        if ($this->addingProducts && $collection && $collection['membership_supported']) {
            $products = Product::with(['images', 'variants'])->whereNotNull('shopify_id')
                ->whereNotIn('shopify_id', array_column($collection['products'], 'id'))
                ->where(fn ($query) => $query->where('title', 'like', '%'.$this->productSearch.'%')
                    ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', '%'.$this->productSearch.'%')))
                ->orderBy('title')->limit(60)->get()->unique('shopify_id');
        }

        $showProductList = $draft && ($this->activeTab === 'products'
            || filled($this->managingProductGid)
            || filled($this->managingSiblingProductGid)
            || filled($this->managingTagProductGid)
            || filled($this->managingComplementaryProductGid));
        $filteredParentProducts = [];
        $productMembership = '0';
        if ($showProductList) {
            $source = $draft->desired['collections'][$draft->collection_gid]['products'] ?? [];
            $ids = array_column($source, 'id');
            $sorted = $ids;
            sort($sorted);
            $productMembership = md5(implode('|', $sorted));
            $filteredParentProducts = $this->presentProducts($draft, $source);
        }
        $productIndex = collect($filteredParentProducts)->keyBy('id');
        $managedProduct = $this->managingProductGid ? $productIndex->get($this->managingProductGid) : null;
        $siblingProduct = $this->managingSiblingProductGid ? $productIndex->get($this->managingSiblingProductGid) : null;
        $tagProduct = $this->managingTagProductGid ? $productIndex->get($this->managingTagProductGid) : null;
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
            ? $productIndex->get($this->managingComplementaryProductGid)
            : null;
        $progressGid = $draft->collection_gid ?? ($this->hydratingCollection ? $this->selectedCollection : null);
        $syvProgress = is_string($progressGid) ? Cache::get(ShopYourVibeWorkflow::progressKey($progressGid)) : null;
        $hydratingParent = $this->hydratingCollection
            ? collect($this->parents)->firstWhere('gid', $this->selectedCollection)
            : null;

        $parentProductIds = $draft
            ? array_fill_keys(array_column($draft->desired['collections'][$draft->collection_gid]['products'] ?? [], 'id'), true)
            : [];
        $productTotal = count($parentProductIds);
        $productsLoaded = $draft && ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $draft->collection_gid);
        $hydrationState = is_array($syvProgress) ? ($syvProgress['state'] ?? null) : null;
        $hydrationTerminal = in_array($hydrationState, ['success', 'failed', 'skipped'], true);
        $statusPoll = (! $productsLoaded && $this->hydratingCollection && ! $hydrationTerminal)
            || ($this->refreshingCollection && ! $hydrationTerminal)
            || ($draft && in_array($draft->status, ['pushing', 'creating', 'deleting'], true))
            || ($draft && $draft->pending && $draft->status === 'pending' && filled($draft->progress) && empty($draft->remote_jobs));
        $productCountLabel = $productsLoaded
            ? (string) $productTotal
            : (($draft?->pending || $hydrationState === 'failed') ? 'Not loaded' : 'Loading…');
        $vibeCountLabel = ($productsLoaded || ($draft && $draft->pending))
            ? (string) count($draft->desired['cards'] ?? [])
            : ($hydrationState === 'failed' ? 'Not loaded' : 'Loading…');

        return compact('draft', 'parents', 'pendingDrafts', 'operationDrafts', 'card', 'collection', 'products', 'managedProduct', 'filteredParentProducts', 'assignmentAdds', 'assignmentAddDetails', 'assignmentRemovals', 'complementaryProduct', 'productMembership', 'siblingProduct', 'tagProduct', 'syvProgress', 'statusPoll', 'hydratingParent', 'parentProductIds', 'productTotal', 'productsLoaded', 'hydrationState', 'productCountLabel', 'vibeCountLabel') + [
            'summary' => $draft && $this->confirmingPush ? app(ShopYourVibeWorkflow::class)->summary($draft) : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function presentProducts(?ShopYourVibeDraft $draft, array $products): array
    {
        $membershipLabels = collect($this->vibeMappings)
            ->filter(fn ($mapping): bool => filled($mapping['membership_tag'] ?? null))
            ->mapWithKeys(fn ($mapping): array => [
                mb_strtolower(trim((string) $mapping['membership_tag'])) => $mapping['collection_name'],
            ]);

        return collect($this->withPlacementInfo($draft, $this->decorateProductCards($products)))
            ->map(function (array $product) use ($membershipLabels): array {
                if ($patch = $this->productPatches[$product['id'] ?? ''] ?? null) {
                    $product = array_replace($product, $patch);
                }
                $productTags = collect($product['tags'] ?? [])->map(fn ($tag): string => mb_strtolower(trim((string) $tag)));
                $product['vibe_assignments'] = $membershipLabels
                    ->filter(fn ($_name, string $tag): bool => $productTags->contains($tag))
                    ->values()
                    ->all();
                unset($product['shopify_metafields'], $product['tags']);

                return $product;
            })
            ->values()
            ->all();
    }
}
