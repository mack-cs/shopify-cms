<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ShopYourVibeWorkflow
{
    public function __construct(private readonly ShopYourVibeShopify $shopify) {}

    public function open(string $gid): ShopYourVibeDraft
    {
        $this->shopify->gid($gid, 'Collection');
        if ($draft = ShopYourVibeDraft::where('collection_gid', $gid)->first()) {
            return $draft;
        }
        $this->shopify->definition();
        $state = $this->shopify->parent($gid);

        return ShopYourVibeDraft::firstOrCreate(['collection_gid' => $gid], [
            'snapshot' => $state, 'desired' => $state, 'refreshed_at' => now(),
        ]);
    }

    public function refresh(int $id, int $revision, bool $discard = false): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision);
        if ($draft->pending && ! $discard) {
            throw new RuntimeException('You have pending changes. Confirm refresh and discard changes first.');
        }
        if ($draft->remote_jobs) {
            throw new RuntimeException('A Shopify operation is still awaiting confirmation. Retry the push before refreshing.');
        }
        $this->shopify->definition();
        $state = $this->shopify->parent($draft->collection_gid);
        foreach (array_unique(array_filter(array_column($state['cards'], 'collection_gid'))) as $gid) {
            $state['collections'][$gid] = $this->shopify->collection($gid);
        }

        return DB::transaction(function () use ($id, $revision, $state) {
            $draft = $this->editable($id, $revision, true);
            $draft->update(['snapshot' => $state, 'desired' => $state, 'pending' => false, 'status' => 'synced',
                'last_error' => null, 'progress' => [], 'remote_jobs' => [], 'revision' => $revision + 1, 'refreshed_at' => now()]);

            return $draft;
        });
    }

    public function loadProducts(int $id, int $revision, string $key): ShopYourVibeDraft
    {
        $draft = $this->editable($id, $revision);
        $card = $this->card($draft->desired, $key);
        $gid = $card['collection_gid'] ?? throw new RuntimeException('Choose a synchronized collection link to manage this vibe’s products.');
        if (isset($draft->desired['collections'][$gid])) {
            return $draft;
        }
        $collection = $this->shopify->collection($gid);
        $this->assertCollectionLink($card, $collection);

        return DB::transaction(function () use ($id, $revision, $gid, $collection) {
            $draft = $this->editable($id, $revision, true);
            $desired = $draft->desired;
            $snapshot = $draft->snapshot;
            $desired['collections'][$gid] = $snapshot['collections'][$gid] = $collection;
            $draft->update(['desired' => $desired, 'snapshot' => $snapshot, 'revision' => $revision + 1]);

            return $draft;
        });
    }

    /** Normal edits and drag actions deliberately perform database work only. */
    public function edit(int $id, int $revision, string $operation, array $input = []): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $revision, $operation, $input) {
            $draft = $this->editable($id, $revision, true);
            if ($draft->remote_jobs) {
                throw new RuntimeException('Shopify is still processing this draft. Retry the push to confirm completion before editing.');
            }
            $state = $draft->desired;
            switch ($operation) {
                case 'create_collection':
                    $importId = ShopifyCollection::where('shopify_id', $draft->collection_gid)->latest('id')->value('import_id')
                        ?? Import::orderByDesc('is_current')->latest('id')->value('id');
                    if (! $importId) {
                        throw new RuntimeException('Synchronize the collection catalogue before creating a collection.');
                    }
                    $fields = Validator::make($input, [
                        'title' => 'required|string|max:255',
                        'handle' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                        'image' => ['nullable', 'regex:~^gid://shopify/MediaImage/\d+$~'],
                        'image_path' => ['nullable', 'regex:~^shop-your-vibe/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$~'],
                        'image_url' => 'nullable|url|max:4096',
                    ])->validate();
                    if (ShopifyCollection::where('handle', $fields['handle'])->exists()
                        || in_array($fields['handle'], array_column($state['new_collections'] ?? [], 'handle'), true)) {
                        throw new RuntimeException('This handle already exists. Choose that collection or enter a different handle.');
                    }
                    $key = (string) Str::uuid();
                    $gid = 'new:'.$key;
                    $state['new_collections'][$key] = $fields + ['token' => $key, 'gid' => $gid, 'published' => false, 'import_id' => $importId];
                    $state['collections'][$gid] = ['gid' => $gid, 'title' => $fields['title'], 'handle' => $fields['handle'],
                        'sort' => 'MANUAL', 'manual_supported' => true, 'membership_supported' => true,
                        'enable_manual' => false, 'products' => []];
                    $state['cards'][] = ['key' => $key, 'id' => null, 'handle' => 'cms-vibe-'.$key,
                        'name' => $fields['title'], 'image' => $fields['image'] ?? '', 'image_url' => $fields['image_url'] ?? null,
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
                        'image_url' => 'nullable|url:http,https|max:4096'])->validate();
                    $this->validateLink($fields['link']);
                    if (str_starts_with($fields['link'], '/')) {
                        $fields['link'] = rtrim(config('services.shopify.storefront_url'), '/').$fields['link'];
                    }
                    $fields['image'] = $fields['image'] ?? '';
                    $fields['collection_gid'] = $this->localLink($fields['link']);
                    if (str_starts_with($card['collection_gid'] ?? '', 'new:')) {
                        if ($fields['link'] !== $card['link']) {
                            throw new RuntimeException('Push the new collection before changing its link.');
                        }
                        $fields['collection_gid'] = $card['collection_gid'];
                        if ($fields['image'] !== $card['image']) {
                            $state['new_collections'][$card['key']]['image'] = $fields['image'];
                            unset($state['new_collections'][$card['key']]['image_path']);
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
                    $gids = array_column($state['cards'], 'collection_gid');
                    $state['collections'] = array_intersect_key($state['collections'], array_flip(array_filter($gids)));
                    break;
                case 'reorder_cards':
                    $state['cards'] = $this->reorder($state['cards'], $input['keys'], 'key');
                    break;
                case 'enable_manual':
                case 'add_products':
                case 'remove_product':
                case 'reorder_products':
                    $gid = $input['collection_gid'];
                    if (! in_array($gid, array_column($state['cards'], 'collection_gid'), true) || ! isset($state['collections'][$gid])) {
                        throw new RuntimeException('Open this vibe’s products first.');
                    }
                    $collection = &$state['collections'][$gid];
                    if ($operation === 'enable_manual') {
                        if (! $collection['manual_supported'] || ($input['confirmed'] ?? false) !== true) {
                            throw new RuntimeException('Manual sorting requires explicit confirmation and Shopify support.');
                        }
                        $collection['enable_manual'] = $collection['sort'] !== 'MANUAL';
                    } elseif ($operation === 'reorder_products') {
                        if (! $collection['manual_supported'] || ($collection['sort'] !== 'MANUAL' && ! $collection['enable_manual'])) {
                            throw new RuntimeException('Explicitly enable manual sorting before reordering products.');
                        }
                        $collection['products'] = $this->reorder($collection['products'], $input['ids'], 'id');
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
            $represented = array_filter(array_column($state['cards'], 'collection_gid'));
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
            $draft->update(['desired' => $state, 'pending' => $pending, 'status' => $pending ? 'pending' : 'synced',
                'last_error' => null, 'revision' => $revision + 1]);

            return $draft;
        });
    }

    public function queue(int $id, int $revision): ShopYourVibeDraft
    {
        return DB::transaction(function () use ($id, $revision) {
            $draft = $this->editable($id, $revision, true);
            if (! $draft->pending) {
                throw new RuntimeException('There are no pending changes to push.');
            }
            $draft->update(['status' => 'pushing', 'last_error' => null, 'revision' => $revision + 1]);

            return $draft;
        });
    }

    /** Called by the queue worker only after the explicit push action. */
    public function push(int $id): void
    {
        $lock = Cache::lock('shop-your-vibe-push', 900);
        $draft = ShopYourVibeDraft::findOrFail($id);
        if ($draft->status !== 'pushing') {
            return;
        }
        try {
            if (! $lock->get()) {
                throw new RuntimeException('Another Shop Your Vibe push is running. Please retry shortly.');
            }
            $this->shopify->definition();
            $state = $draft->desired;
            $baseline = $draft->snapshot;
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
            foreach ($state['cards'] as $card) {
                if (! $card['id']) {
                    continue;
                }
                $before = collect($baseline['cards'])->firstWhere('id', $card['id']);
                $current = collect($remote['cards'])->firstWhere('id', $card['id']);
                // Successfully created, not-yet-attached objects are checkpointed in the baseline.
                if (! $current && ! in_array($card['id'], $baseline['reference_ids'], true)) {
                    $current = $before;
                }
                if (! $current || ($this->fields($current) !== $this->fields($before) && $this->fields($current) !== $this->fields($card))) {
                    throw new RuntimeException('A preview card changed in Shopify. Refresh and review before pushing.');
                }
            }
            $currentCollections = [];
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
                if (! $this->collectionDifferent($baseline['collections'][$gid], $desiredCollection)) {
                    continue;
                }
                $current = $this->shopify->collection($gid);
                foreach ($state['cards'] as $card) {
                    if ($card['collection_gid'] === $gid) {
                        $this->assertCollectionLink($card, $current);
                    }
                }
                if ($this->collectionDifferent($baseline['collections'][$gid], $current) && $this->collectionDifferent($current, $desiredCollection)) {
                    throw new RuntimeException($current['title'].' changed in Shopify. Refresh and review before pushing.');
                }
                if (! $current['membership_supported'] && $this->membership($current) !== $this->membership($desiredCollection)) {
                    throw new RuntimeException('Shopify rules control membership in '.$current['title'].'.');
                }
                if (! $current['manual_supported'] && ($desiredCollection['enable_manual']
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
                $state['collections'][$gid] = array_replace($state['collections'][$oldGid], ['gid' => $gid, 'handle' => $created['handle']]);
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
                $baseline['collections'][$gid] = array_replace($state['collections'][$gid], ['products' => []]);
                $currentCollections[$gid] = $baseline['collections'][$gid];
                $progress[] = 'Collection created: '.$created['title'];
                $draft->update(['desired' => $state, 'snapshot' => $baseline, 'progress' => $progress]);
            }
            foreach ($state['new_collections'] ?? [] as $creation) {
                $this->rememberCreatedCollection($draft, $creation);
            }
            foreach ($state['cards'] as $index => $card) {
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

            foreach ($currentCollections as $gid => $current) {
                $desired = $state['collections'][$gid];
                if ($desired['enable_manual'] && $current['sort'] !== 'MANUAL') {
                    $this->shopify->enableManual($gid);
                    $current['sort'] = 'MANUAL';
                    $baseline['collections'][$gid] = $current;
                    $draft->update(['snapshot' => $baseline]);
                    $progress[] = 'Manual sorting enabled: '.$current['title'];
                }
                $wantedIds = array_column($desired['products'], 'id');
                $remove = array_diff(array_column($current['products'], 'id'), $wantedIds);
                foreach (array_chunk($remove, 250) as $chunk) {
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
                        $job = $this->shopify->reorderProducts($gid, $chunk);
                        $this->trackJob($draft, $gid, $job);
                        $current = $this->shopify->collection($gid);
                        $baseline['collections'][$gid] = $current;
                        $this->checkpointCollection($draft, $baseline, $gid);
                    }
                }
                if ($this->collectionDifferent($current, $desired)) {
                    throw new RuntimeException('Product sorting was not confirmed for '.$current['title'].'.');
                }
                $state['collections'][$gid] = $baseline['collections'][$gid] = $current;
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
            $confirmed = $this->shopify->parent($draft->collection_gid);
            if ($confirmed['reference_ids'] !== $ids || array_map($this->fields(...), $confirmed['cards']) !== array_map($this->fields(...), $state['cards'])) {
                throw new RuntimeException('Shopify has not confirmed the final preview layout. Some changes remain pending.');
            }
            $confirmed['collections'] = $state['collections'];
            $draft->update(['snapshot' => $confirmed, 'desired' => $confirmed, 'pending' => false, 'status' => 'synced',
                'last_error' => null, 'remote_jobs' => [], 'progress' => $progress, 'refreshed_at' => now(), 'revision' => $draft->revision + 1]);
        } catch (Throwable $e) {
            report($e);
            $draft->update(['pending' => true, 'status' => 'failed', 'last_error' => $e->getMessage(), 'revision' => $draft->revision + 1]);
        } finally {
            $lock->release();
        }
    }

    private function trackJob(ShopYourVibeDraft $draft, string $gid, string $job): void
    {
        $jobs = $draft->remote_jobs ?? [];
        $jobs[$gid] = $job;
        $draft->update(['remote_jobs' => $jobs]);
        $this->waitForJob($job);
    }

    private function rememberCreatedCollection(ShopYourVibeDraft $draft, array $creation): void
    {
        $collection = $draft->desired['collections'][$creation['gid']];
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::firstOrCreate(['shopify_id' => $creation['gid']], [
            'import_id' => $creation['import_id'],
            'handle' => $collection['handle'], 'title' => $creation['title'],
            'sync_status' => ShopifyCollection::SYNC_STATUS_SYNCED, 'last_synced_at' => now(),
        ]));
    }

    protected function waitForJob(string $job): void
    {
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
        $jobs = $draft->remote_jobs ?? [];
        unset($jobs[$gid]);
        $draft->update(['snapshot' => $baseline, 'remote_jobs' => $jobs]);
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

    private function collectionDifferent(array $before, array $after): bool
    {
        $sort = $after['enable_manual'] ? 'MANUAL' : $after['sort'];

        return $before['sort'] !== $sort || ($sort === 'MANUAL'
            ? array_column($before['products'], 'id') !== array_column($after['products'], 'id')
            : $this->membership($before) !== $this->membership($after));
    }

    private function membership(array $collection): array
    {
        $ids = array_column($collection['products'], 'id');
        sort($ids);

        return $ids;
    }

    private function fields(?array $card): array
    {
        return array_map(fn ($key) => (string) ($card[$key] ?? ''), ['name', 'image', 'link']);
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

    private function editable(int $id, int $revision, bool $lock = false): ShopYourVibeDraft
    {
        $draft = ShopYourVibeDraft::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        if ($draft->status === 'pushing') {
            throw new RuntimeException('Changes are being pushed to Shopify. Wait for completion before editing.');
        }
        if ($draft->revision !== $revision) {
            throw new RuntimeException('This draft changed in another tab or session. Reopen it before editing.');
        }

        return $draft;
    }

    private function card(array $state, string $key): array
    {
        return collect($state['cards'])->firstWhere('key', $key) ?? throw new RuntimeException('This vibe is no longer in the draft.');
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
