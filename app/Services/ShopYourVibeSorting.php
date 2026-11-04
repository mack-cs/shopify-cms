<?php

namespace App\Services;

use App\Jobs\SendSyvLaunchSortWarningJob;
use App\Jobs\SendSyvSortApprovalEmailJob;
use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\ProductLaunchItem;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\ShopYourVibeDraft;
use App\Models\SyvCollectionSortEvent;
use App\Models\SyvCollectionSortState;
use App\Models\SyvNotification;
use App\Models\SyvVerifiedMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Per product and collection sorting. Membership used for publication comes from
 * verified Shopify collection reads, never from tags.
 */
class ShopYourVibeSorting
{
    public function __construct(private readonly ShopYourVibeShopify $shopify) {}

    /** @param array<string, mixed> $state */
    public function rememberVerifiedDraft(ShopYourVibeDraft $draft, array $state): void
    {
        foreach ($state['collections'] ?? [] as $gid => $collection) {
            if (! is_string($gid) || ! is_array($collection) || ! empty($collection['unavailable']) || ! isset($collection['retrieval_started_at'])) {
                continue;
            }
            $ids = [];
            foreach ($collection['products'] ?? [] as $product) {
                $id = $product['id'] ?? null;
                if (is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)) {
                    $ids[] = $id;
                }
            }
            $this->replaceMembership($draft, $gid, $ids, (string) ($collection['title'] ?? ''));
        }
    }

    /** @param array<int, string> $productGids */
    public function replaceMembership(ShopYourVibeDraft $draft, string $collectionGid, array $productGids, string $title): void
    {
        $productGids = array_values(array_unique($productGids));
        $existing = SyvVerifiedMembership::query()
            ->where('draft_id', $draft->id)
            ->where('collection_gid', $collectionGid)
            ->get()
            ->keyBy('product_gid');
        $now = now();
        $seen = [];
        foreach ($productGids as $gid) {
            $seen[$gid] = true;
            $row = $existing->get($gid);
            if ($row instanceof SyvVerifiedMembership) {
                $row->forceFill([
                    'parent_collection_gid' => $draft->collection_gid,
                    'collection_title' => $title !== '' ? $title : $row->collection_title,
                    'last_verified_at' => $now,
                ])->save();

                continue;
            }
            SyvVerifiedMembership::query()->create([
                'draft_id' => $draft->id,
                'parent_collection_gid' => $draft->collection_gid,
                'collection_gid' => $collectionGid,
                'collection_title' => $title !== '' ? $title : null,
                'product_gid' => $gid,
                'first_verified_at' => $now,
                'last_verified_at' => $now,
            ]);
        }
        foreach ($existing as $gid => $row) {
            if (! isset($seen[$gid])) {
                $row->delete();
            }
        }
    }

    public static function membershipLockKey(int $draftId, string $gid): string
    {
        return 'syv-membership:'.$draftId.':'.$gid;
    }

    /**
     * Sorted Shopify product IDs. Order is ignored so a sort is not a membership change.
     *
     * @param  array<int, mixed>  $products
     * @return array<int, string>
     */
    public function membershipIds(array $products): array
    {
        $ids = array_values(array_filter(
            array_column($products, 'id'),
            fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
        ));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $savedCollection
     * @param  array<string, mixed>  $liveCollection
     * @return array{gid:string,title:string,verified_at:?string,saved_count:int,shopify_count:int,added:array<int,array{id:string,title:string}>,removed:array<int,array{id:string,title:string}>,matches:bool}
     */
    public function describeMembershipChange(ShopYourVibeDraft $draft, string $gid, array $savedCollection, array $liveCollection): array
    {
        $savedProducts = is_array($savedCollection['products'] ?? null) ? $savedCollection['products'] : [];
        $liveProducts = is_array($liveCollection['products'] ?? null) ? $liveCollection['products'] : [];
        $savedIds = $this->membershipIds($savedProducts);
        $liveIds = $this->membershipIds($liveProducts);
        $savedSet = array_fill_keys($savedIds, true);
        $liveSet = array_fill_keys($liveIds, true);
        $added = [];
        $removed = [];
        foreach ($liveProducts as $product) {
            $id = $product['id'] ?? null;
            if (! is_string($id) || ! isset($liveSet[$id]) || isset($savedSet[$id]) || isset($added[$id])) {
                continue;
            }
            $added[$id] = ['id' => $id, 'title' => (string) ($product['title'] ?? $id)];
        }
        foreach ($savedProducts as $product) {
            $id = $product['id'] ?? null;
            if (! is_string($id) || ! isset($savedSet[$id]) || isset($liveSet[$id]) || isset($removed[$id])) {
                continue;
            }
            $removed[$id] = ['id' => $id, 'title' => (string) ($product['title'] ?? $id)];
        }
        $verifiedAt = SyvVerifiedMembership::query()
            ->where('draft_id', $draft->id)
            ->where('collection_gid', $gid)
            ->max('last_verified_at');
        if (! is_string($verifiedAt) || $verifiedAt === '') {
            $fallback = $savedCollection['retrieval_started_at'] ?? $draft->refreshed_at;
            $verifiedAt = $fallback instanceof \DateTimeInterface ? $fallback->format(\DateTimeInterface::ATOM) : (is_string($fallback) && $fallback !== '' ? $fallback : null);
        }

        return [
            'gid' => $gid,
            'title' => (string) ($liveCollection['title'] ?? $savedCollection['title'] ?? $gid),
            'verified_at' => $verifiedAt,
            'saved_count' => count($savedIds),
            'shopify_count' => count($liveIds),
            'added' => array_values($added),
            'removed' => array_values($removed),
            'matches' => $savedIds === $liveIds,
        ];
    }

    /**
     * Keep the proposed relative order of products that are still in Shopify.
     * Insert new Shopify products immediately before the next surviving product
     * in the current Shopify order, or at the end when none follows.
     *
     * @param  array<int, string>  $proposedIds
     * @param  array<int, string>  $shopifyIds
     * @return array<int, string>
     */
    public function mergePreservingSort(array $proposedIds, array $shopifyIds): array
    {
        $shopifySet = array_fill_keys($shopifyIds, true);
        $seen = [];
        $survivors = [];
        foreach ($proposedIds as $id) {
            if (isset($shopifySet[$id]) && ! isset($seen[$id])) {
                $survivors[] = $id;
                $seen[$id] = true;
            }
        }
        $result = $survivors;
        $placed = array_fill_keys($survivors, true);
        foreach ($shopifyIds as $index => $id) {
            if (isset($placed[$id])) {
                continue;
            }
            $anchor = null;
            for ($next = $index + 1, $count = count($shopifyIds); $next < $count; $next++) {
                if (isset($placed[$shopifyIds[$next]])) {
                    $anchor = $shopifyIds[$next];
                    break;
                }
            }
            if ($anchor === null) {
                $result[] = $id;
            } else {
                $position = array_search($anchor, $result, true);
                array_splice($result, (int) $position, 0, [$id]);
            }
            $placed[$id] = true;
        }

        return $result;
    }

    /**
     * @param  array{gid?:string,title?:string,retrieval_started_at?:string,products:array<int,array{id:string,title?:string}>}  $live
     */
    public function reconcileMembership(int $draftId, string $gid, array $live, int $scopeRevision): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($draftId, $gid, $live, $scopeRevision): ShopYourVibeDraft {
            $this->actor();
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            if (in_array($draft->status, ['pushing', 'creating', 'deleting'], true)) {
                throw new RuntimeException('Wait for the active Shopify operation before refreshing this collection.');
            }
            $scope = ShopYourVibeEditing::sortScope($gid);
            $state = $draft->editing_state ?? [];
            $lock = $state['locks'][$scope] ?? [];
            if (($lock['status'] ?? null) === 'pushing') {
                throw new RuntimeException('Wait for the Shopify push of this collection to finish before refreshing its membership.');
            }
            $editing = app(ShopYourVibeEditing::class);
            if ($editing->active($lock) && (int) ($lock['owner_id'] ?? 0) !== (int) auth()->id()) {
                throw new RuntimeException(($lock['owner_name'] ?? 'Another editor').' is currently editing this collection. Their pending changes are protected for 14 minutes after their last activity.');
            }
            $lock = $editing->guardPendingSort($draft, $scope, $lock);
            $current = array_key_exists('revision', $lock) ? (int) $lock['revision'] : $scopeRevision;
            if ($current !== $scopeRevision) {
                throw new RuntimeException('This draft changed in another tab or session. Reopen it before editing.');
            }
            $desired = $draft->desired ?? [];
            $snapshot = $draft->snapshot ?? [];
            if (! isset($desired['collections'][$gid]) || ! is_array($desired['collections'][$gid])) {
                throw new RuntimeException('Open this collection’s products before refreshing its membership.');
            }
            $shopifyIds = [];
            $liveTitles = [];
            foreach ($live['products'] ?? [] as $product) {
                $id = $product['id'] ?? null;
                if (! is_string($id) || ! preg_match('~^gid://shopify/Product/\d+$~', $id) || isset($liveTitles[$id])) {
                    throw new RuntimeException('Shopify membership for '.$gid.' could not be verified. Sorting was not changed.');
                }
                $shopifyIds[] = $id;
                $liveTitles[$id] = (string) ($product['title'] ?? $id);
            }
            $savedProducts = $desired['collections'][$gid]['products'] ?? [];
            $baselineProducts = $snapshot['collections'][$gid]['products'] ?? $savedProducts;
            $savedIds = array_values(array_filter(
                array_column(is_array($savedProducts) ? $savedProducts : [], 'id'),
                fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
            ));
            $baselineIds = array_values(array_filter(
                array_column(is_array($baselineProducts) ? $baselineProducts : [], 'id'),
                fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
            ));
            $pendingSort = $savedIds !== $baselineIds || SyvCollectionSortState::query()
                ->where('draft_id', $draft->id)
                ->where('collection_gid', $gid)
                ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
                ->where('explicit', true)
                ->exists();
            $proposedIds = $pendingSort ? $this->mergePreservingSort($savedIds, $shopifyIds) : $shopifyIds;
            $cards = $this->membershipCards($savedProducts, $baselineProducts, $liveTitles);
            $title = (string) ($live['title'] ?? $desired['collections'][$gid]['title'] ?? $gid);
            $retrieved = (string) ($live['retrieval_started_at'] ?? now()->toISOString());
            $desired['collections'][$gid]['products'] = $this->cardsInOrder($cards, $proposedIds, $liveTitles);
            $desired['collections'][$gid]['product_count'] = count($shopifyIds);
            $desired['collections'][$gid]['retrieval_started_at'] = $retrieved;
            if (! isset($snapshot['collections'][$gid]) || ! is_array($snapshot['collections'][$gid])) {
                $snapshot['collections'][$gid] = $desired['collections'][$gid];
            }
            $snapshot['collections'][$gid]['products'] = $this->cardsInOrder($cards, $shopifyIds, $liveTitles);
            $snapshot['collections'][$gid]['product_count'] = count($shopifyIds);
            $snapshot['collections'][$gid]['retrieval_started_at'] = $retrieved;
            $snapshot['collections'][$gid]['title'] = $snapshot['collections'][$gid]['title'] ?? $title;
            $desired['collections'][$gid]['title'] = $desired['collections'][$gid]['title'] ?? $title;
            $shopifySet = array_fill_keys($shopifyIds, true);
            $rows = SyvCollectionSortState::query()
                ->where('draft_id', $draft->id)
                ->where('collection_gid', $gid)
                ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
                ->lockForUpdate()
                ->get();
            foreach ($rows as $row) {
                if (! isset($shopifySet[$row->product_key])) {
                    $row->delete();

                    continue;
                }
                $from = array_search($row->product_key, $shopifyIds, true);
                $to = array_search($row->product_key, $proposedIds, true);
                if ($from === false || $to === false) {
                    $row->delete();

                    continue;
                }
                $row->forceFill([
                    'from_position' => ((int) $from) + 1,
                    'to_position' => ((int) $to) + 1,
                ])->save();
            }
            $this->invalidateUnpublishedApproval($draft, $gid);
            $this->replaceMembership($draft, $gid, $shopifyIds, $title);
            $lock['revision'] = $current + 1;
            $state['locks'][$scope] = $lock;
            $state['membership_prompt'] = null;
            $pending = app(ShopYourVibeWorkflow::class)->different($snapshot, $desired);
            $draft->update([
                'desired' => $desired,
                'snapshot' => $snapshot,
                'pending' => $pending,
                'status' => $pending ? 'pending' : 'synced',
                'editing_state' => $state,
            ]);

            return $draft->fresh() ?? $draft;
        });
    }

    /**
     * @param  array<int, mixed>  $savedProducts
     * @param  array<int, mixed>  $baselineProducts
     * @param  array<string, string>  $liveTitles
     * @return array<string, array<string, mixed>>
     */
    private function membershipCards(array $savedProducts, array $baselineProducts, array $liveTitles): array
    {
        $cards = [];
        foreach ([$baselineProducts, $savedProducts] as $products) {
            foreach ($products as $product) {
                if (is_array($product) && is_string($product['id'] ?? null)) {
                    $cards[$product['id']] = $product;
                }
            }
        }
        $missing = array_values(array_diff(array_keys($liveTitles), array_keys($cards)));
        if ($missing !== []) {
            $local = Product::query()->with(['images', 'variants'])->whereIn('shopify_id', $missing)->get()->keyBy('shopify_id');
            foreach ($missing as $id) {
                $product = $local->get($id);
                $cards[$id] = [
                    'id' => $id,
                    'title' => $product?->title ?: $liveTitles[$id],
                    'image' => $product?->images?->sortBy('position')->first()?->src,
                    'sku' => $product?->variants?->first()?->sku,
                    'status' => 'ACTIVE',
                    'tags' => [],
                ];
            }
        }

        return $cards;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cards
     * @param  array<int, string>  $order
     * @param  array<string, string>  $liveTitles
     * @return array<int, array<string, mixed>>
     */
    private function cardsInOrder(array $cards, array $order, array $liveTitles): array
    {
        $result = [];
        foreach ($order as $id) {
            $card = $cards[$id] ?? ['id' => $id, 'title' => $liveTitles[$id] ?? $id, 'status' => 'ACTIVE', 'tags' => []];
            if (! isset($card['title']) || $card['title'] === '') {
                $card['title'] = $liveTitles[$id] ?? $id;
            }
            $result[] = $card;
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     * @param  array<int, string>  $explicitIds
     */
    public function recordExplicitMoves(ShopYourVibeDraft $draft, string $collectionGid, array $before, array $after, array $explicitIds): void
    {
        $actor = $this->actor();
        $explicit = array_fill_keys($explicitIds, true);
        foreach ($explicitIds as $id) {
            if (! isset($explicit[$id])) {
                continue;
            }
            $from = array_search($id, $before, true);
            $to = array_search($id, $after, true);
            if ($from === false || $to === false || $from === $to) {
                continue;
            }
            $this->storeExplicit($draft, $collectionGid, $id, $actor, ((int) $from) + 1, ((int) $to) + 1, 'explicit_sort');
        }
    }

    public function markReviewed(int $draftId, int $revision, string $collectionGid, string $productKey, ?int $scopeRevision = null): SyvCollectionSortState
    {
        return DB::transaction(function () use ($draftId, $collectionGid, $productKey, $scopeRevision): SyvCollectionSortState {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            $editing = app(ShopYourVibeEditing::class);
            $scope = ShopYourVibeEditing::sortScope($collectionGid);
            $known = $scopeRevision ?? (int) ($draft->editing_state['locks'][$scope]['revision'] ?? 0);
            $editing->assertScopeRevision($draft, $scope, $known, false);
            if (auth()->check()) {
                $editing->authorizeScope($draft, $scope);
            }
            $products = $draft->desired['collections'][$collectionGid]['products'] ?? null;
            if (! is_array($products) || ! in_array($productKey, array_column($products, 'id'), true)) {
                throw new RuntimeException('That product is not in this collection.');
            }
            $position = array_search($productKey, array_column($products, 'id'), true);
            $row = $this->storeExplicit($draft, $collectionGid, $productKey, $this->actor(), ((int) $position) + 1, ((int) $position) + 1, 'mark_reviewed');
            $this->invalidateUnpublishedApproval($draft, $collectionGid);

            return $row;
        });
    }

    /**
     * Apply a Shopify-confirmed membership tag change to pending collection orders.
     * Planned draft tags must not be passed here.
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public function reconcileMembershipTags(string $productGid, array $before, array $after): void
    {
        $beforeKeys = array_fill_keys(array_map(fn ($tag) => mb_strtolower(trim((string) $tag)), $before), true);
        $afterKeys = array_fill_keys(array_map(fn ($tag) => mb_strtolower(trim((string) $tag)), $after), true);
        $mappings = ShopYourVibeCollectionMapping::query()
            ->where('is_active', true)
            ->whereNotNull('membership_tag')
            ->get();
        $add = [];
        $remove = [];
        foreach ($mappings as $mapping) {
            $key = mb_strtolower(trim((string) $mapping->membership_tag));
            if ($key === '') {
                continue;
            }
            $had = isset($beforeKeys[$key]);
            $has = isset($afterKeys[$key]);
            if ($has && ! $had) {
                $add[] = (string) $mapping->shopify_collection_id;
            } elseif ($had && ! $has) {
                $remove[] = (string) $mapping->shopify_collection_id;
            }
        }
        $this->reconcileConfirmedMembership($productGid, $add, $remove);
    }

    /**
     * @param  array<int, string>  $addGids
     * @param  array<int, string>  $removeGids
     */
    public function reconcileConfirmedMembership(string $productGid, array $addGids, array $removeGids): void
    {
        $addGids = array_values(array_unique(array_filter($addGids)));
        $removeGids = array_values(array_unique(array_filter($removeGids, fn ($gid) => ! in_array($gid, $addGids, true))));
        if ($productGid === '' || ($addGids === [] && $removeGids === [])) {
            return;
        }
        $gids = array_values(array_unique(array_merge($addGids, $removeGids)));
        $draftIds = ShopYourVibeDraft::query()->where('pending', true)->pluck('id')
            ->merge(SyvCollectionSortState::query()->whereIn('collection_gid', $gids)->where('status', '!=', SyvCollectionSortState::PUBLISHED)->pluck('draft_id'))
            ->unique()
            ->values();
        $product = Product::query()->with(['images', 'variants'])->where('shopify_id', $productGid)->latest('id')->first();
        $card = [
            'id' => $productGid,
            'title' => $product?->title ?: 'Product',
            'image' => $product?->images?->sortBy('position')->first()?->src,
            'sku' => $product?->variants?->first()?->sku,
        ];
        foreach ($draftIds as $draftId) {
            DB::transaction(function () use ($draftId, $productGid, $addGids, $removeGids, $card): void {
                $draft = ShopYourVibeDraft::query()->lockForUpdate()->find($draftId);
                if (! $draft) {
                    return;
                }
                $desired = $draft->desired ?? [];
                $changed = [];
                foreach ($addGids as $gid) {
                    if (! $this->membershipAffectsSort($draft, $gid) || ! isset($desired['collections'][$gid]['products']) || ! is_array($desired['collections'][$gid]['products'])) {
                        continue;
                    }
                    $ids = array_column($desired['collections'][$gid]['products'], 'id');
                    if (in_array($productGid, $ids, true)) {
                        continue;
                    }
                    $desired['collections'][$gid]['products'][] = $card;
                    $changed[] = $gid;
                }
                foreach ($removeGids as $gid) {
                    if (! $this->membershipAffectsSort($draft, $gid) || ! isset($desired['collections'][$gid]['products']) || ! is_array($desired['collections'][$gid]['products'])) {
                        continue;
                    }
                    $before = count($desired['collections'][$gid]['products']);
                    $desired['collections'][$gid]['products'] = array_values(array_filter(
                        $desired['collections'][$gid]['products'],
                        fn ($item) => ($item['id'] ?? null) !== $productGid
                    ));
                    if (count($desired['collections'][$gid]['products']) !== $before) {
                        $changed[] = $gid;
                    }
                }
                if ($changed === []) {
                    return;
                }
                $pending = app(ShopYourVibeWorkflow::class)->different($draft->snapshot ?? [], $desired);
                $draft->update([
                    'desired' => $desired,
                    'pending' => $pending,
                    'status' => $pending ? 'pending' : $draft->status,
                ]);
                foreach (array_unique($changed) as $gid) {
                    $this->invalidateUnpublishedApproval($draft, $gid);
                    ChangeLog::create([
                        'changed_by' => auth()->id(),
                        'source' => 'shop_your_vibe_membership',
                        'model_type' => ShopYourVibeDraft::class,
                        'model_id' => $draft->id,
                        'field' => 'collection_membership',
                        'old_value' => $gid,
                        'new_value' => json_encode(array_column($desired['collections'][$gid]['products'] ?? [], 'id')),
                    ]);
                }
            });
        }
    }

    private function membershipAffectsSort(ShopYourVibeDraft $draft, string $gid): bool
    {
        if (! isset($draft->desired['collections'][$gid]['products'])) {
            return false;
        }
        if ($draft->pending) {
            return true;
        }
        $lock = $draft->editing_state['locks'][ShopYourVibeEditing::sortScope($gid)] ?? null;
        if (is_array($lock) && (($lock['revision'] ?? 0) > 0 || ! empty($lock['owner_id']) || ! empty($lock['pending_owner_id']))) {
            return true;
        }
        if (SyvCollectionSortState::query()->where('draft_id', $draft->id)->where('collection_gid', $gid)->where('status', '!=', SyvCollectionSortState::PUBLISHED)->exists()) {
            return true;
        }
        $before = array_column($draft->snapshot['collections'][$gid]['products'] ?? [], 'id');
        $after = array_column($draft->desired['collections'][$gid]['products'] ?? [], 'id');

        return $before !== $after;
    }

    public function submit(int $draftId, int $revision, int $reviewerId, ?string $collectionGid = null, ?int $scopeRevision = null): ShopYourVibeDraft
    {
        $reviewer = User::query()->find($reviewerId);
        if (! $reviewer || ! ShopYourVibeEditing::permitted($reviewer)) {
            throw new RuntimeException('Choose an active reviewer who can edit Shop Your Vibe.');
        }

        [$draft, $notificationId] = DB::transaction(function () use ($draftId, $revision, $reviewer, $collectionGid, $scopeRevision): array {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            $editing = app(ShopYourVibeEditing::class);
            $scope = is_string($collectionGid) && $collectionGid !== '' ? ShopYourVibeEditing::sortScope($collectionGid) : null;
            if ($scope !== null) {
                $editing->assertScopeRevision($draft, $scope, (int) $scopeRevision, false);
                $editing->authorizeScope($draft, $scope);
            } else {
                $editing->authorize($draft, $revision);
            }
            $actor = $this->actor();
            if ((int) $actor->id === (int) $reviewer->id) {
                throw new RuntimeException('Submit sorting to a different reviewer.');
            }
            $rows = SyvCollectionSortState::query()
                ->where('draft_id', $draft->id)
                ->where('explicit', true)
                ->where('highlight', true)
                ->where('status', SyvCollectionSortState::NOT_SORTED)
                ->when($collectionGid, fn ($query) => $query->where('collection_gid', $collectionGid))
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                throw new RuntimeException('There are no explicit sorting changes to submit.');
            }
            $recordedRevision = $scope !== null
                ? (int) ($draft->editing_state['locks'][$scope]['revision'] ?? $draft->revision)
                : $draft->revision;
            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => SyvCollectionSortState::AWAITING,
                    'submitted_by' => $actor->id,
                    'submitted_at' => now(),
                    'submitted_revision' => $recordedRevision,
                    'reviewer_id' => $reviewer->id,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approved_revision' => null,
                ])->save();
                $this->event($row, 'submitted', $actor->id, $recordedRevision);
            }
            if ($scope !== null) {
                $editing->handoverScope($draft, $scope, $reviewer, $actor);
            } else {
                $editing->handover($draft, $reviewer, $actor);
            }
            $notificationId = $this->queueApprovalEmail($draft, $actor, $reviewer, $rows);

            return [$draft->fresh() ?? $draft, $notificationId];
        });
        if ($notificationId) {
            SendSyvSortApprovalEmailJob::dispatch($notificationId);
        }

        return $draft;
    }

    public function approve(int $draftId, int $revision): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($draftId, $revision): ShopYourVibeDraft {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            $actor = $this->assignedReviewer($draft, $revision, 'approve this revision');
            $rows = SyvCollectionSortState::query()
                ->where('draft_id', $draft->id)
                ->where('status', SyvCollectionSortState::AWAITING)
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                throw new RuntimeException('There is no submitted sorting revision to approve.');
            }
            $alreadyApproved = $rows->every(fn (SyvCollectionSortState $row): bool => $row->approved_at !== null
                && (int) $row->approved_revision === (int) $draft->revision
                && (int) $row->approved_by === (int) $actor->id);
            if ($alreadyApproved) {
                return $draft;
            }
            foreach ($rows as $row) {
                $row->forceFill([
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'approved_revision' => $draft->revision,
                ])->save();
                $this->event($row, 'approved', $actor->id, $draft->revision);
            }

            return $draft->fresh() ?? $draft;
        });
    }

    public function requestChanges(int $draftId, int $revision, string $feedback): ShopYourVibeDraft
    {
        $feedback = trim($feedback);
        if ($feedback === '' || mb_strlen($feedback) > 4000) {
            throw ValidationException::withMessages([
                'sortChangeFeedback' => 'Enter review feedback before requesting changes (maximum 4000 characters).',
            ]);
        }

        return DB::transaction(function () use ($draftId, $revision, $feedback): ShopYourVibeDraft {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            $actor = $this->assignedReviewer($draft, $revision, 'request changes to this revision');
            $rows = SyvCollectionSortState::query()
                ->where('draft_id', $draft->id)
                ->where('status', SyvCollectionSortState::AWAITING)
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                throw new RuntimeException('There is no submitted sorting revision to return.');
            }
            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => SyvCollectionSortState::NOT_SORTED,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approved_revision' => null,
                ])->save();
                $this->event($row, 'changes_requested', $actor->id, $draft->revision, null, null, $feedback);
            }
            app(ShopYourVibeEditing::class)->returnForChanges($draft, $actor, $feedback);
            $draft->revision = $draft->revision + 1;
            $draft->save();

            return $draft->fresh() ?? $draft;
        });
    }

    public function approveScope(int $draftId, string $gid, int $scopeRevision): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($draftId, $gid, $scopeRevision): ShopYourVibeDraft {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            $scope = ShopYourVibeEditing::sortScope($gid);
            $actor = $this->scopeReviewer($draft, $scope, $scopeRevision, 'approve this revision');
            $rows = $this->awaitingRows($draft, $gid);
            if ($rows->isEmpty()) {
                throw new RuntimeException('There is no submitted sorting revision to approve.');
            }
            $already = $rows->every(fn (SyvCollectionSortState $row): bool => $row->approved_at !== null
                && (int) $row->approved_revision === $scopeRevision
                && (int) $row->approved_by === (int) $actor->id);
            if ($already) {
                return $draft;
            }
            foreach ($rows as $row) {
                $row->forceFill([
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'approved_revision' => $scopeRevision,
                ])->save();
                $this->event($row, 'approved', $actor->id, $scopeRevision);
            }

            return $draft->fresh() ?? $draft;
        });
    }

    public function requestScopeChanges(int $draftId, string $gid, int $scopeRevision, string $feedback): ShopYourVibeDraft
    {
        $feedback = trim($feedback);
        if ($feedback === '' || mb_strlen($feedback) > 4000) {
            throw ValidationException::withMessages([
                'sortChangeFeedback' => 'Enter review feedback before requesting changes (maximum 4000 characters).',
            ]);
        }

        return DB::transaction(function () use ($draftId, $gid, $scopeRevision, $feedback): ShopYourVibeDraft {
            $draft = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draftId);
            $scope = ShopYourVibeEditing::sortScope($gid);
            $actor = $this->scopeReviewer($draft, $scope, $scopeRevision, 'request changes to this revision');
            $rows = $this->awaitingRows($draft, $gid);
            if ($rows->isEmpty()) {
                throw new RuntimeException('There is no submitted sorting revision to return.');
            }
            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => SyvCollectionSortState::NOT_SORTED,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approved_revision' => null,
                ])->save();
                $this->event($row, 'changes_requested', $actor->id, $scopeRevision, null, null, $feedback);
            }
            app(ShopYourVibeEditing::class)->returnScope($draft, $scope, $actor, $feedback);

            return $draft->fresh() ?? $draft;
        });
    }

    private function scopeReviewer(ShopYourVibeDraft $draft, string $scope, int $scopeRevision, string $action): User
    {
        $editing = app(ShopYourVibeEditing::class);
        $editing->assertScopeRevision($draft, $scope, $scopeRevision, false);
        $lock = $draft->editing_state['locks'][$scope] ?? [];
        $actor = $this->actor();
        $reviewerId = $lock['review']['reviewer_id'] ?? null;
        if ((int) $reviewerId !== (int) $actor->id) {
            throw new RuntimeException('Only the assigned reviewer can '.$action.'.');
        }
        if ($editing->active($lock) && (int) ($lock['owner_id'] ?? 0) !== (int) $actor->id) {
            throw new RuntimeException(($lock['owner_name'] ?? 'Another editor').' holds the editing lock.');
        }

        return $actor;
    }

    private function awaitingRows(ShopYourVibeDraft $draft, string $gid): \Illuminate\Support\Collection
    {
        return SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('collection_gid', $gid)
            ->where('status', SyvCollectionSortState::AWAITING)
            ->lockForUpdate()
            ->get();
    }

    public function invalidateUnpublishedApproval(ShopYourVibeDraft $draft, ?string $collectionGid = null): void
    {
        $rows = SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('status', SyvCollectionSortState::AWAITING)
            ->whereNotNull('approved_at')
            ->when($collectionGid, fn ($query) => $query->where('collection_gid', $collectionGid))
            ->when($collectionGid === null, function ($query) use ($draft): void {
                $query->where(function ($query) use ($draft): void {
                    $query->whereNull('approved_revision')->orWhere('approved_revision', '!=', $draft->revision);
                });
            })
            ->get();
        foreach ($rows as $row) {
            $row->forceFill([
                'approved_by' => null,
                'approved_at' => null,
                'approved_revision' => null,
            ])->save();
            $this->event($row, 'approval_invalidated', auth()->id() ? (int) auth()->id() : null, $draft->revision);
        }
    }

    public function assertPushAllowed(ShopYourVibeDraft $draft, ?string $collectionGid = null): void
    {
        if ($collectionGid === null) {
            return;
        }
        $rows = SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->when($collectionGid, fn ($query) => $query->where('collection_gid', $collectionGid))
            ->where('explicit', true)
            ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
            ->get();
        if ($rows->isEmpty()) {
            return;
        }
        if ($rows->contains(fn (SyvCollectionSortState $row): bool => $row->status === SyvCollectionSortState::NOT_SORTED && $row->highlight)) {
            throw new RuntimeException('Submit explicit sorting for approval before pushing.');
        }
        if ($rows->contains(fn (SyvCollectionSortState $row): bool => $row->status === SyvCollectionSortState::AWAITING && ($row->approved_at === null || $row->approved_revision === null))) {
            throw new RuntimeException('The assigned reviewer must approve this exact revision before pushing.');
        }
        $publishing = $rows->filter(function (SyvCollectionSortState $row) use ($draft, $collectionGid): bool {
            if ($collectionGid !== null) {
                return $row->collection_gid === $collectionGid;
            }

            return ! isset($draft->editing_state['locks'][ShopYourVibeEditing::sortScope($row->collection_gid)]);
        });
        $reviewerIds = $publishing->pluck('reviewer_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        if ($reviewerIds->isNotEmpty() && ($reviewerIds->count() !== 1 || $reviewerIds->first() !== (int) auth()->id())) {
            throw new RuntimeException('Only the assigned reviewer can publish this sorting.');
        }
    }

    public function markPublished(ShopYourVibeDraft $draft, int $approvedRevision, ?string $onlyGid = null, array $exceptGids = []): void
    {
        $rows = SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('status', SyvCollectionSortState::AWAITING)
            ->whereNotNull('approved_at')
            ->whereNotNull('approved_revision')
            ->when($onlyGid, fn ($query) => $query->where('collection_gid', $onlyGid))
            ->when($exceptGids !== [], fn ($query) => $query->whereNotIn('collection_gid', $exceptGids))
            ->get();
        foreach ($rows as $row) {
            $row->forceFill([
                'status' => SyvCollectionSortState::PUBLISHED,
                'shopify_confirmed_at' => now(),
                'highlight' => false,
            ])->save();
            $this->event($row, 'shopify_confirmed', $row->approved_by, $approvedRevision);
        }
    }

    public function confirmMatchingOrder(int $draftId, int $revision): ShopYourVibeDraft
    {
        $draft = ShopYourVibeDraft::query()->findOrFail($draftId);
        app(ShopYourVibeEditing::class)->authorize($draft, $revision);
        $rows = SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('status', SyvCollectionSortState::AWAITING)
            ->where('approved_revision', $draft->revision)
            ->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException('Approve this exact revision before confirming Shopify ordering.');
        }
        foreach ($rows->groupBy('collection_gid') as $gid => $group) {
            $remote = $this->shopify->collection((string) $gid);
            $remoteIds = array_values(array_filter(
                array_column($remote['products'] ?? [], 'id'),
                fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
            ));
            $localIds = array_values(array_filter(
                array_column($draft->desired['collections'][$gid]['products'] ?? [], 'id'),
                fn ($id): bool => is_string($id) && preg_match('~^gid://shopify/Product/\d+$~', $id)
            ));
            if ($remoteIds !== $localIds) {
                throw new RuntimeException('Shopify has not confirmed the requested ordering for '.($remote['title'] ?? $gid).'.');
            }
        }
        $this->markPublished($draft, (int) $draft->revision);

        return $draft->fresh() ?? $draft;
    }

    public function releaseUnpublished(ShopYourVibeDraft $draft, int $actorId, ?string $collectionGid = null): void
    {
        $rows = SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->when($collectionGid, fn ($query) => $query->where('collection_gid', $collectionGid))
            ->where('status', '!=', SyvCollectionSortState::PUBLISHED)
            ->get();
        foreach ($rows as $row) {
            $row->forceFill([
                'status' => SyvCollectionSortState::NOT_SORTED,
                'explicit' => false,
                'highlight' => false,
                'submitted_by' => null,
                'submitted_at' => null,
                'submitted_revision' => null,
                'reviewer_id' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approved_revision' => null,
            ])->save();
            $this->event($row, 'discarded', $actorId, $draft->revision);
        }
    }

    public function recordLaunchWarning(ProductLaunchItem $item): void
    {
        if ($item->outcome !== ProductLaunchItem::OUTCOME_LAUNCHED || $item->status_confirmed_at === null) {
            return;
        }
        $item->loadMissing('product', 'run.user');
        $gid = trim((string) ($item->product?->shopify_id ?? ''));
        if ($gid === '') {
            return;
        }
        $outstanding = $this->outstandingForProduct($gid);
        if ($outstanding === []) {
            return;
        }
        $key = 'launch-sort:'.$item->id;
        $payload = [
            'product_name' => (string) ($item->product_title ?: $item->product?->title),
            'sku' => (string) ($item->sku ?? ''),
            'launch_actor' => (string) ($item->run?->user?->name ?? 'Unknown'),
            'launch_at' => $item->status_confirmed_at->toIso8601String(),
            'product_gid' => $gid,
            'collections' => $outstanding,
        ];
        $notification = SyvNotification::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'type' => SyvNotification::TYPE_LAUNCH_WARNING,
                'product_launch_item_id' => $item->id,
                'status' => SyvNotification::PENDING,
                'payload' => $payload,
            ],
        );
        if (! $notification->wasRecentlyCreated) {
            return;
        }
        try {
            SendSyvLaunchSortWarningJob::dispatch($notification->id);
        } catch (\Throwable $exception) {
            $notification->forceFill(['status' => SyvNotification::FAILED, 'last_error' => $exception->getMessage()])->save();
            report($exception);
        }
    }

    /** @return array<int, array{name:string,status:string,url:string,collection_gid:string}> */
    public function outstandingForProduct(string $productGid): array
    {
        $memberships = SyvVerifiedMembership::query()->where('product_gid', $productGid)->get();
        $outstanding = [];
        foreach ($memberships as $membership) {
            $state = SyvCollectionSortState::query()
                ->where('draft_id', $membership->draft_id)
                ->where('collection_gid', $membership->collection_gid)
                ->where('product_key', $productGid)
                ->first();
            $status = $state->status ?? SyvCollectionSortState::NOT_SORTED;
            if ($status === SyvCollectionSortState::PUBLISHED) {
                continue;
            }
            $outstanding[] = [
                'name' => (string) ($membership->collection_title ?: $membership->collection_gid),
                'status' => SyvCollectionSortState::label($status),
                'collection_gid' => $membership->collection_gid,
                'url' => url('/shop-your-vibe?collection='.rawurlencode((string) $membership->parent_collection_gid)),
            ];
        }

        return $outstanding;
    }

    /** @return array<string, mixed> */
    public function view(?ShopYourVibeDraft $draft, ?string $collectionGid = null): array
    {
        if (! $draft) {
            return [
                'highlights' => [],
                'moves' => [],
                'published_moves' => [],
                'published_at' => null,
                'unsubmitted' => 0,
                'awaiting' => 0,
                'approved' => false,
                'reviewer_id' => null,
                'request_status' => SyvCollectionSortState::label(SyvCollectionSortState::NOT_SORTED),
            ];
        }
        $rows = SyvCollectionSortState::query()->where('draft_id', $draft->id)->where('highlight', true)->orderBy('id')->get();
        $highlights = [];
        $moves = [];
        foreach ($rows as $row) {
            $highlights[$row->collection_gid.'|'.$row->product_key] = [
                'status' => SyvCollectionSortState::label($row->status),
                'code' => $row->status,
            ];
            $products = $draft->desired['collections'][$row->collection_gid]['products'] ?? [];
            $snapshot = array_column($draft->snapshot['collections'][$row->collection_gid]['products'] ?? [], 'id');
            $desired = array_column($products, 'id');
            $previous = array_search($row->product_key, $snapshot, true);
            $proposed = array_search($row->product_key, $desired, true);
            $named = collect($products)->firstWhere('id', $row->product_key);
            $moves[] = [
                'collection_gid' => $row->collection_gid,
                'collection' => (string) ($draft->desired['collections'][$row->collection_gid]['title'] ?? $row->collection_gid),
                'product_key' => $row->product_key,
                'product_name' => (string) ($named['title'] ?? $row->product_key),
                'status' => $row->approved_at && $row->status === SyvCollectionSortState::AWAITING
                    ? 'Approved, waiting for Shopify confirmation'
                    : SyvCollectionSortState::label($row->status),
                'previous' => $previous === false ? null : ((int) $previous) + 1,
                'proposed' => $proposed === false ? null : ((int) $proposed) + 1,
                'previous_label' => $previous === false ? 'New product' : (string) (((int) $previous) + 1),
                'proposed_label' => $proposed === false ? 'New product' : (string) (((int) $proposed) + 1),
                'sorter' => User::query()->find($row->sorted_by)?->name,
            ];
        }
        $review = $this->reviewAssignment($draft, $collectionGid, $rows);
        $statusRows = $collectionGid ? $rows->where('collection_gid', $collectionGid) : $rows;
        if ($collectionGid) {
            $moves = array_values(array_filter($moves, fn (array $move): bool => $move['collection_gid'] === $collectionGid));
        }
        $awaiting = $statusRows->where('status', SyvCollectionSortState::AWAITING);
        $approved = $awaiting->isNotEmpty() && $awaiting->every(fn (SyvCollectionSortState $row): bool => $row->approved_at !== null);
        $returned = ! empty($review['changes_requested_feedback'])
            && $statusRows->contains(fn (SyvCollectionSortState $row): bool => $row->status === SyvCollectionSortState::NOT_SORTED)
            && $awaiting->isEmpty();
        $requestStatus = SyvCollectionSortState::label(SyvCollectionSortState::NOT_SORTED);
        if ($statusRows->contains(fn (SyvCollectionSortState $row): bool => $row->status === SyvCollectionSortState::AWAITING)) {
            $requestStatus = $approved
                ? 'Approved, waiting for Shopify confirmation'
                : SyvCollectionSortState::label(SyvCollectionSortState::AWAITING);
        } elseif ($returned) {
            $requestStatus = 'Returned for changes';
        } elseif ($statusRows->isNotEmpty() && $statusRows->every(fn (SyvCollectionSortState $row): bool => $row->status === SyvCollectionSortState::PUBLISHED)) {
            $requestStatus = SyvCollectionSortState::label(SyvCollectionSortState::PUBLISHED);
        }

        $published = $this->publishedMoves($draft, $collectionGid);

        return [
            'highlights' => $highlights,
            'moves' => $moves,
            'published_moves' => $published['moves'],
            'published_at' => $published['at'],
            'unsubmitted' => $statusRows->where('status', SyvCollectionSortState::NOT_SORTED)->count(),
            'awaiting' => $awaiting->count(),
            'approved' => $approved,
            'moved_count' => $statusRows->pluck('product_key')->unique()->count(),
            'request_status' => $requestStatus,
            'reviewer_id' => $review['reviewer_id'] ?? null,
            'reviewer_name' => $review['reviewer_name'] ?? null,
            'submitter_name' => $review['submitted_by_name'] ?? null,
            'submitted_at' => $review['submitted_at'] ?? $rows->first()?->submitted_at?->toIso8601String(),
            'changes_feedback' => $review['changes_requested_feedback'] ?? null,
            'changes_requested_by' => $review['changes_requested_by_name'] ?? null,
            'changes_requested_at' => $review['changes_requested_at'] ?? null,
        ];
    }

    /**
     * @return array{moves: array<int, array<string, string>>, at: ?string}
     */
    private function publishedMoves(ShopYourVibeDraft $draft, ?string $collectionGid): array
    {
        $rows = SyvCollectionSortState::query()
            ->where('draft_id', $draft->id)
            ->where('status', SyvCollectionSortState::PUBLISHED)
            ->where('explicit', true)
            ->whereNotNull('shopify_confirmed_at')
            ->when($collectionGid, fn ($query) => $query->where('collection_gid', $collectionGid))
            ->orderByDesc('shopify_confirmed_at')
            ->get();
        $latest = $rows->first()?->shopify_confirmed_at;
        if ($latest === null) {
            return ['moves' => [], 'at' => null];
        }
        $cutoff = $latest->copy()->subMinutes(2);
        $moves = [];
        foreach ($rows as $row) {
            if ($row->shopify_confirmed_at === null || $row->shopify_confirmed_at->lt($cutoff)) {
                continue;
            }
            $products = $draft->desired['collections'][$row->collection_gid]['products'] ?? [];
            $named = collect($products)->firstWhere('id', $row->product_key);
            $moves[] = [
                'product_name' => (string) ($named['title'] ?? $row->product_key),
                'from' => (string) $row->from_position,
                'to' => (string) $row->to_position,
            ];
        }

        return [
            'moves' => $moves,
            'at' => $latest->timezone(config('app.timezone'))->format('j M Y, H:i'),
        ];
    }

    /** @param Collection<int, SyvCollectionSortState> $rows */
    private function queueApprovalEmail(ShopYourVibeDraft $draft, User $actor, User $reviewer, Collection $rows): ?int
    {
        $revision = (int) $draft->revision;
        $notification = SyvNotification::query()->firstOrCreate(
            ['idempotency_key' => 'sort-approval:'.$draft->id.':'.$revision],
            [
                'type' => SyvNotification::TYPE_SORT_APPROVAL,
                'draft_id' => $draft->id,
                'status' => SyvNotification::PENDING,
                'payload' => [
                    'collection_name' => (string) ($draft->desired['collections'][$draft->collection_gid]['title']
                        ?? $draft->desired['parent']['title']
                        ?? $draft->collection_gid),
                    'submitter_name' => $actor->name,
                    'submitted_at' => now()->toIso8601String(),
                    'moved_count' => $rows->pluck('product_key')->unique()->count(),
                    'status' => SyvCollectionSortState::label(SyvCollectionSortState::AWAITING),
                    'review_url' => url('/shop-your-vibe?collection='.rawurlencode((string) $draft->collection_gid)),
                    'reviewer_id' => $reviewer->id,
                    'collections' => $rows->map(fn (SyvCollectionSortState $row): string => (string) (
                        $draft->desired['collections'][$row->collection_gid]['title'] ?? $row->collection_gid
                    ))->unique()->values()->all(),
                ],
            ],
        );
        if (! $notification->wasRecentlyCreated) {
            return null;
        }

        return $notification->id;
    }

    private function storeExplicit(ShopYourVibeDraft $draft, string $collectionGid, string $productKey, User $actor, int $from, int $to, string $eventType): SyvCollectionSortState
    {
        $row = SyvCollectionSortState::query()->firstOrNew([
            'draft_id' => $draft->id,
            'collection_gid' => $collectionGid,
            'product_key' => $productKey,
        ]);
        $published = $row->exists && $row->status === SyvCollectionSortState::PUBLISHED;
        $awaiting = $row->exists && $row->status === SyvCollectionSortState::AWAITING;
        $reviewerId = $this->reviewerIdFor($draft, $collectionGid);
        $reviewerCorrection = $reviewerId !== 0 && $reviewerId === (int) $actor->id && ! $published;
        $status = $published || ! $awaiting
            ? ($reviewerCorrection ? SyvCollectionSortState::AWAITING : SyvCollectionSortState::NOT_SORTED)
            : $row->status;
        $row->fill([
            'status' => $status,
            'explicit' => true,
            'highlight' => true,
            'from_position' => $from,
            'to_position' => $to,
            'sorted_by' => $actor->id,
            'sorted_at' => now(),
            'shopify_confirmed_at' => $published ? null : $row->shopify_confirmed_at,
            'approved_by' => $published || $awaiting || $reviewerCorrection ? null : $row->approved_by,
            'approved_at' => $published || $awaiting || $reviewerCorrection ? null : $row->approved_at,
            'approved_revision' => $published || $awaiting || $reviewerCorrection ? null : $row->approved_revision,
        ]);
        if ($published) {
            $row->submitted_by = null;
            $row->submitted_at = null;
            $row->submitted_revision = null;
            $row->reviewer_id = null;
        } elseif ($reviewerCorrection) {
            $row->reviewer_id = $actor->id;
            $row->submitted_by = $row->submitted_by ?: ($draft->editing_state['review']['submitted_by'] ?? null);
            $row->submitted_at = $row->submitted_at ?: now();
            $row->submitted_revision = $row->submitted_revision ?: $draft->revision;
        }
        $row->save();
        $this->event($row, $eventType, $actor->id, $draft->revision, $from, $to);

        return $row;
    }

    private function event(SyvCollectionSortState $row, string $type, ?int $actorId, ?int $revision, ?int $from = null, ?int $to = null, ?string $note = null): void
    {
        SyvCollectionSortEvent::query()->create([
            'sort_state_id' => $row->id,
            'draft_id' => $row->draft_id,
            'collection_gid' => $row->collection_gid,
            'product_key' => $row->product_key,
            'type' => $type,
            'actor_id' => $actorId,
            'revision' => $revision,
            'from_position' => $from ?? $row->from_position,
            'to_position' => $to ?? $row->to_position,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, SyvCollectionSortState>  $rows
     * @return array<string, mixed>
     */
    private function reviewAssignment(ShopYourVibeDraft $draft, ?string $collectionGid, Collection $rows): array
    {
        $review = [];
        if (is_string($collectionGid) && $collectionGid !== '') {
            $review = $draft->editing_state['locks'][ShopYourVibeEditing::sortScope($collectionGid)]['review'] ?? [];
        }
        $parent = is_array($draft->editing_state['review'] ?? null) ? $draft->editing_state['review'] : [];
        if (($review['reviewer_id'] ?? null) === null && empty($review['changes_requested_feedback']) && ($parent['reviewer_id'] ?? $parent['changes_requested_feedback'] ?? null) !== null) {
            $review = $parent;
        }
        if (empty($review['reviewer_id'])) {
            $assigned = $rows->first(fn (SyvCollectionSortState $row): bool => $row->reviewer_id !== null
                && ($collectionGid === null || $row->collection_gid === $collectionGid));
            if ($assigned) {
                $review['reviewer_id'] = $assigned->reviewer_id;
                $review['reviewer_name'] = $review['reviewer_name'] ?? User::query()->find($assigned->reviewer_id)?->name;
            }
        }

        return is_array($review) ? $review : [];
    }

    private function reviewerIdFor(ShopYourVibeDraft $draft, string $collectionGid): int
    {
        $scoped = (int) ($draft->editing_state['locks'][ShopYourVibeEditing::sortScope($collectionGid)]['review']['reviewer_id'] ?? 0);
        if ($scoped !== 0) {
            return $scoped;
        }

        return (int) ($draft->editing_state['review']['reviewer_id'] ?? 0);
    }

    private function assignedReviewer(ShopYourVibeDraft $draft, int $revision, string $action): User
    {
        if ((int) $draft->revision !== $revision) {
            throw new RuntimeException('This draft changed. Reload and review the current revision before continuing.');
        }
        $actor = $this->actor();
        $reviewerId = $draft->editing_state['review']['reviewer_id'] ?? null;
        if ((int) $reviewerId !== (int) $actor->id) {
            throw new RuntimeException('Only the assigned reviewer can '.$action.'.');
        }
        $state = $draft->editing_state ?? [];
        if (app(ShopYourVibeEditing::class)->active($state) && (int) ($state['owner_id'] ?? 0) !== (int) $actor->id) {
            throw new RuntimeException(($state['owner_name'] ?? 'Another editor').' holds the editing lock.');
        }

        return $actor;
    }

    private function actor(): User
    {
        $user = auth()->id() ? User::query()->find(auth()->id()) : null;
        if (! ShopYourVibeEditing::permitted($user)) {
            throw new RuntimeException('You do not have permission to sort Shop Your Vibe products.');
        }

        return $user;
    }
}
