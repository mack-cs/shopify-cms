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
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
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

    public string $collectionSearch = '';

    public string $selectedCollectionGid = '';

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
        $this->collectionSearch = '';
        $this->selectedCollectionGid = '';
        $this->loadError = null;
        $this->dispatch('open-modal', id: 'shop-your-vibe-collections');
    }

    public function closeCollectionPicker(): void
    {
        $this->guard();
        $this->addingParent = false;
        $this->addingCard = false;
        $this->collectionSearch = '';
        $this->selectedCollectionGid = '';
    }

    public function updatedCollectionSearch(): void
    {
        $this->selectedCollectionGid = '';
    }

    public function confirmCollectionSelection(): void
    {
        $this->guard();
        if ((! $this->addingParent && ! $this->addingCard) || $this->selectedCollectionGid === '') {
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
        $collections = collect();
        if ($this->addingParent || $this->addingCard) {
            $collections = ShopifyCollection::query()->whereNotNull('shopify_id')
                ->whereIn('id', ShopifyCollection::query()->selectRaw('MAX(id)')->groupBy('shopify_id'))
                ->where(fn ($query) => $query->where('title', 'like', '%'.$this->collectionSearch.'%')->orWhere('handle', 'like', '%'.$this->collectionSearch.'%'))
                ->when($this->addingParent, fn ($query) => $query->whereNotIn('shopify_id', collect($this->parents)->pluck('gid')))
                ->orderBy('title')->limit(60)->get()->unique('shopify_id');
        }
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

        return compact('draft', 'parents', 'pendingDrafts', 'collections', 'card', 'collection', 'products') + [
            'summary' => $draft && $this->confirmingPush ? app(ShopYourVibeWorkflow::class)->summary($draft) : [],
        ];
    }
}
