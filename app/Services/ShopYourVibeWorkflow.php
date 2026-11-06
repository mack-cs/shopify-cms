<?php

namespace App\Services;

use App\Exceptions\ShopYourVibeBusyException;
use App\Exceptions\ShopYourVibeCollectionUnavailableException;
use App\Models\ChangeLog;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\ShopYourVibeDraft;
use App\Models\SyvCollectionSortState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ShopYourVibeWorkflow
{
    public const PARENT_PREFIX_WARNING = "The collection name is added automatically. We've removed it from your vibe name.";

    public const LOADING_PRODUCTS_MESSAGE = 'Retrieving products from Shopify. You can continue using the CMS while this runs.';

    public const WAITING_PRODUCTS_MESSAGE = 'Waiting to load collection products…';

    public const PREPARING_PRODUCTS_MESSAGE = 'Preparing product display…';

    public const HYDRATION_FAILED_MESSAGE = "Shopify did not return this collection's products. Retry to load them.";

    public const HYDRATION_SUCCESS_MESSAGE = 'Collection loaded successfully.';

    /**
     * @return array{stage: string, done: int, total: int|null, percent: int|null, message: string}
     */
    public static function productProgressPayload(string $parentGid, string $gid, int $done, int $total, string $title = ''): array
    {
        $label = trim($title);
        if ($gid !== '' && $gid !== $parentGid) {
            $stage = $label !== '' ? 'Loading linked collection '.$label : 'Loading a linked collection';
        } else {
            $stage = $label !== '' ? 'Loading '.$label : 'Loading this collection';
        }
        $reportedTotal = $total > 0 ? $total : null;
        $percent = $reportedTotal ? (int) floor(($done / $reportedTotal) * 100) : null;

        return [
            'stage' => $stage,
            'done' => $done,
            'total' => $reportedTotal,
            'percent' => $percent,
            'message' => $reportedTotal ? "{$stage}: {$done} of {$reportedTotal} products — {$percent}%" : $stage,
        ];
    }

    public static function productProgressMessage(string $parentGid, string $gid, int $done, int $total, string $title = ''): string
    {
        return self::productProgressPayload($parentGid, $gid, $done, $total, $title)['message'];
    }

    private const VIBE_SEPARATOR = '[\s\-–—:\/|]+';

    private ?string $lockToken = null;

    public function __construct(private readonly ShopYourVibeShopify $shopify) {}

    public function trackRefreshAttempt(?callable $guard): void
    {
        $this->shopify->refreshGuard = $guard;
    }

    public static function progressKey(string $gid): string
    {
        return 'syv-progress:'.$gid;
    }

    /**
     * A loaded collection always stores products as an array, including a confirmed empty collection.
     * A legacy shell has the parent title only, so this array is absent.
     *
     * @param  array<string, mixed>|null  $state
     */
    public static function productsAreLoaded(?array $state, ?string $gid): bool
    {
        if (! is_string($gid) || $gid === '' || ! is_array($state)) {
            return false;
        }

        return is_array($state['collections'][$gid]['products'] ?? null);
    }

    public function trackProductProgress(?callable $progress): void
    {
        $this->shopify->productProgress = $progress;
    }

    /** Uppercase only. Standalone AND stays so it can still be typed. */
    public static function typingVibeName(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * @return array{name:string,removed_parent:bool,empty:bool,invalid:bool}
     */
    public static function normalizeVibeName(string $value, string $parentTitle): array
    {
        $name = self::typingVibeName(trim($value));
        $removed = false;
        $words = self::parentWords($parentTitle);
        if ($words !== []) {
            $body = implode(self::VIBE_SEPARATOR, array_map(fn (string $word): string => preg_quote($word, '/'), $words));
            $pattern = '/^'.$body.'(?:'.self::VIBE_SEPARATOR.'(.*))?$/u';
            if (preg_match($pattern, $name, $matches)) {
                $removed = true;
                $name = preg_replace('/^(?:'.self::VIBE_SEPARATOR.')+/u', '', trim((string) ($matches[1] ?? ''))) ?? '';
            }
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = trim(preg_replace('/\bAND\b/u', '&', $name) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $slug = Str::slug(str_replace('&', ' and ', $name));

        return [
            'name' => $name,
            'removed_parent' => $removed,
            'empty' => $name === '',
            'invalid' => $name !== '' && $slug === '',
        ];
    }

    public static function vibeNameError(array $normalized): ?string
    {
        if (($normalized['removed_parent'] ?? false) && ($normalized['name'] ?? '') === '') {
            return 'Please enter a vibe name only. The collection name is already included automatically.';
        }
        if ($normalized['invalid'] ?? false) {
            return 'Enter a vibe name using letters or numbers.';
        }
        if (($normalized['name'] ?? '') === '') {
            return 'A vibe name is required.';
        }

        return null;
    }

    public static function shopifyCollectionTitle(string $parentTitle, string $normalizedVibe): string
    {
        $parent = self::typingVibeName(trim($parentTitle));

        return trim($parent.($normalizedVibe !== '' ? ' '.$normalizedVibe : ''));
    }

    public static function contextualHandle(string $parentHandle, string $normalizedVibe): string
    {
        $vibe = Str::slug(str_replace('&', ' and ', $normalizedVibe));

        return collect([Str::slug($parentHandle), $vibe])->filter()->implode('-');
    }

    public static function parentHandle(array $parent): string
    {
        $handle = trim((string) ($parent['handle'] ?? ''));
        if ($handle !== '') {
            return Str::slug($handle);
        }

        return Str::slug((string) ($parent['title'] ?? ''));
    }

    /**
     * @return array<int, string>
     */
    private static function parentWords(string $parentTitle): array
    {
        $parts = preg_split('/'.self::VIBE_SEPARATOR.'/u', self::typingVibeName(trim($parentTitle)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values($parts ?: []);
    }

    public function open(string $gid): ShopYourVibeDraft
    {
        $this->shopify->gid($gid, 'Collection');
        if ($draft = ShopYourVibeDraft::where('collection_gid', $gid)->first()) {
            return $draft;
        }
        $this->shopify->definition();
        $state = $this->shopify->parent($gid);
        $state['collections'][$gid] = $this->shopify->collection($gid);
        $state = $this->hydrateDraftPlacements($gid, $state);

        $created = ShopYourVibeDraft::firstOrCreate(['collection_gid' => $gid], [
            'snapshot' => $state, 'desired' => $state, 'refreshed_at' => now(),
        ]);
        if ($created->wasRecentlyCreated) {
            app(ShopYourVibeSorting::class)->rememberVerifiedDraft($created, $state);
        }

        return $created;
    }

    public function refresh(int $id, int $revision, bool $discard = false, bool $strictRevision = false, ?int $actorId = null, ?string $refreshToken = null): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision, false, $strictRevision);
        $actorId ??= auth()->id();
        app(ShopYourVibeEditing::class)->readPublication($draft, $actorId);
        if ($draft->pending && (auth()->check() || ! empty($draft->editing_state))) {
            throw new RuntimeException('Review and discard pending changes with a reason before refreshing. Refresh cannot discard another editor’s work.');
        }
        $revision = $draft->revision;
        if ($draft->pending && ! $discard) {
            throw new RuntimeException('You have pending changes. Confirm refresh and discard changes first.');
        }
        if ($draft->remote_jobs) {
            throw new RuntimeException('A Shopify operation is still awaiting confirmation. Retry the push before refreshing.');
        }
        if (! empty($draft->operation) && ! $discard) {
            throw new RuntimeException('A Shopify operation is still awaiting confirmation. Retry it before refreshing.');
        }
        $state = $this->pullState($draft);
        // Different parents share catalogue products. Serialize only publication,
        // after network reads, so the mirror sees the latest committed provenance.
        $refreshed = Cache::lock('syv-confirmed-state-publication', 960)->block(5, fn () => DB::transaction(function () use ($id, $revision, $state, $discard, $actorId, $refreshToken) {
            // Never publish an older lookup over a newer deletion or local edit.
            $draft = $this->editable($id, $revision, true, true);
            if ($refreshToken !== null) {
                app(ShopYourVibeRefresh::class)->assertCurrent($draft, $refreshToken);
            }
            app(ShopYourVibeEditing::class)->readPublication($draft, $actorId);
            if ($draft->pending && ! $discard) {
                throw new RuntimeException('You have pending changes. Confirm refresh and discard changes first.');
            }
            if (! empty($draft->operation) && ! $discard) {
                throw new RuntimeException('A Shopify operation is still awaiting confirmation. Retry it before refreshing.');
            }
            // Another parent may have confirmed deletion while this lookup ran.
            foreach (ShopYourVibeCollectionMapping::confirmedMissingIds() as $gid) {
                if (isset($state['collections'][$gid])) {
                    $state['collections'][$gid] = $this->keptUnavailableCollection($draft, $gid);
                    $state['missing_collection_ids'][] = $gid;
                }
            }
            $state['missing_collection_ids'] = array_values(array_unique($state['missing_collection_ids'] ?? []));
            $snapshot = $state;
            $desired = $state;
            foreach ($state['missing_collection_ids'] ?? [] as $gid) {
                $affectedKeys = array_column(array_filter(array_merge(
                    $draft->snapshot['cards'] ?? [], $draft->desired['cards'] ?? [],
                ), fn ($card) => ($card['collection_gid'] ?? null) === $gid), 'key');
                $beforeCards = collect($draft->snapshot['cards'] ?? [])->keyBy('key');
                $afterCards = collect($draft->desired['cards'] ?? [])->keyBy('key');
                $changedKeys = array_values(array_filter($affectedKeys, fn ($key) =>
                    $this->fields($beforeCards->get($key)) !== $this->fields($afterCards->get($key))
                    || array_search($key, array_column($draft->snapshot['cards'] ?? [], 'key'), true) !== array_search($key, array_column($draft->desired['cards'] ?? [], 'key'), true)
                ));
                // Keep both sides of a missing target's pending edit, even when
                // the user discards other edits to refresh valid collections.
                if (isset($draft->snapshot['collections'][$gid])) {
                    $snapshot['collections'][$gid] = $draft->snapshot['collections'][$gid];
                    $snapshot['collections'][$gid]['unavailable'] = true;
                }
                foreach (['snapshot', 'desired'] as $side) {
                    if ($side === 'snapshot') {
                        $target = &$snapshot;
                    } else {
                        $target = &$desired;
                    }
                    $target['collections'][$gid] ??= $this->keptUnavailableCollection($draft, $gid);
                    $target['collections'][$gid]['card_history'] ??= [
                        'snapshot' => $beforeCards->only($affectedKeys)->values()->all(),
                        'desired' => $afterCards->only($affectedKeys)->values()->all(),
                    ];
                    $oldKeys = array_column($draft->{$side}['cards'] ?? [], 'key');
                    $target['cards'] = array_values(array_filter($target['cards'], fn ($card) => ! in_array($card['key'], $changedKeys, true) || in_array($card['key'], $oldKeys, true)));
                    foreach ($draft->{$side}['cards'] ?? [] as $oldPosition => $oldCard) {
                        if (in_array($oldCard['key'], $changedKeys, true)) {
                            if (($oldCard['collection_gid'] ?? null) !== $gid) {
                                $oldCard['unavailable_collection_gid'] = $gid;
                            }
                            $index = array_search($oldCard['key'], array_column($target['cards'], 'key'), true);
                            if ($index !== false) {
                                $target['cards'][$index] = $oldCard;
                                if ($side === 'desired' && $index !== $oldPosition) {
                                    array_splice($target['cards'], $index, 1);
                                    array_splice($target['cards'], min($oldPosition, count($target['cards'])), 0, [$oldCard]);
                                }
                            } else {
                                $target['cards'][] = $oldCard;
                            }
                        }
                    }
                    foreach (['delete_cards', 'delete_collections'] as $field) {
                        foreach ($draft->{$side}[$field] ?? [] as $key => $deletion) {
                            if (($deletion['gid'] ?? $deletion['card']['collection_gid'] ?? null) === $gid) {
                                $target[$field][$key] = $deletion;
                            }
                        }
                    }
                    unset($target);
                }
            }
            $missingPending = $this->different($snapshot, $desired);
            $desired = $this->stagePublishedPlacements($desired, $draft->desired ?? []);
            $pending = $this->different($snapshot, $desired);
            $draft->update(['snapshot' => $snapshot, 'desired' => $desired, 'pending' => $pending, 'status' => $pending ? 'failed' : 'synced',
                'last_error' => $pending ? ($missingPending
                    ? ShopYourVibeCollectionMapping::MISSING_MESSAGE.'. Pending changes to this collection were kept and cannot be pushed.'
                    : 'Prepared product positions are pending. Review them before pushing to Shopify.') : null,
                'progress' => [], 'remote_jobs' => [], 'operation' => null,
                'revision' => $draft->revision + 1, 'refreshed_at' => $pending ? $draft->refreshed_at : now()]);

            $this->deactivateMissingMappings($draft, $desired);
            app(ShopYourVibeSorting::class)->rememberVerifiedDraft($draft, $desired);
            $freshState = $state;
            $freshState['collections'] = array_filter($state['collections'], fn ($collection) => empty($collection['unavailable']));
            app(ShopYourVibeProductMirror::class)->mirrorFromDraftState($freshState);

            if ($refreshToken !== null) {
                if (! app(ShopYourVibeRefresh::class)->finish($id, $refreshToken, 'success', self::HYDRATION_SUCCESS_MESSAGE)) {
                    throw new \App\Exceptions\ShopYourVibeRefreshExpiredException('Refresh expired before publication completed. Existing products were kept.');
                }
            }

            return $draft;
        }));

        return $refreshed;
    }

    public function loadProducts(int $id, int $revision, string $key): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision);
        $revision = $draft->revision;
        $card = $this->card($draft->desired, $key);
        $gid = $card['collection_gid'] ?? throw new RuntimeException('Choose a synchronized collection link to manage this vibe’s products.');
        $this->assertCollectionAvailable($draft, $card['unavailable_collection_gid'] ?? $gid);
        if (isset($draft->desired['collections'][$gid])) {
            return $draft;
        }
        $collection = $this->shopify->collection($gid);
        $this->assertCollectionLink($card, $collection);

        return DB::transaction(function () use ($id, $revision, $gid, $collection) {
            $draft = $this->editable($id, $revision, true, true);
            app(ShopYourVibeEditing::class)->readPublication($draft, auth()->id());
            $desired = $draft->desired;
            $snapshot = $draft->snapshot;
            $desired['collections'][$gid] = $snapshot['collections'][$gid] = $collection;
            $desired = $this->hydrateDraftPlacements($draft->collection_gid, $desired, $desired);
            $snapshot = $this->hydrateDraftPlacements($draft->collection_gid, $snapshot, $desired);
            $draft->update(['desired' => $desired, 'snapshot' => $snapshot, 'revision' => $draft->revision + 1]);
            app(ShopYourVibeSorting::class)->rememberVerifiedDraft($draft, $desired);

            return $draft;
        });
    }

    /** Normal edits and drag actions deliberately perform database work only. */
    public function edit(int $id, int $revision, string $operation, array $input = []): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $revision, $operation, $input) {
            $collectionGid = is_string($input['collection_gid'] ?? null) ? $input['collection_gid'] : null;
            $sortOperation = $collectionGid !== null && in_array($operation, ['enable_manual', 'add_products', 'remove_product', 'reorder_products'], true);
            $membershipOperation = $collectionGid !== null && in_array($operation, ['add_products', 'remove_product'], true);
            $scopeKey = $sortOperation && array_key_exists('scope_revision', $input)
                ? \App\Services\ShopYourVibeEditing::sortScope($collectionGid)
                : null;
            if ($scopeKey !== null) {
                $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
                if (in_array($draft->status, ['pushing', 'deleting'], true) || ($draft->status === 'creating' && $operation !== 'reorder_products')) {
                    throw new RuntimeException($this->activeOperationMessage($draft));
                }
                $editing = app(ShopYourVibeEditing::class);
                $editing->assertScopeRevision($draft, $scopeKey, (int) $input['scope_revision']);
                if (auth()->check()) {
                    $editing->authorizeScope($draft, $scopeKey);
                }
            } elseif ($membershipOperation) {
                $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
                if (in_array($draft->status, ['pushing', 'deleting'], true)) {
                    throw new RuntimeException($this->activeOperationMessage($draft));
                }
                $parent = $draft->editing_state ?? [];
                unset($parent['locks']);
                if (app(ShopYourVibeEditing::class)->active($parent) && (int) ($parent['owner_id'] ?? 0) !== (int) auth()->id()) {
                    throw new RuntimeException(($parent['owner_name'] ?? 'Another editor').' owns this editing lock. Start editing explicitly when the scope is available.');
                }
            } else {
                $draft = $this->editable($id, $revision, true);
                app(ShopYourVibeEditing::class)->authorize($draft, $revision);
            }
            if ($draft->remote_jobs && ($collectionGid === null || isset($draft->remote_jobs[$collectionGid]))) {
                throw new RuntimeException('Shopify is still processing this draft. Retry the push to confirm completion before editing.');
            }
            $state = $draft->desired;
            if (in_array($operation, ['edit_card', 'remove_card'], true)) {
                $targetCard = $this->card($state, $input['key']);
                $this->assertCollectionAvailable($draft, $targetCard['unavailable_collection_gid'] ?? $targetCard['collection_gid'] ?? null);
            }
            if ($operation === 'add_card') {
                $this->assertCollectionAvailable($draft, $input['collection_gid'] ?? null);
            }
            switch ($operation) {
                case 'create_collection':
                    $importId = ShopifyCollection::where('shopify_id', $draft->collection_gid)->latest('id')->value('import_id')
                        ?? Import::orderByDesc('is_current')->latest('id')->value('id');
                    if (! $importId) {
                        throw new RuntimeException('Synchronize the collection catalogue before creating a collection.');
                    }
                    $input = $this->preparedCollectionInput($draft, $input);
                    $vibeName = $input['vibe_name'] ?? null;
                    $fields = Validator::make($input, [
                        'title' => 'required|string|max:255',
                        'handle' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                        'image' => ['nullable', 'regex:~^gid://shopify/MediaImage/\d+$~'],
                        'image_path' => ['nullable', 'regex:~^shop-your-vibe/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$~'],
                        'image_url' => 'nullable|url|max:4096',
                    ])->validate();
                    if (in_array($fields['handle'], array_column($state['new_collections'] ?? [], 'handle'), true)) {
                        throw new RuntimeException('This handle already exists. Choose that collection or enter a different handle.');
                    }
                    $this->assertHandleFreeInShopify($fields['handle']);
                    $key = (string) Str::uuid();
                    $gid = 'new:'.$key;
                    $state['new_collections'][$key] = $fields + [
                        'token' => $key,
                        'gid' => $gid,
                        'published' => false,
                        'import_id' => $importId,
                        'membership_tag' => $fields['handle'],
                    ];
                    $state['collections'][$gid] = ['gid' => $gid, 'title' => $fields['title'], 'handle' => $fields['handle'],
                        'sort' => 'MANUAL', 'manual_supported' => true, 'membership_supported' => false,
                        'detected_membership_tag' => $fields['handle'],
                        'enable_manual' => false, 'products' => []];
                    $state['cards'][] = ['key' => $key, 'id' => null, 'handle' => 'cms-vibe-'.$key,
                        'name' => $vibeName ?: $fields['title'], 'image' => $fields['image'] ?? '', 'image_url' => $fields['image_url'] ?? null,
                        'link' => rtrim(config('services.shopify.storefront_url'), '/').'/collections/'.$fields['handle'],
                        'collection_gid' => $gid];
                    break;
                case 'add_card':
                    $collection = $this->localCollection($input['collection_gid'] ?? '');
                    $key = (string) Str::uuid();
                    $state['cards'][] = ['key' => $key, 'id' => null, 'handle' => 'cms-vibe-'.$key,
                        'name' => $collection->title, 'image' => '', 'image_url' => null,
                        'link' => rtrim(config('services.shopify.storefront_url'), '/').'/collections/'.$collection->handle,
                        'collection_gid' => $collection->shopify_id];
                    break;
                case 'edit_card':
                    $card = $this->card($state, $input['key']);
                    $fields = Validator::make($input, ['name' => 'required|string|max:255', 'link' => 'required|string|max:2048',
                        'image' => ['present', 'nullable', 'regex:~^gid://shopify/MediaImage/\d+$~'],
                        'image_path' => ['nullable', 'regex:~^shop-your-vibe/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$~'],
                        'image_url' => 'nullable|url:http,https|max:4096'])->validate();
                    $this->validateLink($fields['link']);
                    if (str_starts_with($fields['link'], '/')) {
                        $fields['link'] = rtrim(config('services.shopify.storefront_url'), '/').$fields['link'];
                    }
                    $fields['image'] = $fields['image'] ?? '';
                    $fields['collection_gid'] = $this->localLink($fields['link']);
                    $this->assertCollectionAvailable($draft, $fields['collection_gid']);
                    if (str_starts_with($card['collection_gid'] ?? '', 'new:')) {
                        if ($fields['link'] !== $card['link']) {
                            throw new RuntimeException('Push the new collection before changing its link.');
                        }
                        $fields['collection_gid'] = $card['collection_gid'];
                        if ($fields['image'] !== $card['image']) {
                            $state['new_collections'][$card['key']]['image'] = $fields['image'];
                            unset($state['new_collections'][$card['key']]['image_path']);
                        }
                        if (! empty($fields['image_path'])) {
                            $state['new_collections'][$card['key']]['image_path'] = $fields['image_path'];
                            $state['new_collections'][$card['key']]['image_url'] = $fields['image_url'] ?? null;
                            unset($state['new_collections'][$card['key']]['image']);
                        }
                    }
                    foreach ($state['cards'] as &$item) {
                        if ($item['key'] === $card['key']) {
                            $item = array_replace($item, $fields);
                        }
                    }
                    unset($item);
                    break;
                case 'remove_card':
                    $removed = $this->card($state, $input['key']);
                    if (($input['delete_collection'] ?? false) === true) {
                        $gid = $removed['collection_gid'];
                        if (! $gid || $gid === $draft->collection_gid) {
                            throw new RuntimeException('Choose a vibe linked to a collection other than the parent collection being edited.');
                        }
                        $creation = collect($state['new_collections'] ?? [])->firstWhere('gid', $gid);
                        $linked = str_starts_with($gid, 'new:') ? $state['collections'][$gid] : $this->localCollection($gid)->toArray();
                        $this->assertCollectionLink($removed, $linked);
                        $state['delete_collections'][$gid] = ['gid' => $gid, 'handle' => $linked['handle'],
                            'title' => $linked['title'], 'token' => $creation['token'] ?? null, 'deleted' => false];
                    }
                    if (($input['delete_from_shopify'] ?? false) === true || ($input['delete_collection'] ?? false) === true) {
                        $original = collect($draft->snapshot['cards'])->firstWhere('key', $removed['key']) ?? $removed;
                        $state['delete_cards'][$removed['key']] = ['card' => $original, 'deleted' => false];
                    }
                    $state['cards'] = array_values(array_filter($state['cards'], fn ($card) => $card['key'] !== $input['key']));
                    // Do not push product edits for a collection no longer represented in this layout.
                    $gids = array_merge([$draft->collection_gid], array_column($state['cards'], 'collection_gid'));
                    $state['collections'] = array_intersect_key($state['collections'], array_flip(array_filter(array_merge($gids, $state['missing_collection_ids'] ?? []))));
                    break;
                case 'reorder_cards':
                    foreach ($state['cards'] as $position => $card) {
                        if ($this->collectionIsUnavailable($draft, $card['unavailable_collection_gid'] ?? $card['collection_gid'] ?? null)
                            && ($input['keys'][$position] ?? null) !== $card['key']) {
                            throw new RuntimeException(ShopYourVibeCollectionMapping::MISSING_MESSAGE.'. Sorting this card is disabled.');
                        }
                    }
                    $state['cards'] = $this->reorder($state['cards'], $input['keys'], 'key');
                    break;
                case 'enable_manual':
                case 'add_products':
                case 'remove_product':
                case 'reorder_products':
                    $gid = $input['collection_gid'];
                    $this->assertCollectionAvailable($draft, $gid);
                    if (($gid !== $draft->collection_gid && ! in_array($gid, array_column($state['cards'], 'collection_gid'), true)) || ! isset($state['collections'][$gid])) {
                        throw new RuntimeException('Open this vibe’s products first.');
                    }
                    $collection = &$state['collections'][$gid];
                    if ($operation === 'enable_manual') {
                        if (! $collection['manual_supported'] || ($input['confirmed'] ?? false) !== true) {
                            throw new RuntimeException('Manual sorting requires explicit confirmation and Shopify support.');
                        }
                        $collection['enable_manual'] = $collection['sort'] !== 'MANUAL';
                    } elseif ($operation === 'reorder_products') {
                        $prompt = $draft->editing_state['membership_prompt'] ?? null;
                        if (is_array($prompt) && ($prompt['gid'] ?? null) === $gid) {
                            throw new RuntimeException('Shopify membership for '.($prompt['title'] ?? 'this collection').' no longer matches this draft. Refresh membership before sorting.');
                        }
                        if (! $this->manualSortingSupported($collection)) {
                            throw new RuntimeException('Manual sorting is not supported for this collection.');
                        }
                        if ($collection['sort'] !== 'MANUAL') {
                            $collection['manual_supported'] = true;
                            $collection['enable_manual'] = true;
                        }
                        $ids = $this->orderWithCurrentMembership($collection['products'], $input['ids']);
                        $beforeIds = array_column($collection['products'], 'id');
                        $collection['products'] = $this->reorder($collection['products'], $ids, 'id');
                        $explicitIds = array_values(array_unique(array_filter(
                            (array) ($input['explicit_ids'] ?? []),
                            fn ($id): bool => is_string($id) && $id !== ''
                        )));
                        $this->markPlacedDrafts($state, $gid, $collection['products'], $explicitIds);
                        if ($explicitIds !== [] && auth()->check()) {
                            app(ShopYourVibeSorting::class)->recordExplicitMoves(
                                $draft,
                                $gid,
                                $beforeIds,
                                array_column($collection['products'], 'id'),
                                $explicitIds,
                            );
                        }
                    } else {
                        if (! $collection['membership_supported']) {
                            throw new RuntimeException('Shopify rules control membership in this automated collection.');
                        }
                        if ($operation === 'remove_product') {
                            $collection['products'] = array_values(array_filter($collection['products'], fn ($product) => $product['id'] !== $input['product_gid']));
                        } else {
                            if (count($input['ids']) > 250) {
                                throw new RuntimeException('Select up to 250 products at a time.');
                            }
                            $existing = array_column($collection['products'], 'id');
                            foreach (array_unique($input['ids']) as $productGid) {
                                $this->shopify->gid($productGid, 'Product');
                                if (in_array($productGid, $existing, true)) {
                                    continue;
                                }
                                $product = Product::with(['images', 'variants'])->where('shopify_id', $productGid)->latest('id')->firstOrFail();
                                $collection['products'][] = ['id' => $productGid, 'title' => $product->title,
                                    'image' => $product->images->sortBy('position')->first()?->src, 'sku' => $product->variants->first()?->sku];
                                $existing[] = $productGid;
                            }
                        }
                    }
                    unset($collection);
                    break;
                default:
                    throw new RuntimeException('Unknown workflow edit.');
            }
            $represented = array_values(array_unique(array_filter(array_merge(
                [$draft->collection_gid],
                array_column($state['cards'], 'collection_gid'),
                $state['missing_collection_ids'] ?? [],
            ))));
            foreach ($state['delete_collections'] ?? [] as $gid => $deletion) {
                if (in_array($gid, $represented, true)) {
                    throw new RuntimeException('Another vibe in this layout uses the collection selected for deletion. Remove that vibe first.');
                }
            }
            $state['collections'] = array_intersect_key($state['collections'], array_flip($represented));
            if (isset($state['new_collections'])) {
                $state['new_collections'] = array_filter($state['new_collections'], fn ($item) => in_array($item['gid'], $represented, true));
            }
            $pending = $this->different($draft->snapshot, $state);
            $overlayCollection = $scopeKey !== null || $membershipOperation;
            $nextRevision = $overlayCollection ? $draft->revision : $draft->revision + 1;
            if ($overlayCollection && isset($state['collections'][$collectionGid])) {
                $latest = $draft->desired;
                $latest['collections'][$collectionGid] = $state['collections'][$collectionGid];
                if (isset($state['draft_placements'])) {
                    $latest['draft_placements'] = $state['draft_placements'];
                }
                $state = $latest;
                $pending = $this->different($draft->snapshot, $state);
            }
            $draft->update(['desired' => $state, 'pending' => $pending, 'status' => $pending ? 'pending' : 'synced',
                'last_error' => null, 'revision' => $nextRevision]);
            if ($scopeKey !== null) {
                app(ShopYourVibeEditing::class)->recordScope($draft, $scopeKey, $operation, $input);
                app(ShopYourVibeEditing::class)->bumpScope($draft, $scopeKey);
                app(ShopYourVibeSorting::class)->invalidateUnpublishedApproval($draft, $collectionGid);
            } elseif ($membershipOperation) {
                app(ShopYourVibeSorting::class)->invalidateUnpublishedApproval($draft, $collectionGid);
                ChangeLog::create([
                    'changed_by' => auth()->id(),
                    'source' => 'shop_your_vibe_membership',
                    'model_type' => ShopYourVibeDraft::class,
                    'model_id' => $draft->id,
                    'field' => 'collection_membership',
                    'old_value' => $collectionGid,
                    'new_value' => json_encode(array_column($state['collections'][$collectionGid]['products'] ?? [], 'id')),
                ]);
            } else {
                app(ShopYourVibeEditing::class)->record($draft, $operation, $input);
                if ($sortOperation) {
                    app(ShopYourVibeSorting::class)->invalidateUnpublishedApproval($draft, $collectionGid);
                }
            }

            return $draft;
        });
    }

    public function queue(int $id, int $revision): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $revision) {
            $draft = $this->editable($id, $revision, true);
            app(ShopYourVibeEditing::class)->authorize($draft, $revision);
            if (! empty($draft->operation) && in_array($draft->operation['type'] ?? null, ['create_vibe', 'delete_vibe'], true)) {
                throw new RuntimeException('Finish or retry the in-progress Vibe operation before pushing other changes.');
            }
            if (! $draft->pending) {
                throw new RuntimeException('There are no pending changes to push.');
            }
            if ($this->pendingExplicitSortingOnly($draft)) {
                throw new RuntimeException('There are no eligible non-sorting changes to push. Publish sorting from the collection that was sorted.');
            }
            $this->assertMissingTargetsUnchanged($draft, $draft->desired, $draft->snapshot);
            app(ShopYourVibeSorting::class)->assertPushAllowed($draft);
            $draft->update(['status' => 'pushing', 'last_error' => null, 'operation' => null, 'revision' => $draft->revision + 1]);
            app(ShopYourVibeEditing::class)->ticket($draft);

            return $draft;
        });
    }

    /** @return array{0: ShopYourVibeDraft, 1: array<string, mixed>} */
    public function queueCollection(int $id, int $scopeRevision, string $gid): array
    {
        return DB::transaction(function () use ($id, $scopeRevision, $gid) {
            $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
            if (in_array($draft->status, ['pushing', 'creating', 'deleting'], true)) {
                throw new RuntimeException($this->activeOperationMessage($draft));
            }
            $editing = app(ShopYourVibeEditing::class);
            $scope = ShopYourVibeEditing::sortScope($gid);
            $editing->assertScopeRevision($draft, $scope, $scopeRevision, false);
            $editing->authorizeScope($draft, $scope);
            app(ShopYourVibeSorting::class)->assertPushAllowed($draft, $gid);
            $ticket = $editing->ticketScope($draft, $scope, $gid);

            return [$draft->fresh() ?? $draft, $ticket];
        });
    }

    public function queueCreateVibe(int $id, int $revision, array $input): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision);
        $sortOnly = $this->pendingIsCollectionSortOnly($draft);
        if (! $sortOnly) {
            $this->assertImmediateAllowed($draft);
        }
        if (! empty($draft->operation)) {
            throw new RuntimeException('A previous Vibe operation did not finish. Retry it, or refresh from Shopify before starting another.');
        }
        $payload = $this->prepareCreatePayload($draft, $input);

        return DB::transaction(function () use ($id, $revision, $payload, $sortOnly) {
            $draft = $this->editable($id, $revision, true);
            app(ShopYourVibeEditing::class)->authorize($draft, $revision);
            if (! $sortOnly) {
                $this->assertImmediateAllowed($draft);
            }
            if (! empty($draft->operation)) {
                throw new RuntimeException('A previous Vibe operation did not finish. Retry it, or refresh from Shopify before starting another.');
            }
            $attempt = (string) Str::uuid();

            $draft->update([
                'status' => 'creating',
                'pending' => $draft->pending,
                'last_error' => null,
                'progress' => ['Creating Vibe in Shopify...'],
                'operation' => [
                    'id' => $attempt,
                    'attempt' => $attempt,
                    'type' => 'create_vibe',
                    'payload' => $payload,
                    'checkpoints' => [
                        'image_gid' => $payload['image'] ?: null,
                        'collection_gid' => $payload['mode'] === 'existing' ? $payload['collection_gid'] : null,
                        'handle' => $payload['handle'],
                        'card_id' => null,
                        'card_handle' => $payload['card_handle'],
                        'attached' => false,
                        'published' => $payload['mode'] !== 'new',
                    ],
                ],
                'revision' => $draft->revision + 1,
            ]);

            app(ShopYourVibeEditing::class)->ticket($draft, 'create_vibe');
            return $draft->refresh();
        });
    }

    public function queueDeleteVibe(int $id, int $revision, array $input): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision);
        $this->assertImmediateAllowed($draft);
        if (! empty($draft->operation)) {
            throw new RuntimeException('A previous Vibe operation did not finish. Retry it, or refresh from Shopify before starting another.');
        }
        $payload = $this->prepareDeletePayload($draft, $input);
        if ($payload['mode'] === 'collection') {
            $this->shopify->collectionForDeletion($payload['collection']);
        }
        if (in_array($payload['mode'], ['permanent', 'collection'], true)) {
            $this->shopify->assertCardDeletable($payload['card'], $draft->collection_gid);
        }

        return DB::transaction(function () use ($id, $revision, $payload) {
            $draft = $this->editable($id, $revision, true);
            app(ShopYourVibeEditing::class)->authorize($draft, $revision);
            $this->assertImmediateAllowed($draft);
            if (! empty($draft->operation)) {
                throw new RuntimeException('A previous Vibe operation did not finish. Retry it, or refresh from Shopify before starting another.');
            }
            $attempt = (string) Str::uuid();
            $draft->update([
                'status' => 'deleting',
                'pending' => false,
                'last_error' => null,
                'progress' => ['Deleting Vibe in Shopify...'],
                'operation' => [
                    'id' => $attempt,
                    'attempt' => $attempt,
                    'type' => 'delete_vibe',
                    'payload' => $payload,
                    'checkpoints' => [
                        'detached' => false,
                        'card_deleted' => $payload['mode'] === 'layout',
                        'collection_deleted' => $payload['mode'] !== 'collection',
                    ],
                ],
                'revision' => $draft->revision + 1,
            ]);

            app(ShopYourVibeEditing::class)->ticket($draft, 'delete_vibe');
            return $draft->refresh();
        });
    }

    public function resumeOperation(int $id, ?int $revision = null): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $revision) {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($id);
            app(ShopYourVibeEditing::class)->authorize($draft, $revision ?? $draft->revision);
            if ($draft->status !== 'failed' || ! in_array($draft->operation['type'] ?? null, ['create_vibe', 'delete_vibe'], true)) {
                throw new RuntimeException('There is no failed Shopify operation to retry.');
            }
            if ($draft->pending && $this->different($draft->snapshot, $draft->desired) && ! $this->pendingIsCollectionSortOnly($draft)) {
                throw new RuntimeException('This collection has pending changes. Push or discard them before retrying.');
            }
            $operation = $draft->operation;
            $operation['attempt'] = (string) Str::uuid();
            $draft->update([
                'status' => $operation['type'] === 'create_vibe' ? 'creating' : 'deleting',
                'pending' => $draft->pending,
                'last_error' => null,
                'operation' => $operation,
                'revision' => $draft->revision + 1,
            ]);

            app(ShopYourVibeEditing::class)->ticket($draft, $operation['type']);
            return $draft->refresh();
        });
    }

    public function runImmediate(int $id, string $attempt, ?array $authorization = null): void
    {
        $draft = ShopYourVibeDraft::findOrFail($id);
        if (! in_array($draft->status, ['creating', 'deleting'], true) || ($draft->operation['attempt'] ?? null) !== $attempt) {
            return;
        }
        $authorization ??= auth()->check() ? ($draft->editing_state['push'] ?? null) : null;
        app(ShopYourVibeEditing::class)->validateJob($id, $authorization);
        $this->holdOperationLock($draft);
        $this->shopify->beforeMutation = function () use ($id, $authorization): void {
            $this->assertOperationOwnership();
            app(ShopYourVibeEditing::class)->validateJob($id, $authorization, false);
        };
        try {
            $draft->refresh();
            if (! in_array($draft->status, ['creating', 'deleting'], true) || ($draft->operation['attempt'] ?? null) !== $attempt) {
                return;
            }
            if ($draft->operation['type'] === 'create_vibe') {
                $this->runCreate($draft);
            } elseif ($draft->operation['type'] === 'delete_vibe') {
                $this->runDelete($draft);
            }
        } catch (ShopYourVibeBusyException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $fresh = $draft->fresh() ?? $draft;
            if (($fresh->operation['attempt'] ?? null) === $attempt && in_array($fresh->status, ['creating', 'deleting', 'failed'], true)) {
                $fresh->update([
                    'status' => 'failed',
                    'pending' => $fresh->pending || $this->different($fresh->snapshot ?? [], $fresh->desired ?? []),
                    'last_error' => $e->getMessage(),
                    'revision' => $fresh->revision + 1,
                ]);
            }
        } finally {
            $this->shopify->beforeMutation = null;
            $this->releaseOperationLock($draft);
        }
    }

    public function confirmCompletedPush(int $id): ShopYourVibeDraft
    {
        $draft = ShopYourVibeDraft::findOrFail($id);
        if (! $draft->pending || $draft->status === 'pushing' || $draft->status === 'failed') {
            return $draft;
        }
        if ($draft->remote_jobs || empty($draft->progress)) {
            return $draft;
        }

        $state = $draft->desired;
        if (collect($state['cards'] ?? [])->contains(fn ($card) => empty($card['id']) || str_starts_with((string) ($card['collection_gid'] ?? ''), 'new:'))) {
            return $draft;
        }
        if (collect($state['new_collections'] ?? [])->contains(fn ($collection) => empty($collection['published']) || str_starts_with((string) ($collection['gid'] ?? ''), 'new:'))) {
            return $draft;
        }
        if (collect($state['delete_cards'] ?? [])->contains(fn ($deletion) => empty($deletion['deleted']))
            || collect($state['delete_collections'] ?? [])->contains(fn ($deletion) => empty($deletion['deleted']))) {
            return $draft;
        }

        $this->assertMissingTargetsUnchanged($draft, $state, $draft->snapshot);
        $allowedUnavailable = array_values(array_filter(array_keys($state['collections']), fn ($gid) => $this->collectionIsUnavailable($draft, $gid)));
        $held = [];
        foreach (array_keys($state['collections'] ?? []) as $gid) {
            if (! is_string($gid) || ! $this->collectionSortHeldBack($draft, $gid)) {
                continue;
            }
            $held[$gid] = $state['collections'][$gid];
            if (isset($draft->snapshot['collections'][$gid])) {
                $state['collections'][$gid] = $draft->snapshot['collections'][$gid];
            }
        }
        $confirmed = $this->confirmedState($draft, $state, array_column($state['cards'] ?? [], 'id'), $allowedUnavailable);
        foreach ($held as $gid => $collection) {
            $confirmed['collections'][$gid] = $collection;
        }
        $this->markSortingPublished($draft, array_keys($held));
        $snapshot = $confirmed;
        foreach ($held as $gid => $collection) {
            if (isset($draft->snapshot['collections'][$gid])) {
                $snapshot['collections'][$gid] = $draft->snapshot['collections'][$gid];
            }
        }
        $pending = $held !== [];
        $draft->update(['snapshot' => $snapshot, 'desired' => $confirmed, 'pending' => $pending, 'status' => $pending ? 'pending' : 'synced',
            'last_error' => null, 'remote_jobs' => [], 'refreshed_at' => now(), 'revision' => $draft->revision + 1]);

        return $draft->refresh();
    }

    /** Called by the queue worker only after the explicit push action. */
    public function push(int $id, ?array $authorization = null): void
    {
        $draft = ShopYourVibeDraft::findOrFail($id);
        if ($draft->status !== 'pushing' || in_array($draft->operation['type'] ?? null, ['create_vibe', 'delete_vibe'], true)) {
            if (! is_string($authorization['scope_gid'] ?? null) || $authorization['scope_gid'] === '') {
                return;
            }
        }
        $authorization ??= auth()->check() ? ($draft->editing_state['push'] ?? null) : null;
        if (is_string($authorization['scope_gid'] ?? null) && $authorization['scope_gid'] !== '') {
            $this->pushScopedSort($id, $authorization);

            return;
        }
        app(ShopYourVibeEditing::class)->validateJob($id, $authorization);
        $this->holdOperationLock($draft);
        $this->shopify->beforeMutation = function () use ($id, $authorization): void {
            $this->assertOperationOwnership();
            app(ShopYourVibeEditing::class)->validateJob($id, $authorization, false);
        };
        try {
            $this->shopify->definition();
            $state = $draft->desired;
            $baseline = $draft->snapshot;
            $this->assertMissingTargetsUnchanged($draft, $state, $baseline);
            $allowedUnavailable = array_values(array_filter(
                array_unique(array_merge(array_keys($state['collections']), array_column($state['cards'], 'collection_gid'))),
                fn ($gid) => $this->collectionIsUnavailable($draft, $gid),
            ));
            $progress = [];
            // Resume asynchronous work before calculating another mutation for that collection.
            foreach (($draft->remote_jobs ?? []) as $gid => $job) {
                $this->waitForJob($job);
                $baseline['collections'][$gid] = $this->shopify->collection($gid);
                $jobs = $draft->remote_jobs;
                unset($jobs[$gid]);
                $draft->update(['remote_jobs' => $jobs, 'snapshot' => $baseline]);
            }
            $remote = $this->shopify->parent($draft->collection_gid);
            $desiredIds = array_column($state['cards'], 'id');
            if ($remote['reference_ids'] !== $baseline['reference_ids'] && $remote['reference_ids'] !== $desiredIds) {
                throw new RuntimeException('The preview layout changed in Shopify. Refresh and review before pushing.');
            }
            // Check every existing card before issuing any new mutations.
            $this->assertCardsUnchanged($baseline, $remote, $state['cards']);
            $currentCollections = [];
            $heldSorts = [];
            foreach ($state['delete_collections'] ?? [] as $deletion) {
                if (! $deletion['deleted']) {
                    $this->shopify->collectionForDeletion($deletion);
                }
            }
            foreach ($state['delete_cards'] ?? [] as $deletion) {
                if (! $deletion['deleted']) {
                    $this->shopify->assertCardDeletable($deletion['card'], $draft->collection_gid);
                }
            }
            foreach ($state['collections'] as $gid => $desiredCollection) {
                if (str_starts_with($gid, 'new:')) {
                    continue;
                }
                if ($this->collectionSortHeldBack($draft, (string) $gid)) {
                    $heldSorts[$gid] = $desiredCollection;
                    continue;
                }
                if (! $this->collectionDifferent($baseline['collections'][$gid], $desiredCollection)) {
                    continue;
                }
                // All changed targets must pass preflight before any new writes.
                // An unavailable target is unfinished work, never a successful no-op.
                $current = $this->shopify->collection($gid);
                foreach ($state['cards'] as $card) {
                    if ($card['collection_gid'] === $gid) {
                        $this->assertCollectionLink($card, $current);
                    }
                }
                $missingFromShopify = $this->productsMissingFromShopify($baseline['collections'][$gid], $current, $desiredCollection);
                $baselineForCheck = $this->withoutProductIds($baseline['collections'][$gid], $missingFromShopify);
                $desiredForCheck = $this->withoutProductIds($desiredCollection, $missingFromShopify);
                if ($this->collectionDifferent($baselineForCheck, $current) && $this->collectionDifferent($current, $desiredForCheck)) {
                    throw new RuntimeException($current['title'].' changed in Shopify. Refresh and review before pushing.');
                }
                if ($missingFromShopify !== []) {
                    $baseline['collections'][$gid] = $baselineForCheck;
                    $desiredCollection = $desiredForCheck;
                    $state['collections'][$gid] = $desiredCollection;
                    $draft->update(['desired' => $state, 'snapshot' => $baseline]);
                }
                if (! $current['membership_supported'] && $this->membership($current) !== $this->membership($desiredCollection)) {
                    throw new RuntimeException('Shopify rules control membership in '.$current['title'].'.');
                }
                if (! $this->manualSortingSupported($current) && ($desiredCollection['enable_manual']
                    || ($current['sort'] === 'MANUAL' && array_column($current['products'], 'id') !== array_column($desiredCollection['products'], 'id')))) {
                    throw new RuntimeException('Manual sorting is not supported for '.$current['title'].'.');
                }
                $currentCollections[$gid] = $current;
            }
            $publication = ! empty($state['new_collections']) ? $this->shopify->onlineStorePublication() : null;
            foreach ($state['new_collections'] ?? [] as $token => $creation) {
                if (! str_starts_with($creation['gid'], 'new:')) {
                    continue;
                }
                if (! empty($creation['image_path']) && empty($creation['image'])) {
                    $creation['image'] = $this->shopify->uploadImage($creation['image_path'], $token, $creation['title']);
                    $state['new_collections'][$token] = $creation;
                    $draft->update(['desired' => $state]);
                }
                $image = ! empty($creation['image']) ? $this->shopify->image($creation['image'], true) : null;
                $created = $this->shopify->createCollection($creation, $image['image']['url'] ?? null);
                $oldGid = $creation['gid'];
                $gid = $created['id'];
                $creation['gid'] = $gid;
                $state['new_collections'][$token] = $creation;
                $confirmedCollection = $this->shopify->collection($gid);
                $state['collections'][$gid] = array_replace($state['collections'][$oldGid], $confirmedCollection, [
                    'gid' => $gid,
                    'handle' => $created['handle'],
                ]);
                unset($state['collections'][$oldGid]);
                foreach ($state['cards'] as &$item) {
                    if ($item['collection_gid'] === $oldGid) {
                        $item['collection_gid'] = $gid;
                        $item['link'] = rtrim(config('services.shopify.storefront_url'), '/').'/collections/'.$created['handle'];
                        $item['image'] = $creation['image'] ?? '';
                        $item['image_url'] = $image['image']['url'] ?? null;
                    }
                }
                unset($item);
                // Creation is checkpointed before publication or product changes can fail.
                $baseline['collections'][$gid] = $state['collections'][$gid];
                $currentCollections[$gid] = $baseline['collections'][$gid];
                $progress[] = 'Collection created: '.$created['title'];
                $draft->update(['desired' => $state, 'snapshot' => $baseline, 'progress' => $progress]);
            }
            foreach ($state['new_collections'] ?? [] as $creation) {
                $this->rememberCreatedCollection($draft, $creation);
            }
            foreach ($state['cards'] as $index => $card) {
                if (! empty($card['image_path']) && empty($card['image'])) {
                    $card['image'] = $this->shopify->uploadImage($card['image_path'], $card['key'], $card['name']);
                    $image = $this->shopify->image($card['image'], true);
                    $card['image_url'] = $image['image']['url'] ?? $card['image_url'] ?? null;
                    unset($card['image_path']);
                    $state['cards'][$index] = $card;
                    $draft->update(['desired' => $state]);
                }
                $current = collect($remote['cards'])->firstWhere('id', $card['id'])
                    ?? collect($baseline['cards'])->firstWhere('id', $card['id']);
                if (! $card['id'] || $this->fields($current) !== $this->fields($card)) {
                    $card['id'] = $this->shopify->saveCard($card);
                    $state['cards'][$index] = $card;
                    $baseline['cards'] = array_values(array_filter($baseline['cards'], fn ($item) => $item['id'] !== $card['id']));
                    $baseline['cards'][] = $card;
                    $progress[] = 'Card saved: '.$card['name'];
                    $draft->update(['desired' => $state, 'snapshot' => $baseline, 'progress' => $progress]);
                }
            }
            $ids = array_column($state['cards'], 'id');
            if ($ids !== $remote['reference_ids']) {
                $this->shopify->setReferences($draft->collection_gid, $ids, $remote['digest']);
                $progress[] = 'Preview card layout updated.';
            }
            $baseline['reference_ids'] = $ids;
            $baseline['cards'] = $state['cards'];
            $draft->update(['snapshot' => $baseline, 'progress' => $progress]);

            $this->holdOperationLock($draft);
            foreach ($currentCollections as $gid => $current) {
                $desired = $state['collections'][$gid];
                if ($desired['enable_manual'] && $current['sort'] !== 'MANUAL') {
                    $this->shopify->enableManual($gid);
                    $current['sort'] = 'MANUAL';
                    $baseline['collections'][$gid] = $current;
                    $draft->update(['snapshot' => $baseline]);
                    $progress[] = 'Manual sorting enabled: '.$current['title'];
                }
                $wantedIds = array_values(array_filter(
                    array_column($desired['products'], 'id'),
                    fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
                ));
                $remove = array_diff(array_column($current['products'], 'id'), $wantedIds);
                foreach (array_chunk($remove, 250) as $chunk) {
                    $this->storeRemoteJob($draft, $gid, 'unconfirmed:'.Str::uuid());
                    $job = $this->shopify->removeProducts($gid, $chunk);
                    $this->trackJob($draft, $gid, $job);
                    $current = $this->shopify->collection($gid);
                    $baseline['collections'][$gid] = $current;
                    $this->checkpointCollection($draft, $baseline, $gid);
                }
                $add = array_diff($wantedIds, array_column($current['products'], 'id'));
                foreach (array_chunk($add, 250) as $chunk) {
                    $this->shopify->addProducts($gid, $chunk);
                    $current = $this->shopify->collection($gid);
                    $baseline['collections'][$gid] = $current;
                    $draft->update(['snapshot' => $baseline]);
                }
                if ($this->membership($current) !== $this->membership($desired)) {
                    throw new RuntimeException('Product membership was not confirmed for '.$current['title'].'.');
                }
                $progress[] = 'Product membership confirmed: '.$current['title'];
                $draft->update(['progress' => $progress]);
                if ($current['sort'] === 'MANUAL') {
                    $moves = $this->moves(array_column($current['products'], 'id'), $wantedIds);
                    foreach (array_chunk($moves, 250) as $chunk) {
                        $this->storeRemoteJob($draft, $gid, 'unconfirmed:'.Str::uuid());
                        $job = $this->shopify->reorderProducts($gid, $chunk);
                        $this->trackJob($draft, $gid, $job);
                        $current = $this->shopify->collection($gid);
                        $baseline['collections'][$gid] = $current;
                        $this->checkpointCollection($draft, $baseline, $gid);
                    }
                }
                $confirmableDesired = array_replace($desired, [
                    'products' => array_values(array_filter(
                        $desired['products'],
                        fn ($product): bool => is_string($product['id'] ?? null) && preg_match('~^gid://shopify/Product/\d+$~', $product['id'])
                    )),
                ]);
                if ($this->collectionDifferent($current, $confirmableDesired)) {
                    throw new RuntimeException('Product sorting was not confirmed for '.$current['title'].'.');
                }
                $state['collections'][$gid] = array_replace($desired, [
                    'sort' => $current['sort'],
                    'enable_manual' => false,
                    'product_count' => $current['product_count'] ?? count($current['products']),
                ]);
                $baseline['collections'][$gid] = $state['collections'][$gid];
                $progress[] = 'Products synced: '.$current['title'];
                $draft->update(['snapshot' => $baseline, 'desired' => $state, 'progress' => $progress]);
            }
            foreach ($state['new_collections'] ?? [] as $token => $creation) {
                $this->shopify->publishCollection($creation['gid'], $publication);
                $state['new_collections'][$token]['published'] = true;
                $progress[] = 'Collection published to Online Store: '.$creation['title'];
                $draft->update(['desired' => $state, 'progress' => $progress]);
            }
            // A final read confirms the complete layout, including exact reference order and fields.
            $this->holdOperationLock($draft);
            foreach ($state['delete_cards'] ?? [] as $key => $deletion) {
                if ($deletion['deleted']) {
                    continue;
                }
                $this->shopify->deleteCard($deletion['card']);
                $state['delete_cards'][$key]['deleted'] = true;
                $progress[] = 'Vibe card deleted from Shopify: '.$deletion['card']['name'];
                $draft->update(['desired' => $state, 'progress' => $progress]);
            }
            foreach ($state['delete_collections'] ?? [] as $key => $deletion) {
                if ($deletion['deleted']) {
                    continue;
                }
                $deletedGid = $this->shopify->deleteCollectionOnly($deletion);
                // Delete catalogue entries only. Never invoke product deletion or touch product records.
                $gid = $deletedGid ?? $deletion['gid'];
                ShopifyCollection::where('shopify_id', $gid)->delete();
                ShopYourVibeDraft::where('collection_gid', $gid)->where('id', '!=', $draft->id)->delete();
                $state['delete_collections'][$key]['deleted'] = true;
                $progress[] = 'Collection deleted from Shopify; all products kept: '.$deletion['title'];
                $draft->update(['desired' => $state, 'progress' => $progress]);
                Cache::forget('shop-your-vibe-parents:'.config('services.shopify.shop'));
            }
            $confirmation = $state;
            foreach ($heldSorts as $gid => $collection) {
                $confirmation['collections'][$gid] = $draft->snapshot['collections'][$gid];
            }
            $confirmed = $this->confirmedState($draft, $confirmation, $ids, $allowedUnavailable);
            $this->assertOperationOwnership();
            $snapshot = $confirmed;
            foreach ($heldSorts as $gid => $collection) {
                $confirmed['collections'][$gid] = $collection;
                if (isset($draft->snapshot['collections'][$gid])) {
                    $snapshot['collections'][$gid] = $draft->snapshot['collections'][$gid];
                }
            }
            $this->markSortingPublished($draft, array_keys($heldSorts));
            $pending = $heldSorts !== [];
            $draft->update(['snapshot' => $snapshot, 'desired' => $confirmed, 'pending' => $pending, 'status' => $pending ? 'pending' : 'synced',
                'last_error' => null, 'remote_jobs' => [], 'progress' => $progress, 'refreshed_at' => now(), 'revision' => $draft->revision + 1]);
        } catch (ShopYourVibeBusyException $e) {
            throw $e;
        } catch (ShopYourVibeCollectionUnavailableException $e) {
            report($e);
            $draft->update([
                'pending' => true,
                'status' => 'failed',
                'last_error' => 'Push incomplete. '.$e->getMessage().' Requested local changes and historical data were kept. Previously confirmed changes were retained; retry to finish unconfirmed work.',
                'revision' => $draft->revision + 1,
            ]);
        } catch (Throwable $e) {
            report($e);
            $draft->update(['pending' => true, 'status' => 'failed', 'last_error' => $e->getMessage(), 'revision' => $draft->revision + 1]);
        } finally {
            $this->shopify->beforeMutation = null;
            $this->releaseOperationLock($draft);
        }
    }

    private function confirmedState(ShopYourVibeDraft $draft, array $state, array $ids, array $allowedUnavailable = []): array
    {
        $confirmed = $this->shopify->parent($draft->collection_gid);
        if ($confirmed['reference_ids'] !== $ids || array_map($this->shopifyComparable(...), $confirmed['cards']) !== array_map($this->shopifyComparable(...), $state['cards'])) {
            throw new RuntimeException('Shopify has not confirmed the final preview layout. Some changes remain pending.');
        }
        $collectionGids = array_unique(array_filter(array_merge(
            array_keys($state['collections']),
            array_column($state['cards'], 'collection_gid'),
        )));
        foreach ($collectionGids as $gid) {
            if (! preg_match('~^gid://shopify/Collection/\d+$~', $gid)) {
                continue;
            }
            if (in_array($gid, $allowedUnavailable, true)) {
                // Only unchanged, already-known unavailable targets may be kept
                // as history. A new missing response still follows F1 failure.
                continue;
            }
            try {
                $current = $this->shopify->collection($gid);
            } catch (ShopYourVibeCollectionUnavailableException $e) {
                if (array_key_exists($gid, $state['collections'])) {
                    throw $e;
                }
                // An unloaded link has no staged product operation or product
                // snapshot to confirm. Do not invent a confirmed empty snapshot.
                continue;
            }
            if (isset($state['collections'][$gid]) && $this->collectionDifferent($current, $state['collections'][$gid])) {
                throw new RuntimeException('Shopify has not confirmed the requested products or sorting for '.$current['title'].'. Some changes remain pending.');
            }
            // Keep the parent's local prelaunch placements; only real Shopify IDs
            // participate in collectionDifferent(), as in push preparation.
            if ($gid !== $draft->collection_gid) {
                $state['collections'][$gid] = $current;
            }
        }
        $confirmed['collections'] = $state['collections'];
        if (! empty($state['missing_collection_ids'])) {
            $confirmed['missing_collection_ids'] = $state['missing_collection_ids'];
        }
        if (! empty($state['unavailable_collections'])) {
            $confirmed['unavailable_collections'] = $state['unavailable_collections'];
        }

        return $confirmed;
    }

    private function trackJob(ShopYourVibeDraft $draft, string $gid, string $job): void
    {
        $this->storeRemoteJob($draft, $gid, $job);
        $this->waitForJob($job);
    }

    private function storeRemoteJob(ShopYourVibeDraft $draft, string $gid, string $job): void
    {
        $this->assertOperationOwnership();
        DB::transaction(function () use ($draft, $gid, $job): void {
            $locked = ShopYourVibeDraft::lockForUpdate()->findOrFail($draft->id);
            $jobs = $locked->remote_jobs ?? [];
            $jobs[$gid] = $job;
            $locked->update(['remote_jobs' => $jobs]);
        });
        $draft->refresh();
    }

    private function rememberCreatedCollection(ShopYourVibeDraft $draft, array $creation): void
    {
        $collection = $draft->desired['collections'][$creation['gid']];
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::firstOrCreate(['shopify_id' => $creation['gid']], [
            'import_id' => $creation['import_id'],
            'handle' => $collection['handle'], 'title' => $collection['title'] ?? $creation['title'],
            'sync_status' => ShopifyCollection::SYNC_STATUS_SYNCED, 'last_synced_at' => now(),
        ]));
    }

    protected function waitForJob(string $job): void
    {
        if (str_starts_with($job, 'unconfirmed:')) {
            throw new RuntimeException('The Shopify reorder response was lost. Its remote outcome must be reconciled before another reorder; approved sorting was kept.');
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if ($this->shopify->jobDone($job)) {
                return;
            }
            usleep(500000);
        }
        throw new RuntimeException('Shopify is still processing product changes. Changes remain pending; retry to confirm completion.');
    }

    private function checkpointCollection(ShopYourVibeDraft $draft, array $baseline, string $gid): void
    {
        $this->assertOperationOwnership();
        DB::transaction(function () use ($draft, $baseline, $gid): void {
            $locked = ShopYourVibeDraft::lockForUpdate()->findOrFail($draft->id);
            $jobs = $locked->remote_jobs ?? [];
            unset($jobs[$gid]);
            $snapshot = $locked->snapshot;
            $snapshot['collections'][$gid] = $baseline['collections'][$gid];
            $locked->update(['snapshot' => $snapshot, 'remote_jobs' => $jobs]);
        });
        $draft->refresh();
    }

    public function summary(ShopYourVibeDraft $draft): array
    {
        $before = array_column($draft->snapshot['cards'], 'key');
        $after = array_column($draft->desired['cards'], 'key');
        $summary = ['Vibe cards added' => count(array_diff($after, $before)), 'Vibe cards removed' => count(array_diff($before, $after)),
            'Vibe layout changed' => $before !== $after ? 'Yes' : 'No', 'Card fields changed' => 0,
            'Products added' => 0, 'Products removed' => 0, 'Product sorting changed' => 'No',
            'New collections to publish' => count($draft->desired['new_collections'] ?? []),
            'Vibe cards to permanently delete' => count($draft->desired['delete_cards'] ?? []),
            'Collections to delete (products kept)' => count($draft->desired['delete_collections'] ?? [])];
        foreach ($draft->desired['cards'] as $card) {
            $original = collect($draft->snapshot['cards'])->firstWhere('key', $card['key']);
            if ($original && $this->fields($original) !== $this->fields($card)) {
                $summary['Card fields changed']++;
            }
        }
        foreach ($draft->desired['collections'] as $gid => $collection) {
            $original = $draft->snapshot['collections'][$gid] ?? array_replace($collection, ['products' => []]);
            $summary['Products added'] += count(array_diff($this->membership($collection), $this->membership($original)));
            $summary['Products removed'] += count(array_diff($this->membership($original), $this->membership($collection)));
            if ($this->collectionDifferent($original, $collection)) {
                $summary['Product sorting changed'] = 'Yes';
            }
        }

        return $summary;
    }

    /** @return array<string, int|string> */
    public function otherChangesSummary(ShopYourVibeDraft $draft): array
    {
        $summary = $this->summary($draft);
        $summary['Product sorting changed'] = 'Not included';
        $summary['Sorting publication'] = 'Each collection publishes its own sorting';

        return $summary;
    }

    public function different(array $before, array $after): bool
    {
        if (! empty($after['new_collections']) || ! empty($after['delete_cards']) || ! empty($after['delete_collections'])) {
            return true;
        }
        if (array_column($after['cards'], 'id') !== $before['reference_ids'] || array_map($this->fields(...), $before['cards']) !== array_map($this->fields(...), $after['cards'])) {
            return true;
        }
        foreach ($after['collections'] as $gid => $collection) {
            if ($this->collectionDifferent($before['collections'][$gid], $collection)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function productIds(array $collection): array
    {
        return array_values(array_filter(
            array_column($collection['products'] ?? [], 'id'),
            fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
        ));
    }

    /**
     * Real products the draft still lists, and did not add in this edit, that Shopify no longer returns.
     *
     * @return array<int, string>
     */
    private function productsMissingFromShopify(array $baseline, array $current, array $desired): array
    {
        return array_values(array_intersect(
            array_diff($this->productIds($baseline), $this->productIds($current)),
            $this->productIds($desired)
        ));
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function withoutProductIds(array $collection, array $ids): array
    {
        if ($ids === []) {
            return $collection;
        }
        $collection['products'] = array_values(array_filter(
            $collection['products'] ?? [],
            fn ($product): bool => ! in_array($product['id'] ?? null, $ids, true)
        ));

        return $collection;
    }

    private function collectionDifferent(array $before, array $after): bool
    {
        $sort = $after['enable_manual'] ? 'MANUAL' : $after['sort'];
        $beforeProducts = array_values(array_filter(
            $before['products'] ?? [],
            fn ($product): bool => is_string($product['id'] ?? null) && preg_match('~^gid://shopify/Product/\d+$~', $product['id'])
        ));
        $afterProducts = array_values(array_filter(
            $after['products'] ?? [],
            fn ($product): bool => is_string($product['id'] ?? null) && preg_match('~^gid://shopify/Product/\d+$~', $product['id'])
        ));

        return $before['sort'] !== $sort || ($sort === 'MANUAL'
            ? array_column($beforeProducts, 'id') !== array_column($afterProducts, 'id')
            : $this->membership($before) !== $this->membership($after));
    }

    private function manualSortingSupported(array $collection): bool
    {
        return ($collection['manual_supported'] ?? true) !== false
            || ($collection['sort'] ?? null) !== 'UNSUPPORTED';
    }

    private function membership(array $collection): array
    {
        $ids = array_values(array_filter(
            array_column($collection['products'], 'id'),
            fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
        ));
        sort($ids);

        return $ids;
    }

    private function hydrateDraftPlacements(string $parentGid, array $state, array $previous = []): array
    {
        $represented = array_values(array_unique(array_filter(array_merge(
            [$parentGid],
            array_column($state['cards'] ?? [], 'collection_gid'),
        ))));
        if ($represented === []) {
            return $state;
        }

        $drafts = NewProductDraft::query()->get();
        if ($drafts->isEmpty()) {
            return $state;
        }

        $collections = $this->placementCollectionRules($parentGid, $state);
        foreach ($represented as $gid) {
            if (! isset($state['collections'][$gid], $collections[$gid]) || ! empty($state['collections'][$gid]['unavailable'])) {
                continue;
            }
            $previousList = collect(data_get($previous, "collections.{$gid}.products", []))->values();
            $previousProducts = $previousList->keyBy('id');
            $products = collect($state['collections'][$gid]['products'] ?? [])->values();
            foreach ($drafts as $draft) {
                $draftId = $this->draftProductId($draft);
                $id = $draftId;
                $liveGid = trim((string) ($draft->shopify_id ?? ''));
                if ($liveGid !== '' && preg_match('~^gid://shopify/Product/\d+$~', $liveGid)) {
                    // A live product's identity, values, membership and relative
                    // order come exclusively from this Shopify retrieval.
                    continue;
                }
                if (! $this->draftBelongsToCollection($draft, $collections[$gid])) {
                    unset($state['draft_placements'][$draftId][$gid], $state['draft_placements'][$id][$gid]);

                    continue;
                }
                $previousCard = $previousProducts->get($draftId) ?? $previousProducts->get($id);
                if ($previousCard !== null) {
                    $previousCard['id'] = $id;
                }
                if ($previousCard === null && $products->contains(fn (array $product): bool => ($product['id'] ?? null) === $id)) {
                    continue;
                }
                $card = $previousCard ?? $this->draftProductCard($draft, $id);
                $products = $products
                    ->reject(fn (array $product): bool => in_array($product['id'] ?? null, [$draftId, $id], true))
                    ->values();
                $previousIndex = $previousList->search(fn (array $product): bool => in_array($product['id'] ?? null, [$draftId, $id], true));
                if ($previousIndex === false) {
                    $products->push($card);
                } else {
                    $products->splice(min((int) $previousIndex, $products->count()), 0, [$card]);
                }
            }
            $state['collections'][$gid]['products'] = $products->values()->all();
        }

        return $state;
    }

    /** Carry a prepared prelaunch position into desired only, using the fresh live card. */
    private function stagePublishedPlacements(array $state, array $previous): array
    {
        foreach (NewProductDraft::query()->whereNotNull('shopify_id')->get() as $productDraft) {
            $gid = trim((string) $productDraft->shopify_id);
            if (! preg_match('~^gid://shopify/Product/\d+$~', $gid)) {
                continue;
            }
            $draftId = $this->draftProductId($productDraft);
            foreach ($state['collections'] as $collectionGid => &$collection) {
                if (! empty($collection['unavailable'])) {
                    continue;
                }
                $old = $previous['collections'][$collectionGid]['products'] ?? [];
                $position = array_search($draftId, array_column($old, 'id'), true);
                $livePosition = array_search($gid, array_column($collection['products'] ?? [], 'id'), true);
                if ($position === false || $livePosition === false) {
                    continue;
                }
                $card = $collection['products'][$livePosition];
                array_splice($collection['products'], $livePosition, 1);
                array_splice($collection['products'], min($position, count($collection['products'])), 0, [$card]);
            }
            unset($collection);
        }

        return $state;
    }

    private function placementCollectionRules(string $parentGid, array $state): array
    {
        $rules = [];
        foreach ($state['collections'] ?? [] as $gid => $collection) {
            $rules[$gid] = [
                'gid' => $gid,
                'title' => $collection['title'] ?? '',
                'handle' => $collection['handle'] ?? '',
                'membership_tag' => $collection['detected_membership_tag'] ?? null,
            ];
        }
        foreach (ShopYourVibeCollectionMapping::query()->where('parent_collection_id', $parentGid)->where('is_active', true)->get() as $mapping) {
            $gid = $mapping->shopify_collection_id;
            $rules[$gid]['membership_tag'] = $mapping->membership_tag ?: ($rules[$gid]['membership_tag'] ?? null);
            $rules[$gid]['title'] = $mapping->collection_name ?: ($rules[$gid]['title'] ?? '');
            $rules[$gid]['handle'] = $mapping->collection_handle ?: ($rules[$gid]['handle'] ?? '');
        }

        return $rules;
    }

    private function draftBelongsToCollection(NewProductDraft $draft, array $collection): bool
    {
        $tags = collect(TagNormalizer::parseTokens((string) $draft->tags))
            ->map(fn (string $tag): string => mb_strtolower($tag));
        $membershipTag = TagNormalizer::normalizeToken((string) ($collection['membership_tag'] ?? ''));
        if ($membershipTag !== null && $tags->contains(mb_strtolower($membershipTag))) {
            return true;
        }
        foreach ([$collection['handle'] ?? '', $collection['title'] ?? ''] as $value) {
            $token = TagNormalizer::normalizeToken((string) $value);
            if ($token !== null && $tags->contains(mb_strtolower($token))) {
                return true;
            }
        }

        return false;
    }

    private function draftProductId(NewProductDraft $draft): string
    {
        return 'draft:'.$draft->id;
    }

    private function draftProductCard(NewProductDraft $draft, string $id): array
    {
        return [
            'id' => $id,
            'title' => (string) ($draft->title ?: $draft->handle ?: 'Draft product #'.$draft->id),
            'status' => $draft->isApprovedByTwo() ? 'APPROVED - NOT LIVE' : ((string) ($draft->status ?: 'DRAFT')),
            'tags' => TagNormalizer::parseTokens((string) $draft->tags),
            'image' => $draft->imageUrl(),
            'sku' => $draft->sku,
            'is_prelaunch_draft' => true,
            'draft_id' => $draft->id,
        ];
    }

    private function pushScopedSort(int $id, array $authorization): void
    {
        $gid = (string) $authorization['scope_gid'];
        $scope = (string) ($authorization['scope'] ?? ShopYourVibeEditing::sortScope($gid));
        $draft = ShopYourVibeDraft::findOrFail($id);
        $execution = Cache::lock(ShopYourVibeEditing::scopePushLockKey($id, $scope), 900);
        if (! $execution->get()) {
            throw new ShopYourVibeBusyException('This collection push is already running.');
        }
        try {
            app(ShopYourVibeEditing::class)->validateJob($id, $authorization);
            $this->shopify->beforeMutation = fn () => app(ShopYourVibeEditing::class)->validateJob($id, $authorization, false);
            $draft->refresh();
            if (ShopYourVibeDraft::where('id', '!=', $id)->whereNotNull('remote_jobs->'.$gid)->exists()) {
                throw new RuntimeException('Another draft has an unresolved Shopify reorder for this collection. Confirm it before publishing.');
            }
            $desired = $draft->desired['collections'][$gid] ?? null;
            if (! is_array($desired)) {
                throw new RuntimeException('Open this collection’s products before pushing their order.');
            }
            if (isset($draft->remote_jobs[$gid])) {
                $this->waitForJob($draft->remote_jobs[$gid]);
                $baseline = $draft->snapshot;
                $baseline['collections'][$gid] = $this->shopify->collection($gid);
                $this->checkpointCollection($draft, $baseline, $gid);
            }
            $current = $this->shopify->collection($gid);
            if ($this->membership($current) !== $this->membership($desired)) {
                $sorting = app(ShopYourVibeSorting::class);
                $sorting->invalidateUnpublishedApproval($draft, $gid);
                $diff = $sorting->describeMembershipChange($draft, $gid, $desired, $current);
                $draft->refresh();
                $editingState = $draft->editing_state ?? [];
                $editingState['membership_prompt'] = $diff;
                $draft->editing_state = $editingState;
                $draft->save();
                $lines = ['Shopify membership for '.$current['title'].' changed after approval. Review this collection again before publishing its order.'];
                foreach ($diff['added'] as $product) {
                    $lines[] = 'Added in Shopify: '.$product['title'].' ('.$product['id'].')';
                }
                foreach ($diff['removed'] as $product) {
                    $lines[] = 'Removed from Shopify: '.$product['title'].' ('.$product['id'].')';
                }
                throw new RuntimeException(implode("\n", $lines));
            }
            if ($desired['enable_manual'] && $current['sort'] !== 'MANUAL') {
                $this->shopify->enableManual($gid);
                $current = $this->shopify->collection($gid);
            }
            $wantedIds = array_values(array_filter(
                array_column($desired['products'], 'id'),
                fn ($productId): bool => is_string($productId) && preg_match('~^gid://shopify/Product/\d+$~', $productId)
            ));
            if ($current['sort'] === 'MANUAL') {
                $moves = $this->moves(array_column($current['products'], 'id'), $wantedIds);
                foreach (array_chunk($moves, 250) as $chunk) {
                    if ($chunk !== []) {
                        app(ShopYourVibeEditing::class)->validateJob($id, $authorization, false);
                        // Persist uncertainty before the HTTP call: a lost response or killed
                        // worker must never silently authorize a second, competing reorder.
                        $this->storeRemoteJob($draft, $gid, 'unconfirmed:'.Str::uuid());
                        $job = $this->shopify->reorderProducts($gid, $chunk);
                        $this->trackJob($draft, $gid, $job);
                        $baseline = $draft->snapshot;
                        $baseline['collections'][$gid] = $this->shopify->collection($gid);
                        $this->checkpointCollection($draft, $baseline, $gid);
                    }
                }
                $current = $this->shopify->collection($gid);
            }
            $confirmable = array_replace($desired, [
                'products' => array_values(array_filter(
                    $desired['products'],
                    fn ($product): bool => is_string($product['id'] ?? null) && preg_match('~^gid://shopify/Product/\d+$~', $product['id'])
                )),
            ]);
            if ($this->collectionDifferent($current, $confirmable)) {
                throw new RuntimeException('Product sorting was not confirmed for '.$current['title'].'.');
            }
            DB::transaction(function () use ($id, $authorization, $gid, $scope, $desired, $current): void {
                app(ShopYourVibeEditing::class)->validateJob($id, $authorization, false);
                $draft = ShopYourVibeDraft::lockForUpdate()->findOrFail($id);
                $state = $draft->desired;
                $baseline = $draft->snapshot;
                $confirmed = array_replace($desired, [
                    'sort' => $current['sort'],
                    'enable_manual' => false,
                    'product_count' => $current['product_count'] ?? count($current['products']),
                    'products' => $current['products'],
                ]);
                $state['collections'][$gid] = $confirmed;
                $baseline['collections'][$gid] = $confirmed;
                $pending = $this->different($baseline, $state);
                app(ShopYourVibeSorting::class)->markPublished($draft, (int) ($authorization['reviewed_revision'] ?? 0), $gid);
                app(ShopYourVibeEditing::class)->finishScopePush($draft, $scope, false);
                $fresh = $draft->fresh() ?? $draft;
                $editing = $fresh->editing_state;
                $fresh->update([
                    'desired' => $state,
                    'snapshot' => $baseline,
                    'pending' => $pending,
                    'status' => $pending ? 'pending' : 'synced',
                    'last_error' => null,
                    'editing_state' => $editing,
                ]);
            });
        } catch (Throwable $e) {
            report($e);
            app(ShopYourVibeEditing::class)->failScopePush($id, $authorization, $e->getMessage(), true);
        } finally {
            $this->shopify->beforeMutation = null;
            $execution->release();
        }
    }

    private function collectionSortHeldBack(ShopYourVibeDraft $draft, string $gid): bool
    {
        return SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('collection_gid', $gid)
            ->where('explicit', true)
            ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
            ->exists();
    }

    /** @param array<int, string> $exceptGids */
    private function markSortingPublished(ShopYourVibeDraft $draft, array $exceptGids = []): void
    {
        $reviewed = $draft->editing_state['push']['reviewed_revision'] ?? null;
        if (! is_int($reviewed) && ! (is_string($reviewed) && ctype_digit((string) $reviewed))) {
            return;
        }
        app(ShopYourVibeSorting::class)->markPublished($draft, (int) $reviewed, null, $exceptGids);
    }

    private function markPlacedDrafts(array &$state, string $gid, array $products, array $explicitIds = []): void
    {
        $explicit = array_fill_keys($explicitIds, true);
        foreach ($products as $position => $product) {
            $id = (string) ($product['id'] ?? '');
            if (! str_starts_with($id, 'draft:') || ! isset($explicit[$id])) {
                continue;
            }
            $state['draft_placements'][$id][$gid] = [
                'status' => 'complete',
                'position' => $position,
                'positioned_at' => now()->toISOString(),
                'synced' => false,
            ];
        }
    }

    private function fields(?array $card): array
    {
        return array_map(fn ($key) => (string) ($card[$key] ?? ''), ['name', 'image', 'image_path', 'link']);
    }

    public function placeProduct(int $id, int $revision, string $gid, string $productGid, int $position, ?int $scopeRevision = null): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision);
        $products = $draft->desired['collections'][$gid]['products'] ?? null;
        if (! is_array($products)) {
            throw new RuntimeException('Open this collection’s products before changing their order.');
        }
        $ids = array_column(ShopYourVibeVisibleProducts::visible($products), 'id');
        $count = count($ids);
        if ($position < 1 || $position > $count) {
            throw new RuntimeException('Enter a position from 1 to '.$count.'.');
        }
        $from = array_search($productGid, $ids, true);
        if ($from === false) {
            throw new RuntimeException('That product is not in this collection.');
        }
        array_splice($ids, $from, 1);
        array_splice($ids, $position - 1, 0, [$productGid]);

        return $this->edit($id, $revision, 'reorder_products', [
            'collection_gid' => $gid,
            'ids' => $ids,
            'explicit_ids' => [$productGid],
        ] + ($scopeRevision === null ? [] : ['scope_revision' => $scopeRevision]));
    }

    public function moves(array $current, array $desired): array
    {
        $moves = [];
        foreach ($desired as $position => $id) {
            $old = array_search($id, $current, true);
            if ($old === false) {
                throw new RuntimeException('Cannot order a product that is not a member of the collection.');
            }
            if ($old !== $position) {
                $moves[] = ['id' => $id, 'newPosition' => (string) $position];
                array_splice($current, $old, 1);
                array_splice($current, $position, 0, [$id]);
            }
        }

        return $moves;
    }

    private function editable(int $id, int $revision, bool $lock = false, bool $strict = false): ShopYourVibeDraft
    {
        $draft = ShopYourVibeDraft::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        if (in_array($draft->status, ['pushing', 'creating', 'deleting'], true)) {
            throw new RuntimeException($this->activeOperationMessage($draft));
        }
        if ($draft->revision !== $revision && ($strict || ! $this->staleRevisionIsHarmless($draft))) {
            throw new RuntimeException('This draft changed in another tab or session. Reopen it before editing.');
        }

        return $draft;
    }

    private function activeOperationMessage(ShopYourVibeDraft $draft): string
    {
        return match ($draft->status) {
            'creating' => 'A Vibe is being created in Shopify. Wait for it to finish before editing.',
            'deleting' => 'A Vibe is being deleted in Shopify. Wait for it to finish before editing.',
            default => 'Changes are being pushed to Shopify. Wait for completion before editing.',
        };
    }

    /**
     * A synced draft has no unsaved edit to protect. An old Livewire revision is
     * not a concurrent edit, so the next action may apply to the current database state.
     */
    private function staleRevisionIsHarmless(ShopYourVibeDraft $draft): bool
    {
        return $draft->status === 'synced'
            && ! $draft->pending
            && empty($draft->remote_jobs)
            && empty($draft->operation)
            && ! $this->different($draft->snapshot ?? [], $draft->desired ?? []);
    }

    private function assertHandleFreeInShopify(string $handle): void
    {
        if ($this->shopify->findCollectionByHandle($handle)) {
            throw new RuntimeException('The handle "'.$handle.'" is already used in Shopify. Choose the existing collection or a different vibe name.');
        }
    }

    private function assertImmediateAllowed(ShopYourVibeDraft $draft): void
    {
        if ($draft->pending || $this->different($draft->snapshot ?? [], $draft->desired ?? [])) {
            throw new RuntimeException('This collection has pending changes. Push or discard them before adding or removing a vibe.');
        }
    }

    private function prepareCreatePayload(ShopYourVibeDraft $draft, array $input): array
    {
        $mode = ($input['mode'] ?? 'new') === 'existing' ? 'existing' : 'new';
        $token = (string) Str::uuid();
        if ($mode === 'existing') {
            $collection = $this->localCollection((string) ($input['collection_gid'] ?? ''));
            $this->assertCollectionAvailable($draft, $collection->shopify_id);
            if ($collection->shopify_id === $draft->collection_gid) {
                throw new RuntimeException('Choose a collection other than the parent collection being edited.');
            }

            return [
                'mode' => 'existing',
                'token' => $token,
                'collection_gid' => $collection->shopify_id,
                'title' => $collection->title,
                'handle' => $collection->handle,
                'name' => $collection->title,
                'image' => (string) ($input['image'] ?? ''),
                'image_path' => $input['image_path'] ?? null,
                'image_url' => $input['image_url'] ?? null,
                'card_handle' => 'cms-vibe-'.$token,
                'import_id' => null,
            ];
        }

        $importId = ShopifyCollection::where('shopify_id', $draft->collection_gid)->latest('id')->value('import_id')
            ?? Import::orderByDesc('is_current')->latest('id')->value('id');
        if (! $importId) {
            throw new RuntimeException('Synchronize the collection catalogue before creating a collection.');
        }
        $prepared = $this->preparedCollectionInput($draft, $input);
        if (($prepared['image'] ?? '') === '') {
            $prepared['image'] = null;
        }
        $fields = Validator::make($prepared, [
            'title' => 'required|string|max:255',
            'handle' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'vibe_name' => 'required|string|max:255',
            'image' => ['nullable', 'regex:~^gid://shopify/MediaImage/\d+$~'],
            'image_path' => ['nullable', 'regex:~^shop-your-vibe/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$~'],
            'image_url' => 'nullable|url|max:4096',
        ])->validate();
        $this->assertHandleFreeInShopify($fields['handle']);

        return [
            'mode' => 'new',
            'token' => $token,
            'collection_gid' => null,
            'title' => $fields['title'],
            'handle' => $fields['handle'],
            'name' => $fields['vibe_name'],
            'image' => (string) ($fields['image'] ?? ''),
            'image_path' => $fields['image_path'] ?? null,
            'image_url' => $fields['image_url'] ?? null,
            'card_handle' => 'cms-vibe-'.$token,
            'import_id' => (int) $importId,
        ];
    }

    private function prepareDeletePayload(ShopYourVibeDraft $draft, array $input): array
    {
        $mode = (string) ($input['mode'] ?? 'layout');
        if (! in_array($mode, ['layout', 'permanent', 'collection'], true)) {
            throw new RuntimeException('Choose a removal option.');
        }
        $card = collect($draft->snapshot['cards'] ?? [])->firstWhere('key', $input['key'] ?? null)
            ?? collect($draft->desired['cards'] ?? [])->firstWhere('key', $input['key'] ?? null);
        if (! is_array($card)) {
            throw new RuntimeException('This vibe is no longer in the draft.');
        }
        $this->assertCollectionAvailable($draft, $card['unavailable_collection_gid'] ?? $card['collection_gid'] ?? null);
        $collection = $mode === 'collection' ? $this->assertCollectionDeletable($draft, $card) : null;

        return ['mode' => $mode, 'key' => $card['key'], 'card' => $card, 'collection' => $collection];
    }

    private function assertCollectionDeletable(ShopYourVibeDraft $draft, array $card): array
    {
        $gid = (string) ($card['collection_gid'] ?? '');
        if ($gid === '' || $gid === $draft->collection_gid || str_starts_with($gid, 'new:')) {
            throw new RuntimeException('Choose a vibe linked to a collection other than the parent collection being edited.');
        }
        $sharedHere = collect($draft->snapshot['cards'] ?? [])
            ->contains(fn ($item) => ($item['collection_gid'] ?? null) === $gid && ($item['key'] ?? null) !== ($card['key'] ?? null));
        if ($sharedHere) {
            throw new RuntimeException('Another vibe in this layout uses the collection selected for deletion. Remove that vibe first.');
        }
        if (ShopYourVibeDraft::query()->where('collection_gid', $gid)->where('id', '!=', $draft->id)->exists()) {
            throw new RuntimeException('This collection is a Shop Your Vibe parent in another workflow and cannot be deleted.');
        }
        ShopYourVibeDraft::query()->where('id', '!=', $draft->id)->get(['snapshot', 'desired'])->each(function (ShopYourVibeDraft $other) use ($gid): void {
            foreach ([$other->snapshot['cards'] ?? [], $other->desired['cards'] ?? []] as $cards) {
                if (collect($cards)->contains(fn ($item) => ($item['collection_gid'] ?? null) === $gid)) {
                    throw new RuntimeException('Another Shop Your Vibe uses this collection. Remove that vibe first.');
                }
            }
        });
        $linked = $this->localCollection($gid);

        return ['gid' => $gid, 'handle' => $linked->handle, 'title' => $linked->title, 'token' => null, 'deleted' => false];
    }

    private function runCreate(ShopYourVibeDraft $draft): void
    {
        $payload = $draft->operation['payload'];
        $this->assertCollectionAvailable($draft, $payload['collection_gid'] ?? null);
        $this->shopify->definition();
        $checkpoints = $draft->operation['checkpoints'];
        if (empty($checkpoints['attached'])) {
            $remote = $this->shopify->parent($draft->collection_gid);
            $this->assertCreateLayout($draft->snapshot, $remote);
        }
        if ($payload['mode'] === 'new' && empty($checkpoints['collection_gid'])) {
            if (! empty($payload['image_path']) && empty($checkpoints['image_gid'])) {
                $checkpoints['image_gid'] = $this->shopify->uploadImage($payload['image_path'], $payload['token'], $payload['title']);
                $this->saveOperation($draft, $checkpoints, 'Image uploaded.');
            }
            $imageUrl = $payload['image_url'] ?? null;
            if (! empty($checkpoints['image_gid'])) {
                $image = $this->shopify->image($checkpoints['image_gid'], true);
                $imageUrl = $image['image']['url'] ?? $imageUrl;
            }
            $created = $this->shopify->createCollection([
                'title' => $payload['title'],
                'handle' => $payload['handle'],
                'token' => $payload['token'],
                'membership_tag' => $payload['handle'],
            ], $imageUrl);
            $checkpoints['collection_gid'] = $created['id'];
            $checkpoints['handle'] = $created['handle'];
            $this->rememberCollectionRecord($payload['import_id'], $created['id'], $created['handle'], $payload['title']);
            $this->saveOperation($draft, $checkpoints, 'Collection created: '.$created['title']);
        }
        if (empty($checkpoints['card_id'])) {
            $handle = $checkpoints['handle'] ?? $payload['handle'];
            $checkpoints['card_id'] = $this->shopify->saveCard([
                'id' => null,
                'handle' => $checkpoints['card_handle'],
                'name' => $payload['name'],
                'image' => (string) ($checkpoints['image_gid'] ?? $payload['image'] ?? ''),
                'link' => rtrim((string) config('services.shopify.storefront_url'), '/').'/collections/'.$handle,
            ]);
            $this->saveOperation($draft, $checkpoints, 'Card created: '.$payload['name']);
        }
        if (empty($checkpoints['attached'])) {
            $remote = $this->shopify->parent($draft->collection_gid);
            $this->assertCreateLayout($draft->snapshot, $remote);
            // Append to the live Shopify list. The saved snapshot can be older than another user's vibe.
            $ids = $remote['reference_ids'];
            if (! in_array($checkpoints['card_id'], $ids, true)) {
                $ids[] = $checkpoints['card_id'];
                $this->shopify->setReferences($draft->collection_gid, $ids, $remote['digest']);
            }
            $checkpoints['attached'] = true;
            $this->saveOperation($draft, $checkpoints, 'Preview card added to the collection.');
        }
        if ($payload['mode'] === 'new' && empty($checkpoints['published'])) {
            $publication = $this->shopify->onlineStorePublication();
            $this->shopify->publishCollection($checkpoints['collection_gid'], $publication);
            $checkpoints['published'] = true;
            $this->saveOperation($draft, $checkpoints, 'Collection published to Online Store: '.$payload['title']);
        }
        $this->finishImmediate($draft);
    }

    private function runDelete(ShopYourVibeDraft $draft): void
    {
        $payload = $draft->operation['payload'];
        $this->assertCollectionAvailable($draft, $payload['card']['unavailable_collection_gid'] ?? $payload['card']['collection_gid'] ?? null);
        $this->shopify->definition();
        $checkpoints = $draft->operation['checkpoints'];
        $card = $payload['card'];
        if (empty($checkpoints['detached'])) {
            $remote = $this->shopify->parent($draft->collection_gid);
            $removedId = $card['id'] ?? null;
            $alreadyGone = $removedId && ! in_array($removedId, $remote['reference_ids'], true);
            $this->assertLayoutCompatible($draft->snapshot, $remote, $alreadyGone ? $removedId : null);
            if (in_array($payload['mode'], ['permanent', 'collection'], true)) {
                $this->shopify->assertCardDeletable($card, $draft->collection_gid);
            }
            if ($payload['mode'] === 'collection') {
                $this->assertCollectionDeletable($draft, $card);
                $this->shopify->collectionForDeletion($payload['collection']);
            }
            // Remove only the selected card from the live list, leaving vibes added since this snapshot.
            $ids = array_values(array_filter($remote['reference_ids'], fn ($id) => $id !== $removedId));
            if ($ids !== $remote['reference_ids']) {
                $this->shopify->setReferences($draft->collection_gid, $ids, $remote['digest']);
            }
            $checkpoints['detached'] = true;
            $this->saveOperation($draft, $checkpoints, 'Removed from the Shop Your Vibe preview.');
        }
        if (in_array($payload['mode'], ['permanent', 'collection'], true) && empty($checkpoints['card_deleted'])) {
            $this->shopify->deleteCard($card);
            $checkpoints['card_deleted'] = true;
            $this->saveOperation($draft, $checkpoints, 'Vibe card deleted from Shopify: '.($card['name'] ?? 'Vibe'));
        }
        if ($payload['mode'] === 'collection' && empty($checkpoints['collection_deleted'])) {
            $deletedGid = $this->shopify->deleteCollectionOnly($payload['collection']);
            $gid = $deletedGid ?? $payload['collection']['gid'];
            ShopifyCollection::where('shopify_id', $gid)->delete();
            ShopYourVibeDraft::where('collection_gid', $gid)->where('id', '!=', $draft->id)->delete();
            $checkpoints['collection_deleted'] = true;
            $this->saveOperation($draft, $checkpoints, 'Collection deleted from Shopify; all products kept: '.$payload['collection']['title']);
        }
        $this->finishImmediate($draft);
    }

    private function finishImmediate(ShopYourVibeDraft $draft): void
    {
        $operationId = $draft->operation['id'] ?? null;
        $state = $this->pullState($draft);
        DB::transaction(function () use ($draft, $state, $operationId): void {
            $locked = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if (($locked->operation['id'] ?? null) !== $operationId) {
                return;
            }
            $sortOrders = [];
            if ($locked->pending && $this->different($locked->snapshot ?? [], $locked->desired ?? [])) {
                if (! $this->pendingIsCollectionSortOnly($locked)) {
                    $locked->update([
                        'status' => 'failed',
                        'last_error' => 'Pending draft changes appeared during the Shopify operation. Refresh and review before continuing.',
                        'revision' => $locked->revision + 1,
                    ]);

                    return;
                }
                foreach ($locked->desired['collections'] ?? [] as $gid => $collection) {
                    $sortOrders[$gid] = $collection['products'] ?? [];
                }
            }
            $snapshot = $state;
            $desired = $state;
            foreach ($sortOrders as $gid => $products) {
                if (! isset($desired['collections'][$gid]) && isset($locked->desired['collections'][$gid])) {
                    $desired['collections'][$gid] = $locked->desired['collections'][$gid];
                    $snapshot['collections'][$gid] = $locked->snapshot['collections'][$gid] ?? $locked->desired['collections'][$gid];
                }
                if (isset($desired['collections'][$gid])) {
                    $desired['collections'][$gid]['products'] = $products;
                }
            }
            $pending = $sortOrders !== [] && $this->different($snapshot, $desired);
            $locked->update([
                'snapshot' => $snapshot,
                'desired' => $desired,
                'pending' => $pending,
                'status' => 'synced',
                'last_error' => null,
                'remote_jobs' => [],
                'operation' => null,
                'progress' => array_values(array_merge($locked->progress ?? [], ['Shopify confirmed the Vibe operation.'])),
                'refreshed_at' => now(),
                'revision' => $locked->revision + 1,
            ]);
            $this->deactivateMissingMappings($locked, $state);
        });
        Cache::forget('shop-your-vibe-parents:'.config('services.shopify.shop'));
    }

    private function saveOperation(ShopYourVibeDraft $draft, array $checkpoints, ?string $progress = null): void
    {
        $operation = $draft->operation ?? [];
        $operation['checkpoints'] = $checkpoints;
        $attributes = ['operation' => $operation];
        if ($progress) {
            $attributes['progress'] = array_values(array_merge($draft->progress ?? [], [$progress]));
        }
        $draft->update($attributes);
        $this->holdOperationLock($draft);
    }

    private function pullState(ShopYourVibeDraft $draft): array
    {
        $this->shopify->definition();
        $state = $this->shopify->parent($draft->collection_gid);
        $state['collections'][$draft->collection_gid] = $this->pullCollection($draft, $draft->collection_gid, false);
        $unavailable = [];
        $knownMissing = ShopYourVibeCollectionMapping::confirmedMissingIds();
        $missingIds = $draft->desired['missing_collection_ids'] ?? [];
        foreach (array_unique(array_filter(array_column($state['cards'], 'collection_gid'))) as $gid) {
            if ($gid === $draft->collection_gid) {
                continue;
            }
            if (in_array($gid, $knownMissing, true)) {
                $missingIds[] = $gid;
                $unavailable[] = ['gid' => $gid, 'message' => $this->unavailableCollectionMessage($draft, $gid, true)];
                $state['collections'][$gid] = $this->keptUnavailableCollection($draft, $gid);
                continue;
            }
            try {
                $state['collections'][$gid] = $this->shopify->collection($gid);
            } catch (ShopYourVibeCollectionUnavailableException) {
                $message = $this->unavailableCollectionMessage($draft, $gid, true);
                $unavailable[] = ['gid' => $gid, 'message' => $message];
                $missingIds[] = $gid;
                $state['collections'][$gid] = $this->keptUnavailableCollection($draft, $gid);
            }
        }
        $state['unavailable_collections'] = $unavailable;
        $state['missing_collection_ids'] = array_values(array_unique($missingIds));
        foreach ($state['missing_collection_ids'] as $gid) {
            $state['collections'][$gid] ??= $this->keptUnavailableCollection($draft, $gid);
        }
        $state = $this->hydrateDraftPlacements($draft->collection_gid, $state, $draft->desired ?? []);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function keptUnavailableCollection(ShopYourVibeDraft $draft, string $gid): array
    {
        $previous = $draft->desired['collections'][$gid] ?? $draft->snapshot['collections'][$gid] ?? null;
        if (is_array($previous) && array_key_exists('products', $previous)) {
            $previous['unavailable'] = true;

            return $previous;
        }
        $title = $draft->desired['collections'][$gid]['title']
            ?? collect($draft->desired['cards'] ?? [])->firstWhere('collection_gid', $gid)['name']
            ?? ShopifyCollection::query()->where('shopify_id', $gid)->latest('id')->value('title')
            ?? $gid;

        return [
            'gid' => $gid,
            'title' => $title,
            'handle' => '',
            'sort' => 'MANUAL',
            'manual_supported' => false,
            'membership_supported' => false,
            'enable_manual' => false,
            'product_count' => 0,
            'products' => [],
            'unavailable' => true,
        ];
    }

    private function pullCollection(ShopYourVibeDraft $draft, string $gid, bool $linked): array
    {
        try {
            return $this->shopify->collection($gid);
        } catch (ShopYourVibeCollectionUnavailableException) {
            throw new RuntimeException($this->unavailableCollectionMessage($draft, $gid, $linked));
        }
    }

    private function unavailableCollectionMessage(ShopYourVibeDraft $draft, string $gid, bool $linked): string
    {
        $title = $draft->desired['collections'][$gid]['title']
            ?? collect($draft->desired['cards'] ?? [])->firstWhere('collection_gid', $gid)['name']
            ?? ShopifyCollection::query()->where('shopify_id', $gid)->latest('id')->value('title');
        $label = trim((string) $title);
        $name = $label !== '' ? $label.' ('.$gid.')' : $gid;
        $role = $linked ? 'linked collection' : 'collection';

        return ShopYourVibeCollectionMapping::MISSING_MESSAGE.': '.$role.' '.$name.'. Existing products and draft history were kept. Editing and new assignments are disabled.';
    }

    public function assertCollectionAvailable(ShopYourVibeDraft $draft, ?string $gid): void
    {
        if ($this->collectionIsUnavailable($draft, $gid)) {
            throw new RuntimeException(ShopYourVibeCollectionMapping::MISSING_MESSAGE.' ('.$gid.'). Editing and Shopify writes are disabled; existing draft changes were kept.');
        }
    }

    private function deactivateMissingMappings(ShopYourVibeDraft $draft, array $state): void
    {
        foreach ($state['missing_collection_ids'] ?? [] as $gid) {
            ShopYourVibeCollectionMapping::query()->where('shopify_collection_id', $gid)->update(['is_active' => false]);
            $card = collect($state['cards'])->firstWhere('collection_gid', $gid);
            if ($card && isset($state['collections'][$gid])) {
                app(ShopYourVibeAssignmentService::class)->syncMapping($draft->collection_gid, $card, $state['collections'][$gid]);
            }
        }
    }

    private function collectionIsUnavailable(ShopYourVibeDraft $draft, ?string $gid): bool
    {
        if (! $gid || str_starts_with($gid, 'new:')) {
            return false;
        }

        return ! empty($draft->desired['collections'][$gid]['unavailable'])
            || in_array($gid, $draft->desired['missing_collection_ids'] ?? [], true)
            || ShopYourVibeCollectionMapping::isConfirmedMissing($gid)
            || ShopYourVibeCollectionMapping::query()->where('parent_collection_id', $draft->collection_gid)
                ->where('shopify_collection_id', $gid)->where('is_active', false)->exists();
    }

    private function assertMissingTargetsUnchanged(ShopYourVibeDraft $draft, array $state, array $baseline): void
    {
        foreach (array_keys($draft->remote_jobs ?? []) as $gid) {
            $this->assertCollectionAvailable($draft, $gid);
        }
        foreach ($state['collections'] as $gid => $collection) {
            if ($this->collectionIsUnavailable($draft, $gid)
                && (! isset($baseline['collections'][$gid]) || $this->collectionDifferent($baseline['collections'][$gid], $collection))) {
                $this->assertCollectionAvailable($draft, $gid);
            }
        }
        $beforeCards = collect($baseline['cards'])->keyBy('key');
        $afterCards = collect($state['cards'])->keyBy('key');
        foreach ($beforeCards->merge($afterCards) as $key => $card) {
            $gids = array_unique([
                $beforeCards->get($key)['collection_gid'] ?? null, $afterCards->get($key)['collection_gid'] ?? null,
                $beforeCards->get($key)['unavailable_collection_gid'] ?? null, $afterCards->get($key)['unavailable_collection_gid'] ?? null,
            ]);
            foreach ($gids as $gid) {
                if ($this->collectionIsUnavailable($draft, $gid)
                    && ($this->fields($beforeCards->get($key)) !== $this->fields($afterCards->get($key))
                        || array_search($key, array_column($baseline['cards'], 'key'), true) !== array_search($key, array_column($state['cards'], 'key'), true)
                        || in_array($card['id'] ?? null, $baseline['reference_ids'], true) !== in_array($card['id'] ?? null, array_column($state['cards'], 'id'), true))) {
                    $this->assertCollectionAvailable($draft, $gid);
                }
            }
        }
        foreach (array_merge(array_values($state['delete_cards'] ?? []), array_values($state['delete_collections'] ?? [])) as $deletion) {
            $this->assertCollectionAvailable($draft, $deletion['gid'] ?? $deletion['card']['collection_gid'] ?? null);
        }
    }

    private function rememberCollectionRecord(?int $importId, string $gid, string $handle, string $title): void
    {
        if (! $importId) {
            return;
        }
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::firstOrCreate(['shopify_id' => $gid], [
            'import_id' => $importId,
            'handle' => $handle,
            'title' => $title,
            'sync_status' => ShopifyCollection::SYNC_STATUS_SYNCED,
            'last_synced_at' => now(),
        ]));
    }

    private function holdOperationLock(ShopYourVibeDraft $draft): void
    {
        $key = 'shop-your-vibe-op:'.$draft->collection_gid;
        if ($this->lockToken !== null) {
            $this->assertOperationOwnership();
            return;
        }
        if (Cache::has($key)) throw new ShopYourVibeBusyException('An earlier Shopify worker still holds this collection.');
        $lock = Cache::lock($key, 900);
        if (! $lock->get()) throw new ShopYourVibeBusyException('Another Shop Your Vibe operation is running for this collection.');
        $this->lockToken = $lock->owner();
        $this->operationKey = $key;
    }

    private ?string $operationKey = null;

    private function assertOperationOwnership(): void
    {
        if ($this->lockToken !== null && ! Cache::restoreLock($this->operationKey, $this->lockToken)->isOwnedByCurrentProcess()) {
            throw new RuntimeException('The Shopify operation lease was lost. No further writes or checkpoints are authorized.');
        }
    }

    public static function operationRunning(string $gid): bool
    {
        $key = 'shop-your-vibe-op:'.$gid;
        if (Cache::has($key)) return true;
        $probe = Cache::lock($key, 1);
        if (! $probe->get()) return true;
        $probe->release();
        return false;
    }

    private function releaseOperationLock(ShopYourVibeDraft $draft): void
    {
        if (! $this->lockToken) {
            return;
        }
        $key = 'shop-your-vibe-op:'.$draft->collection_gid;
        Cache::restoreLock($key, $this->lockToken)->release();
        $this->lockToken = null;
        $this->operationKey = null;
    }

    /**
     * A synchronized draft can be edited with an old Livewire revision, so its snapshot
     * may not contain vibes added in Shopify afterwards. Those ids stay on the live list.
     * Cards this snapshot already knows about must still be unchanged.
     */
    private function assertCreateLayout(array $snapshot, array $remote): void
    {
        $this->assertCardsUnchanged($snapshot, $remote, $snapshot['cards'] ?? []);
    }

    private function assertLayoutCompatible(array $snapshot, array $remote, ?string $allowMissingId = null): void
    {
        $this->assertCardsUnchanged($snapshot, $remote, $snapshot['cards'] ?? [], $allowMissingId ? [$allowMissingId] : []);
    }

    private function assertCardsUnchanged(array $baseline, array $remote, array $cards, array $ignoreMissingIds = []): void
    {
        $conflicts = [];
        foreach ($cards as $card) {
            if (empty($card['id'])) {
                continue;
            }
            if (in_array($card['id'], $ignoreMissingIds, true) && ! collect($remote['cards'] ?? [])->firstWhere('id', $card['id'])) {
                continue;
            }
            $before = collect($baseline['cards'] ?? [])->firstWhere('id', $card['id']);
            $current = collect($remote['cards'] ?? [])->firstWhere('id', $card['id']);
            $referenced = in_array($card['id'], $baseline['reference_ids'] ?? [], true)
                || in_array($card['id'], $remote['reference_ids'] ?? [], true);
            if (! $current && ! $referenced) {
                $current = $before;
            }
            $difference = $this->cardFieldDifference($before, $current, $card);
            if ($difference !== []) {
                $conflicts[] = $this->describeCardConflict($card, $difference);
            }
        }
        if ($conflicts !== []) {
            throw new RuntimeException('A preview card changed in Shopify. Refresh and review before pushing. '.implode(' ', $conflicts));
        }
    }

    private function cardFieldDifference(?array $before, ?array $current, array $desired): array
    {
        if (! $current) {
            return ['presence' => 'missing from Shopify'];
        }
        $remoteFields = $this->shopifyComparable($current);
        $baseFields = $this->shopifyComparable($before);
        $wantFields = $this->shopifyComparable($desired);
        if ($remoteFields === $baseFields || $remoteFields === $wantFields) {
            return [];
        }
        $difference = [];
        foreach ($remoteFields as $key => $value) {
            if ($value !== ($baseFields[$key] ?? '') && $value !== ($wantFields[$key] ?? '')) {
                $difference[$key] = $value;
            }
        }

        return $difference;
    }

    private function describeCardConflict(array $card, array $difference): string
    {
        $name = trim((string) ($card['name'] ?? 'Untitled'));
        $id = (string) ($card['id'] ?? 'unsaved');

        return 'Card "'.$name.'" ('.$id.') differs in: '.implode(', ', array_keys($difference)).'.';
    }

    private function shopifyComparable(?array $card): array
    {
        return [
            'name' => trim((string) ($card['name'] ?? '')),
            'image' => trim((string) ($card['image'] ?? '')),
            'link' => ShopYourVibeShopify::canonicalLink((string) ($card['link'] ?? '')),
        ];
    }

    private function card(array $state, string $key): array
    {
        return collect($state['cards'])->firstWhere('key', $key) ?? throw new RuntimeException('This vibe is no longer in the draft.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, string>  $clientIds
     * @return array<int, string>
     */
    public function orderWithCurrentMembership(array $products, array $clientIds): array
    {
        $visibleIds = array_column(ShopYourVibeVisibleProducts::visible($products), 'id');
        $clientVisible = array_values(array_filter(
            $clientIds,
            fn ($id): bool => in_array($id, $visibleIds, true)
        ));
        $expected = $visibleIds;
        $actual = $clientVisible;
        sort($expected);
        sort($actual);
        if ($actual === $expected) {
            return count($clientIds) === count($products)
                ? array_values($clientIds)
                : ShopYourVibeVisibleProducts::fullOrder($products, $clientVisible);
        }
        $extra = array_values(array_diff($clientVisible, $visibleIds));
        if ($extra === [] && count($clientVisible) * 2 < count($visibleIds)) {
            throw new RuntimeException('Include every visible product exactly once.');
        }

        return $this->mergeMembershipOrder(array_column($products, 'id'), $clientIds);
    }

    /**
     * @param  array<int, string>  $currentIds
     * @param  array<int, string>  $clientIds
     * @return array<int, string>
     */
    public function mergeMembershipOrder(array $currentIds, array $clientIds): array
    {
        $current = array_values($currentIds);
        $clientSet = [];
        foreach ($clientIds as $id) {
            $clientSet[$id] = true;
        }
        $currentSet = array_fill_keys($current, true);
        $survivors = [];
        $seen = [];
        foreach ($clientIds as $id) {
            if (isset($currentSet[$id]) && ! isset($seen[$id])) {
                $survivors[] = $id;
                $seen[$id] = true;
            }
        }
        $placed = array_fill_keys($survivors, true);
        $result = $survivors;
        foreach ($current as $index => $id) {
            if (isset($clientSet[$id])) {
                continue;
            }
            $anchor = null;
            for ($previous = $index - 1; $previous >= 0; $previous--) {
                if (isset($placed[$current[$previous]])) {
                    $anchor = $current[$previous];
                    break;
                }
            }
            if ($anchor === null) {
                array_unshift($result, $id);
            } else {
                $position = array_search($anchor, $result, true);
                array_splice($result, (int) $position + 1, 0, [$id]);
            }
            $placed[$id] = true;
        }

        return $result;
    }

    public function pendingIsCollectionSortOnly(ShopYourVibeDraft $draft): bool
    {
        if (! $draft->pending) {
            return true;
        }
        $desired = $draft->desired ?? [];
        $snapshot = $draft->snapshot ?? [];
        foreach ($snapshot['collections'] ?? [] as $gid => $collection) {
            if (! isset($desired['collections'][$gid]) || ! is_array($collection)) {
                return false;
            }
            $desired['collections'][$gid]['products'] = $collection['products'] ?? [];
            $desired['collections'][$gid]['sort'] = $collection['sort'] ?? ($desired['collections'][$gid]['sort'] ?? null);
            $desired['collections'][$gid]['enable_manual'] = $collection['enable_manual'] ?? false;
            if (array_key_exists('product_count', $collection)) {
                $desired['collections'][$gid]['product_count'] = $collection['product_count'];
            }
        }
        if (count($desired['collections'] ?? []) !== count($snapshot['collections'] ?? [])) {
            return false;
        }
        if (! isset($snapshot['reference_ids'], $snapshot['cards'], $desired['cards']) || ! is_array($snapshot['cards']) || ! is_array($desired['cards'])) {
            return false;
        }

        return ! $this->different($snapshot, $desired);
    }

    public function pendingExplicitSortingOnly(ShopYourVibeDraft $draft): bool
    {
        return $draft->pending
            && $this->pendingIsCollectionSortOnly($draft)
            && SyvCollectionSortState::query()
                ->where('draft_id', $draft->id)
                ->where('explicit', true)
                ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
                ->exists();
    }

    private function reorder(array $items, array $ids, string $key): array
    {
        if (count($ids) !== count($items) || count(array_unique($ids)) !== count($ids)
            || array_diff($ids, array_column($items, $key))) {
            throw new RuntimeException('The order must contain every item exactly once.');
        }
        $map = array_column($items, null, $key);

        return array_map(fn ($id) => $map[$id], $ids);
    }

    private function localCollection(string $gid): ShopifyCollection
    {
        $this->shopify->gid($gid, 'Collection');

        return ShopifyCollection::where('shopify_id', $gid)->latest('id')->firstOrFail();
    }

    private function assertCollectionLink(array $card, array $collection): void
    {
        $path = (string) parse_url($card['link'], PHP_URL_PATH);
        if (! preg_match('~^/collections/([^/]+)/?$~', $path, $match)
            || rawurldecode($match[1]) !== $collection['handle']) {
            throw new RuntimeException('This collection link changed in Shopify. Sync the collection catalogue and refresh the preview before editing its products.');
        }
    }

    private function validateLink(string $link): void
    {
        if (! preg_match('~^https?://~i', $link) && ! preg_match('~^/collections/[^/]+/?(?:\?.*)?$~', $link)) {
            throw new RuntimeException('Use a collection path or a complete http or https URL.');
        }
    }

    private function preparedCollectionInput(ShopYourVibeDraft $draft, array $input): array
    {
        if (! array_key_exists('vibe_name', $input)) {
            return $input;
        }
        $parent = $draft->desired['parent'] ?? $draft->snapshot['parent'] ?? [];
        $normalized = self::normalizeVibeName((string) $input['vibe_name'], (string) ($parent['title'] ?? ''));
        if ($message = self::vibeNameError($normalized)) {
            throw ValidationException::withMessages(['vibe_name' => $message]);
        }
        $input['vibe_name'] = $normalized['name'];
        $input['title'] = self::shopifyCollectionTitle((string) ($parent['title'] ?? ''), $normalized['name']);
        $input['handle'] = self::contextualHandle(self::parentHandle($parent), $normalized['name']);

        return $input;
    }

    private function localLink(string $link): ?string
    {
        $host = parse_url($link, PHP_URL_HOST);
        if ($host && ! in_array(strtolower($host), array_map('strtolower', array_filter([
            parse_url(config('services.shopify.storefront_url'), PHP_URL_HOST), config('services.shopify.shop'),
        ])), true)) {
            return null;
        }
        if (preg_match('~^/collections/([^/]+)/?$~', (string) parse_url($link, PHP_URL_PATH), $match)) {
            return ShopifyCollection::where('handle', rawurldecode($match[1]))->whereNotNull('shopify_id')->latest('id')->value('shopify_id');
        }

        return null;
    }
}
