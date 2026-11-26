<?php

namespace App\Services;

use App\Jobs\MirrorExpiredNewInTagsLocallyJob;
use App\Models\Import;
use App\Models\NewInExpirationOperation;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\ShopYourVibeCollectionMapping;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class NewInTagExpirationService
{
    public const SUPPORTED_STATUSES = ['ACTIVE', 'DRAFT', 'ARCHIVED', 'UNLISTED'];

    public const REMOVE_TAGS_MUTATION = <<<'GQL'
mutation RemoveNewInTags($id: ID!, $tags: [String!]!) {
  tagsRemove(id: $id, tags: $tags) {
    node {
      ... on Product {
        id
        tags
        createdAt
      }
    }
    userErrors { field message }
  }
}
GQL;

    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly NewInTagClassifier $classifier,
        private readonly NewInProductAgeResolver $ageResolver,
        private readonly NewInExpirationLock $locks,
    ) {}

    public function cutoff(?CarbonImmutable $asOf = null): CarbonImmutable
    {
        return $this->ageResolver->cutoff($asOf);
    }

    public function productIsPastNewInWindow(Product $product, ?CarbonImmutable $asOf = null): bool
    {
        $age = $this->ageResolver->resolve(
            $product,
            $product->shopify_id,
            fetchRemoteWhenMissing: true,
            persist: true,
            asOf: $asOf,
        );

        return $age['trusted'] && $age['past_window'];
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function filterOutboundTags(
        Product $product,
        array $tags,
        ?CarbonImmutable $asOf = null,
        ?string $resolvedProductGid = null,
    ): array {
        $gid = $resolvedProductGid ?? (string) ($product->shopify_id ?? '');

        return $this->ageResolver->filterTagsForResolvedGid($tags, $gid !== '' ? $gid : null, $product);
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function guardOutboundTagsForGid(string $productGid, array $tags, ?Product $product = null): array
    {
        return $this->ageResolver->filterTagsForResolvedGid($tags, $productGid, $product, allowWhenTrulyNew: false);
    }

    /**
     * Hold the product lock across age filtering and the caller's Shopify mutation(s).
     *
     * @template T
     * @param  array<int, string>  $tags
     * @param  callable(array<int, string>, ?callable): T  $mutate
     * @return T
     */
    public function withGuardedTagMutation(string $productGid, array $tags, callable $mutate, ?Product $product = null): mixed
    {
        return $this->ageResolver->withProductTagMutationGuard(
            $productGid,
            $tags,
            $mutate,
            $product,
        );
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function filterLocalAssignmentTags(array $tags, ?Product $product = null, ?string $productGid = null): array
    {
        return $this->ageResolver->filterLocalAssignmentTags($tags, $product, $productGid);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        bool $dryRun = true,
        ?int $limit = null,
        ?string $productGid = null,
        bool $mutate = false,
        ?Lock $executionLock = null,
    ): array {
        $cutoff = $this->cutoff();
        $cutoffIso = $cutoff->format('Y-m-d\\TH:i:s\\Z');
        $discovery = $this->discoverShopTags();
        $autoTags = $discovery['auto_remove'];
        $ambiguousTags = $discovery['ambiguous'];

        $candidates = [];
        if ($productGid !== null && trim($productGid) !== '') {
            $detail = $this->readProduct(trim($productGid));
            if ($detail !== null) {
                $candidates[$detail['id']] = $detail;
            }
        } else {
            foreach ($autoTags as $tag) {
                foreach ($this->searchEligibleProducts($tag, $cutoffIso) as $product) {
                    $candidates[$product['id']] = $product;
                }
            }
        }

        if ($executionLock !== null) {
            $this->locks->assertOwned($executionLock, 'New In expiration discovery');
        }

        $summary = [
            'dry_run' => $dryRun || ! $mutate,
            'cutoff' => $cutoffIso,
            'discovered_auto_remove_tags' => $autoTags,
            'discovered_ambiguous_tags' => $ambiguousTags,
            'assessed' => 0,
            'eligible' => 0,
            'skipped' => 0,
            'removed' => 0,
            'failed' => 0,
            'local_sync_failed' => 0,
            'products' => [],
            'ambiguous_report' => array_map(
                fn (string $tag): array => [
                    'tag' => $tag,
                    'note' => 'Ambiguous New-related tag; report-only until explicitly approved for removal.',
                ],
                $ambiguousTags
            ),
        ];

        $processed = 0;
        foreach ($candidates as $product) {
            if ($executionLock !== null) {
                $this->locks->assertOwned($executionLock, 'New In expiration mutation loop');
            }
            $summary['assessed']++;
            $row = $this->evaluateCandidate($product, $cutoff);
            if ($limit !== null && $row['eligible'] && $processed >= $limit) {
                $row['action'] = 'skipped_limit';
                $row['eligible'] = false;
                $summary['skipped']++;
                $summary['products'][] = $row;
                continue;
            }

            if (! $row['eligible']) {
                $summary['skipped']++;
                $summary['products'][] = $row;
                continue;
            }

            $summary['eligible']++;
            $processed++;

            if ($summary['dry_run']) {
                $row['action'] = 'would_remove';
                $summary['products'][] = $row;
                continue;
            }

            try {
                $result = $this->executeRemoval($row, $executionLock);
                $row = array_merge($row, $result['row']);
                if (($result['row']['action'] ?? '') === 'removed') {
                    $summary['removed']++;
                }
                if (in_array(($result['row']['local_sync'] ?? ''), ['failed', 'syv_pending'], true)) {
                    $summary['local_sync_failed']++;
                }
                if (($result['row']['action'] ?? '') === 'failed') {
                    $summary['failed']++;
                }
            } catch (Throwable $exception) {
                $row['action'] = 'failed';
                $row['error'] = $exception->getMessage();
                $summary['failed']++;
                Log::error('New In expiration: removal failed.', [
                    'shopify_product_id' => $row['shopify_product_id'],
                    'tags' => $row['tags_to_remove'],
                    'error' => $exception->getMessage(),
                ]);
            }

            $summary['products'][] = $row;
        }

        return $summary;
    }

    /**
     * Recover unfinished durable operations and any legacy JSON pending entries.
     *
     * @return array{attempted:int,synced:int,failed:int,needs_review:int}
     */
    public function recoverPendingLocalSyncs(?Lock $executionLock = null): array
    {
        $this->migrateLegacyJsonPendingIfPresent();

        $attempted = 0;
        $synced = 0;
        $failed = 0;
        $needsReview = 0;

        $operations = NewInExpirationOperation::query()
            ->whereIn('status', [
                NewInExpirationOperation::STATUS_PENDING,
                NewInExpirationOperation::STATUS_MUTATING,
                NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                NewInExpirationOperation::STATUS_NEEDS_REVIEW,
            ])
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        foreach ($operations as $operation) {
            if ($executionLock !== null) {
                $this->locks->assertOwned($executionLock, 'New In recovery');
            }
            $attempted++;
            try {
                $status = $this->recoverOperation($operation, $executionLock);
                if ($status === NewInExpirationOperation::STATUS_CONFIRMED) {
                    $synced++;
                } elseif ($status === NewInExpirationOperation::STATUS_NEEDS_REVIEW) {
                    $needsReview++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                if ($executionLock !== null) {
                    $this->locks->assertOwned($executionLock, 'New In recovery');
                }
                $operation->refresh();
                NewInExpirationOperation::query()->whereKey($operation->id)
                    ->where('status', '!=', NewInExpirationOperation::STATUS_CONFIRMED)
                    ->update([
                    'last_error' => $exception->getMessage(),
                    'available_at' => now()->addMinutes(5),
                ]);
            }
        }

        return compact('attempted', 'synced', 'failed', 'needsReview');
    }

    /**
     * @return array{auto_remove: array<int, string>, ambiguous: array<int, string>, unrelated_count: int}
     */
    public function discoverShopTags(): array
    {
        $auto = [];
        $ambiguous = [];
        $unrelated = 0;
        $after = null;
        $seenCursors = [];
        $pageSize = max(1, min(250, (int) config('new_in_expiration.page_size', 50) * 5));

        do {
            $data = $this->client->graphql(<<<'GQL'
query DiscoverProductTags($first: Int!, $after: String) {
  productTags(first: $first, after: $after) {
    nodes
    pageInfo { hasNextPage endCursor }
  }
}
GQL, [
                'first' => $pageSize,
                'after' => $after,
            ]);

            if (! is_array($data['productTags'] ?? null)) {
                throw new RuntimeException('Shopify productTags connection was missing or malformed.');
            }

            $nodes = $this->requireConnectionNodes($data['productTags'], 'productTags');
            foreach ($nodes as $tag) {
                if (! is_string($tag)) {
                    throw new RuntimeException('Shopify productTags node was not a string tag.');
                }
                $class = $this->classifier->classify((string) $tag);
                if ($class === NewInTagClassifier::CLASS_AUTO_REMOVE) {
                    $auto[] = (string) $tag;
                } elseif ($class === NewInTagClassifier::CLASS_AMBIGUOUS) {
                    $ambiguous[] = (string) $tag;
                } else {
                    $unrelated++;
                }
            }

            $after = $this->nextCursor($data['productTags']['pageInfo'] ?? null, $seenCursors, 'productTags');
        } while ($after !== null);

        return [
            'auto_remove' => array_values(array_unique($auto)),
            'ambiguous' => array_values(array_unique($ambiguous)),
            'unrelated_count' => $unrelated,
        ];
    }

    /**
     * @return \Generator<int, array{id:string,title:?string,handle:?string,status:?string,createdAt:?string,tags:array<int,string>,skus:array<int,string>}>
     */
    public function searchEligibleProducts(string $tag, string $cutoffIso): \Generator
    {
        $after = null;
        $seenCursors = [];
        $pageSize = max(1, min(100, (int) config('new_in_expiration.page_size', 50)));
        $statusClause = implode(' OR ', array_map(
            fn (string $status): string => 'status:'.strtolower($status),
            self::SUPPORTED_STATUSES
        ));
        $query = sprintf(
            'tag:%s AND created_at:<=\'%s\' AND (%s)',
            $this->quoteSearchValue($tag),
            $cutoffIso,
            $statusClause
        );

        do {
            $data = $this->client->graphql(<<<'GQL'
query ExpireNewInCandidates($query: String!, $first: Int!, $after: String) {
  products(first: $first, after: $after, query: $query) {
    nodes {
      id
      title
      handle
      status
      createdAt
      tags
      variants(first: 25) {
        nodes { sku }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GQL, [
                'query' => $query,
                'first' => $pageSize,
                'after' => $after,
            ], rejectSearchWarnings: true);

            if (! is_array($data['products'] ?? null)) {
                throw new RuntimeException('Shopify products connection was missing or malformed.');
            }

            $nodes = $this->requireConnectionNodes($data['products'], 'products');
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    throw new RuntimeException('Shopify products node was not an object.');
                }
                if (! is_string($node['id'] ?? null) || ! str_starts_with($node['id'], 'gid://shopify/Product/')) {
                    throw new RuntimeException('Shopify products node omitted id.');
                }
                if ($this->ageResolver->parseShopifyCreatedAt(is_string($node['createdAt'] ?? null) ? $node['createdAt'] : null) === null) {
                    throw new RuntimeException('Shopify products node omitted a valid creation date.');
                }
                yield $this->normalizeProductNode($node);
            }

            $after = $this->nextCursor($data['products']['pageInfo'] ?? null, $seenCursors, 'products');
        } while ($after !== null);
    }

    /**
     * @return array{id:string,title:?string,handle:?string,status:?string,createdAt:?string,tags:array<int,string>,skus:array<int,string>}|null
     */
    public function readProduct(string $productGid): ?array
    {
        $data = $this->client->graphql(<<<'GQL'
query ReadProductForNewInExpiration($id: ID!) {
  product(id: $id) {
    id
    title
    handle
    status
    createdAt
    tags
    variants(first: 25) {
      nodes { sku }
    }
  }
}
GQL, ['id' => $productGid]);

        if (array_key_exists('product', $data) && $data['product'] === null) {
            return null;
        }
        $node = $data['product'] ?? null;
        if (! is_array($node) || ! is_string($node['id'] ?? null)) {
            throw new RuntimeException('Shopify product read omitted the product identity.');
        }
        if ($node['id'] !== $productGid) {
            throw new RuntimeException('Shopify returned a different product GID than requested.');
        }
        if (! $this->isTagList($node['tags'] ?? null)) {
            throw new RuntimeException('Shopify product read omitted the tags array.');
        }

        return $this->normalizeProductNode($node);
    }

    /**
     * @param  array{id:string,title:?string,handle:?string,status:?string,createdAt:?string,tags:array<int,string>,skus:array<int,string>}  $product
     * @return array<string, mixed>
     */
    public function evaluateCandidate(array $product, CarbonImmutable $cutoff): array
    {
        $createdAtRaw = trim((string) ($product['createdAt'] ?? ''));
        $tags = array_values((array) ($product['tags'] ?? []));
        $classified = $this->classifier->classifyMany($tags);
        $removable = $classified[NewInTagClassifier::CLASS_AUTO_REMOVE];
        $ambiguous = $classified[NewInTagClassifier::CLASS_AMBIGUOUS];

        $row = [
            'shopify_product_id' => $product['id'],
            'title' => $product['title'] ?? null,
            'handle' => $product['handle'] ?? null,
            'status' => $product['status'] ?? null,
            'sku' => $product['skus'][0] ?? null,
            'skus' => $product['skus'] ?? [],
            'created_at' => $createdAtRaw !== '' ? $createdAtRaw : null,
            'age_days' => null,
            'current_new_in_tags' => $removable,
            'ambiguous_tags' => $ambiguous,
            'tags_to_remove' => $removable,
            'eligible' => false,
            'action' => 'skipped',
            'reason' => null,
        ];

        if ($createdAtRaw === '') {
            $row['reason'] = 'missing_created_at';

            return $row;
        }

        $createdAt = $this->ageResolver->parseShopifyCreatedAt($createdAtRaw);
        if ($createdAt === null) {
            $row['reason'] = 'invalid_created_at';

            return $row;
        }

        $row['age_days'] = (int) $createdAt->diffInDays(CarbonImmutable::now('UTC'));

        if ($createdAt->greaterThan($cutoff)) {
            $row['reason'] = 'younger_than_cutoff';

            return $row;
        }

        if ($removable === []) {
            $row['reason'] = 'no_auto_remove_new_in_tags';

            return $row;
        }

        $row['eligible'] = true;
        $row['reason'] = null;
        $row['action'] = 'eligible';

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row: array<string, mixed>, operation: NewInExpirationOperation}
     */
    public function executeRemoval(array $row, ?Lock $executionLock = null): array
    {
        $productGid = (string) $row['shopify_product_id'];
        $tagsToRemove = array_values(array_unique(array_map('strval', (array) ($row['tags_to_remove'] ?? []))));
        if ($tagsToRemove === []) {
            throw new RuntimeException('No New In tags provided for removal.');
        }

        $productLock = $this->locks->acquireProduct($productGid);

        try {
            $fresh = $this->readProduct($productGid);
            if ($fresh === null) {
                throw new RuntimeException('Shopify product disappeared before tagsRemove.');
            }

            $reeval = $this->evaluateCandidate($fresh, $this->cutoff());
            if (! $reeval['eligible']) {
                return ['row' => array_merge($row, $reeval), 'operation' => $this->noopConfirmedOperation($productGid, $fresh)];
            }

            $exactRemovals = $reeval['tags_to_remove'];
            $this->assertOperationLocks($productLock, $executionLock);

            $operation = $this->claimOperation(
                $productGid,
                $exactRemovals,
                $fresh['tags'],
                $fresh['createdAt'] ?? $row['created_at'],
            );
            $intended = $this->classifier->autoRemoveFrom($operation->tags_to_remove);

            try {
                $confirmed = $this->removeTagsOnShopify($productGid, $exactRemovals, $operation, $productLock, $executionLock);
                $this->assertOperationLocks($productLock, $executionLock);
                $this->assertTagsAbsent($confirmed['tags'], $intended);
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                    'remote_confirmed_at' => now(),
                    'shopify_created_at' => $this->ageResolver->parseShopifyCreatedAt($fresh['createdAt']),
                    'shopify_created_at_raw' => $fresh['createdAt'],
                    'meta' => array_merge((array) $operation->meta, [
                        'tags_after' => $confirmed['tags'],
                    ]),
                    'last_error' => null,
                ])->save();

                $row['action'] = 'removed';
                $row['tags_after'] = $confirmed['tags'];
                $row['tags_to_remove'] = $exactRemovals;
                $row['operation_id'] = $operation->id;

                try {
                    $this->mirrorLocalStateForOperation($operation);
                    $row['local_sync'] = 'synced';
                } catch (Throwable $localException) {
                    $row['local_sync'] = 'failed';
                    $row['local_sync_error'] = $localException->getMessage();
                    $operation->forceFill([
                        'last_error' => $localException->getMessage(),
                        'available_at' => now(),
                    ])->save();
                    $this->dispatchMirrorJob($operation);
                    Log::error('New In expiration: Shopify removal succeeded but local CMS sync failed.', [
                        'operation_id' => $operation->id,
                        'shopify_product_id' => $productGid,
                        'error' => $localException->getMessage(),
                    ]);

                    return ['row' => $row, 'operation' => $operation->fresh()];
                }

                try {
                    $collections = $this->confirmedRemovedCollections($productGid, $intended);
                    $this->assertOperationLocks($productLock, $executionLock);
                    $this->reconcileShopYourVibeMembership(
                        $productGid,
                        $intended,
                        [],
                        $collections,
                    );
                    $this->assertOperationLocks($productLock, $executionLock);
                    $operation->forceFill([
                        'status' => NewInExpirationOperation::STATUS_CONFIRMED,
                        'confirmed_at' => now(),
                        'active_product_gid' => null,
                        'meta' => array_merge((array) $operation->meta, ['syv_reconcile_failed' => false]),
                    ])->save();
                } catch (Throwable $syvException) {
                    $this->assertOperationLocks($productLock, $executionLock);
                    $row['local_sync'] = 'syv_pending';
                    $row['local_sync_error'] = $syvException->getMessage();
                    $operation->forceFill([
                        'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                        'last_error' => 'Shop Your Vibe reconcile pending: '.$syvException->getMessage(),
                        'available_at' => now(),
                        'meta' => array_merge((array) $operation->meta, [
                            'syv_reconcile_failed' => true,
                            'syv_reconcile_error' => $syvException->getMessage(),
                        ]),
                    ])->save();
                    $this->dispatchMirrorJob($operation);
                    Log::error('New In expiration: local sync ok but Shop Your Vibe reconcile failed.', [
                        'operation_id' => $operation->id,
                        'shopify_product_id' => $productGid,
                        'error' => $syvException->getMessage(),
                    ]);
                }
            } catch (Throwable $exception) {
                $this->assertOperationLocks($productLock, $executionLock);
                $this->handleUncertainOrFailedMutation($operation, $productGid, $intended, $exception, $productLock, $executionLock);
                $row['action'] = 'failed';
                $row['error'] = $exception->getMessage();
                $row['operation_id'] = $operation->id;
            }

            return ['row' => $row, 'operation' => $operation->fresh()];
        } finally {
            $this->locks->release($productLock);
        }
    }

    /**
     * @param  array<int, string>  $tags
     * @return array{tags: array<int, string>, created_at: ?string}
     */
    public function removeTagsOnShopify(string $productGid, array $tags, ?NewInExpirationOperation $operation = null, ?Lock $productLock = null, ?Lock $executionLock = null): array
    {
        $tags = array_values(array_unique(array_filter(array_map('strval', $tags))));
        if ($tags === []) {
            throw new RuntimeException('No New In tags provided for removal.');
        }

        if ($operation !== null) {
            $operation->forceFill([
                'status' => NewInExpirationOperation::STATUS_MUTATING,
                'mutating_at' => now(),
                'attempts' => (int) $operation->attempts + 1,
            ])->save();
        }

        try {
            $data = $this->client->graphql(self::REMOVE_TAGS_MUTATION, [
                'id' => $productGid,
                'tags' => $tags,
            ], $productLock === null ? null : fn () => $this->assertOperationLocks(
                $productLock, $executionLock, NewInExpirationLock::SHOPIFY_WRITE_RESERVE
            ));
        } catch (Throwable $exception) {
            // Transport / uncertain: do not assume failure.
            throw new RuntimeException('Uncertain Shopify tagsRemove outcome: '.$exception->getMessage(), 0, $exception);
        }

        $errors = data_get($data, 'tagsRemove.userErrors');
        if (! is_array($errors)) {
            return $this->confirmRemovalViaReadback($productGid, $tags);
        }
        if ($errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('; ');
            throw new RuntimeException($message !== '' ? $message : 'Shopify rejected tagsRemove.');
        }

        $node = data_get($data, 'tagsRemove.node');
        if (is_array($node) && isset($node['id']) && $node['id'] !== $productGid) {
            throw new RuntimeException('Shopify tagsRemove returned a different product GID.');
        }
        if (! is_array($node) || ($node['id'] ?? null) !== $productGid || ! $this->isTagList($node['tags'] ?? null)) {
            return $this->confirmRemovalViaReadback($productGid, $tags);
        }

        $afterTags = $node['tags'];
        $this->assertTagsAbsent($afterTags, $tags);

        return [
            'tags' => $afterTags,
            'created_at' => isset($node['createdAt']) ? (string) $node['createdAt'] : null,
        ];
    }

    /**
     * Delta-based local sync: remove only intended New In tags from current local records.
     */
    public function mirrorLocalStateForOperation(NewInExpirationOperation $operation): void
    {
        $productGid = (string) $operation->product_gid;
        $removedTags = $this->classifier->autoRemoveFrom((array) $operation->tags_to_remove);
        $createdAtRaw = $operation->shopify_created_at_raw;

        DB::transaction(function () use ($productGid, $removedTags, $createdAtRaw, $operation): void {
            $currentImportId = Import::query()->where('is_current', true)->lockForUpdate()->value('id');

            $products = Product::query()->where('shopify_id', $productGid)
                ->where('import_id', $currentImportId)
                ->lockForUpdate()
                ->get();

            $drafts = NewProductDraft::query()
                ->where('shopify_id', $productGid)
                ->lockForUpdate()
                ->get();

            if ($products->isEmpty() && $drafts->isEmpty()) {
                throw new RuntimeException(
                    'No local Product or NewProductDraft mapping found for '.$productGid.'; local sync incomplete.'
                );
            }

            foreach ($products as $product) {
                $updates = ['tags' => $this->removeLocalTagDelta($product->tags, $removedTags)];
                if (filled($createdAtRaw)) {
                    $updates['shopify_created_at'] = CarbonImmutable::parse((string) $createdAtRaw)->utc();
                }
                $product->forceFill($updates);
                $changes = array_intersect_key($product->getDirty(), $updates);
                if ($changes !== []) {
                    Product::query()->whereKey($product->id)->update($changes);
                }

                if ($product->handle && $currentImportId !== null && (int) $product->import_id === (int) $currentImportId) {
                    ShopifyRow::query()
                        ->where('import_id', $product->import_id)
                        ->where('handle', $product->handle)
                        ->where('row_type', 'product_primary')
                        ->lockForUpdate()
                        ->get()
                        ->each(function (ShopifyRow $row) use ($removedTags): void {
                            $current = (string) $row->get(HeaderStore::TAGS, '');
                            $next = $this->removeLocalTagDelta($current, $removedTags) ?? '';
                            if ($next !== $current) {
                                $row->set(HeaderStore::TAGS, $next);
                                $row->save();
                            }
                        });
                }
            }

            foreach ($drafts as $draft) {
                // Never match a draft that has a conflicting Shopify GID (query already scoped).
                $desired = $this->removeLocalTagDelta($draft->tags, $removedTags);
                if ($desired !== $draft->tags) {
                    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['tags' => $desired])->save());
                }
            }

            $operation->forceFill([
                'meta' => array_merge((array) $operation->meta, [
                    'local_synced_at' => now()->toIso8601String(),
                    'local_product_ids' => $products->pluck('id')->all(),
                    'local_draft_ids' => $drafts->pluck('id')->all(),
                ]),
            ])->save();
        });
    }

    private function removeLocalTagDelta(?string $value, array $removedTags): ?string
    {
        $tokens = preg_split('/[,;]/', (string) $value);
        $remaining = $this->classifier->stripIntended($tokens, $removedTags);
        if ($remaining === $tokens) {
            return $value;
        }

        $remaining = array_filter(array_map('trim', $remaining), fn (string $tag): bool => $tag !== '');

        return $remaining === [] ? null : implode(', ', $remaining);
    }

    /** @deprecated Use mirrorLocalStateForOperation */
    public function mirrorLocalState(
        string $shopifyProductId,
        array $tagsAfter,
        ?string $createdAt,
        array $removedTags = [],
    ): void {
        $operation = NewInExpirationOperation::query()
            ->where('product_gid', $shopifyProductId)
            ->whereIn('status', [
                NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                NewInExpirationOperation::STATUS_MUTATING,
                NewInExpirationOperation::STATUS_NEEDS_REVIEW,
            ])
            ->latest('created_at')
            ->first();

        if ($operation === null) {
            $operation = $this->claimOperation(
                $shopifyProductId,
                $removedTags,
                $tagsAfter,
                $createdAt,
            );
            $operation->forceFill([
                'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                'remote_confirmed_at' => now(),
                'shopify_created_at_raw' => $createdAt,
            ])->save();
        }

        $this->mirrorLocalStateForOperation($operation);
    }

    public function recoverOperation(NewInExpirationOperation $operation, ?Lock $executionLock = null): string
    {
        $productLock = $this->locks->acquireProduct((string) $operation->product_gid);

        try {
            $operation->refresh();
            if (! $operation->isOpen()) {
                return $operation->status;
            }

            $fresh = $this->readProduct((string) $operation->product_gid);
            $this->assertOperationLocks($productLock, $executionLock);
            if ($fresh === null) {
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_NEEDS_REVIEW,
                    'last_error' => 'Shopify product missing during recovery.',
                    'active_product_gid' => null,
                ])->save();

                return NewInExpirationOperation::STATUS_NEEDS_REVIEW;
            }

            $createdAt = $this->ageResolver->parseShopifyCreatedAt($fresh['createdAt']);
            if ($createdAt === null || $createdAt->greaterThan($this->cutoff())) {
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_NEEDS_REVIEW,
                    'last_error' => 'Shopify creation date does not establish expiration eligibility.',
                    'available_at' => now()->addHour(),
                ])->save();

                return NewInExpirationOperation::STATUS_NEEDS_REVIEW;
            }

            $intended = $this->classifier->autoRemoveFrom((array) $operation->tags_to_remove);
            $stillPresent = [];
            foreach ($this->classifier->autoRemoveFrom($fresh['tags']) as $present) {
                foreach ($intended as $wanted) {
                    if (TagNormalizer::normalizeToken($present) === TagNormalizer::normalizeToken((string) $wanted)) {
                        $stillPresent[] = $present;
                        break;
                    }
                }
            }

            if ($stillPresent !== []) {
                // Re-remove whenever intended tags remain, including remote_confirmed
                // records whose prior confirmation was incomplete or lost.
                try {
                    $confirmed = $this->removeTagsOnShopify((string) $operation->product_gid, $stillPresent, $operation, $productLock, $executionLock);
                    $this->assertOperationLocks($productLock, $executionLock);
                    $this->assertTagsAbsent($confirmed['tags'], $stillPresent);
                    $operation->forceFill([
                        'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                        'remote_confirmed_at' => now(),
                        'shopify_created_at' => $createdAt,
                        'shopify_created_at_raw' => $fresh['createdAt'],
                        'meta' => array_merge((array) $operation->meta, ['tags_after' => $confirmed['tags']]),
                        'last_error' => null,
                    ])->save();
                } catch (Throwable $exception) {
                    $this->assertOperationLocks($productLock, $executionLock);
                    $this->handleUncertainOrFailedMutation($operation, (string) $operation->product_gid, $intended, $exception, $productLock, $executionLock);

                    return (string) $operation->fresh()->status;
                }
            } else {
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                    'remote_confirmed_at' => $operation->remote_confirmed_at ?? now(),
                    'shopify_created_at' => $createdAt,
                    'shopify_created_at_raw' => $fresh['createdAt'],
                    'meta' => array_merge((array) $operation->meta, ['tags_after' => $fresh['tags']]),
                ])->save();
            }

            $operation->refresh();
            // Verify remote state again before completing.
            $verify = $this->readProduct((string) $operation->product_gid);
            $this->assertOperationLocks($productLock, $executionLock);
            if ($verify === null) {
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_NEEDS_REVIEW,
                    'last_error' => 'Shopify product missing after recovery removal.',
                    'available_at' => now()->addMinutes(5),
                ])->save();

                return NewInExpirationOperation::STATUS_NEEDS_REVIEW;
            }
            $remaining = [];
            foreach ($this->classifier->autoRemoveFrom($verify['tags']) as $present) {
                foreach ($intended as $wanted) {
                    if (TagNormalizer::normalizeToken($present) === TagNormalizer::normalizeToken((string) $wanted)) {
                        $remaining[] = $present;
                    }
                }
            }
            if ($remaining !== []) {
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_NEEDS_REVIEW,
                    'last_error' => 'Intended tags still present after recovery: '.implode(', ', $remaining),
                    'available_at' => now()->addMinutes(5),
                ])->save();

                return NewInExpirationOperation::STATUS_NEEDS_REVIEW;
            }

            $this->mirrorLocalStateForOperation($operation);

            try {
                $collections = $this->confirmedRemovedCollections((string) $operation->product_gid, $intended);
                $this->assertOperationLocks($productLock, $executionLock);
                $this->reconcileShopYourVibeMembership((string) $operation->product_gid, $intended, [], $collections);
            } catch (Throwable $exception) {
                $this->assertOperationLocks($productLock, $executionLock);
                $operation->forceFill([
                    'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                    'last_error' => 'Shop Your Vibe reconcile pending: '.$exception->getMessage(),
                    'available_at' => now()->addMinutes(5),
                    'meta' => array_merge((array) $operation->meta, [
                        'syv_reconcile_failed' => true,
                        'syv_reconcile_error' => $exception->getMessage(),
                    ]),
                ])->save();

                return NewInExpirationOperation::STATUS_REMOTE_CONFIRMED;
            }

            $this->assertOperationLocks($productLock, $executionLock);
            $operation->forceFill([
                'status' => NewInExpirationOperation::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'active_product_gid' => null,
                'last_error' => null,
                'meta' => array_merge((array) $operation->meta, [
                    'syv_reconcile_failed' => false,
                ]),
            ])->save();

            return NewInExpirationOperation::STATUS_CONFIRMED;
        } finally {
            $this->locks->release($productLock);
        }
    }

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public function reconcileShopYourVibeMembership(string $productGid, array $before, array $after, ?array $collections = null): void
    {
        app(ShopYourVibeSorting::class)->reconcileMembershipTags($productGid, $before, $after, $collections);
    }

    private function confirmedRemovedCollections(string $productGid, array $tags): array
    {
        $removed = array_map(fn (string $tag): string => mb_strtolower(trim($tag)), $tags);
        $collections = ShopYourVibeCollectionMapping::query()
            ->where('is_active', true)->whereNotNull('membership_tag')
            ->get()
            ->filter(fn ($mapping): bool => in_array(mb_strtolower(trim($mapping->membership_tag)), $removed, true))
            ->pluck('shopify_collection_id')->unique();
        $confirmed = [];
        foreach ($collections as $collectionGid) {
            $data = $this->client->graphql(<<<'GQL'
query ConfirmNewInCollectionRemoval($id: ID!, $collection: ID!) {
  product(id: $id) { id inCollection(id: $collection) }
  collection(id: $collection) {
    id
    ruleSet { appliedDisjunctively rules { column relation condition } }
  }
}
GQL, ['id' => $productGid, 'collection' => $collectionGid]);
            $inCollection = data_get($data, 'product.inCollection');
            if (data_get($data, 'product.id') !== $productGid || ! is_bool($inCollection)) {
                throw new RuntimeException('Shopify returned incomplete New In membership data for '.$collectionGid.'.');
            }
            if (! $inCollection) {
                $confirmed[] = $collectionGid;

                continue;
            }
            if (data_get($data, 'collection.id') !== $collectionGid || ! array_key_exists('ruleSet', $data['collection'])) {
                throw new RuntimeException('Shopify returned incomplete collection rules for '.$collectionGid.'.');
            }
            $rules = $data['collection']['ruleSet'];
            if ($rules !== null && (! is_array($rules) || ! is_bool($rules['appliedDisjunctively'] ?? null)
                || ! is_array($rules['rules'] ?? null) || ! array_is_list($rules['rules']))) {
                throw new RuntimeException('Shopify returned malformed collection rules for '.$collectionGid.'.');
            }
            $tagRules = collect($rules['rules'] ?? [])->filter(fn (array $rule): bool =>
                ($rule['column'] ?? null) === 'TAG' && ($rule['relation'] ?? null) === 'EQUALS'
                && in_array(mb_strtolower(trim((string) ($rule['condition'] ?? ''))), $removed, true)
            );
            // Other rules may legitimately keep the product in the collection.
            $requiresRemovedTag = $tagRules->isNotEmpty()
                && (! ($rules['appliedDisjunctively'] ?? false) || $tagRules->count() === count($rules['rules']));
            if ($requiresRemovedTag) {
                throw new RuntimeException('Shopify has not confirmed New In collection removal from '.$collectionGid.'.');
            }
        }

        return $confirmed;
    }

    public function migrateLegacyJsonPendingIfPresent(): int
    {
        $path = 'new_in_expiration/pending_local_sync.json';
        if (! Storage::disk('local')->exists($path)) {
            return 0;
        }

        $raw = (string) Storage::disk('local')->get($path);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $corrupt = 'new_in_expiration/pending_local_sync.corrupt.'.now()->format('YmdHis').'.json';
            if (! Storage::disk('local')->move($path, $corrupt)) {
                throw new RuntimeException('Failed to archive corrupt New In legacy recovery file.');
            }

            return 0;
        }

        $migrated = 0;
        $invalid = 0;
        $mergedByGid = [];

        foreach ($decoded as $index => $item) {
            if (! is_array($item)) {
                $invalid++;
                Log::warning('New In legacy JSON entry invalid', ['index' => $index]);

                continue;
            }
            $gid = trim((string) ($item['shopify_product_id'] ?? ''));
            $removed = $this->isTagList($item['removed_tags'] ?? null)
                ? $this->classifier->autoRemoveFrom($item['removed_tags'])
                : [];
            if ($gid === '' || $removed === []) {
                $invalid++;
                Log::warning('New In legacy JSON entry missing gid or tags', ['index' => $index]);

                continue;
            }

            $mergedByGid[$gid] ??= [
                'removed' => [],
                'tags_after' => [],
                'created_at' => null,
            ];
            $mergedByGid[$gid]['removed'] = array_values(array_unique(array_merge(
                $mergedByGid[$gid]['removed'],
                $removed
            )));
            if ($this->isTagList($item['tags_after'] ?? null)) {
                $mergedByGid[$gid]['tags_after'] = $item['tags_after'];
            }
            if (is_string($item['created_at'] ?? null)
                && $this->ageResolver->parseShopifyCreatedAt($item['created_at']) !== null) {
                $mergedByGid[$gid]['created_at'] = (string) $item['created_at'];
            }
        }

        $sourceHash = hash('sha256', $raw);
        foreach ($mergedByGid as $gid => $payload) {
            if (NewInExpirationOperation::query()->where('product_gid', $gid)
                ->whereJsonContains('meta->legacy_source_hashes', $sourceHash)->exists()) {
                continue;
            }
            $op = $this->claimOperation(
                $gid,
                $payload['removed'],
                array_values((array) $payload['tags_after']),
                $payload['created_at'],
            );
            $op->forceFill([
                'available_at' => now(),
                'meta' => array_merge((array) $op->meta, [
                    'migrated_from_json' => true,
                    'tags_after' => array_values((array) $payload['tags_after']),
                    'legacy_removed_tags' => $payload['removed'],
                    'legacy_source_hashes' => [...($op->meta['legacy_source_hashes'] ?? []), $sourceHash],
                ]),
            ])->save();
            $migrated++;
        }

        $archive = 'new_in_expiration/pending_local_sync.migrated.'.$sourceHash.'.json';
        if (! Storage::disk('local')->move($path, $archive)) {
            throw new RuntimeException('Failed to archive New In legacy recovery file after durable import.');
        }

        if ($invalid > 0) {
            Log::warning('New In legacy JSON import skipped invalid entries', ['invalid' => $invalid]);
        }

        return $migrated;
    }

    /**
     * @param  array<int, string>  $tagsToRemove
     * @param  array<int, string>  $beforeTags
     */
    public function claimOperation(
        string $productGid,
        array $tagsToRemove,
        array $beforeTags,
        ?string $createdAtRaw,
    ): NewInExpirationOperation {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new RuntimeException('New In expiration claims cannot be made inside an enclosing transaction.');
        }

        return DB::transaction(function () use ($productGid, $tagsToRemove, $beforeTags, $createdAtRaw) {
            $open = NewInExpirationOperation::query()
                ->where('product_gid', $productGid)
                ->whereIn('status', [
                    NewInExpirationOperation::STATUS_PENDING,
                    NewInExpirationOperation::STATUS_MUTATING,
                    NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                    NewInExpirationOperation::STATUS_NEEDS_REVIEW,
                ])
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                $open->forceFill([
                    'active_product_gid' => $productGid,
                    'tags_to_remove' => array_values(array_unique(array_merge(
                        array_values((array) $open->tags_to_remove),
                        $tagsToRemove
                    ))),
                    'before_tags' => $beforeTags !== [] ? $beforeTags : $open->before_tags,
                    'shopify_created_at_raw' => $createdAtRaw ?? $open->shopify_created_at_raw,
                    'available_at' => now(),
                ])->save();

                return $open->fresh();
            }

            return NewInExpirationOperation::query()->create([
                'product_gid' => $productGid,
                'active_product_gid' => $productGid,
                'status' => NewInExpirationOperation::STATUS_PENDING,
                'tags_to_remove' => array_values($tagsToRemove),
                'before_tags' => array_values($beforeTags),
                'shopify_created_at' => filled($createdAtRaw) ? CarbonImmutable::parse($createdAtRaw)->utc() : null,
                'shopify_created_at_raw' => $createdAtRaw,
                'attempts' => 0,
                'available_at' => now(),
                'meta' => [],
            ]);
        });
    }

    private function dispatchMirrorJob(NewInExpirationOperation $operation): void
    {
        try {
            dispatch(
                (new MirrorExpiredNewInTagsLocallyJob($operation->id))
                    ->onConnection((string) config('new_in_expiration.queue_connection', 'database'))
                    ->onQueue((string) config('new_in_expiration.queue', 'default'))
            );
        } catch (Throwable $exception) {
            // Intent remains remote_confirmed / recoverable even if dispatch fails.
            $operation->forceFill([
                'last_error' => 'Queue dispatch failed: '.$exception->getMessage(),
                'available_at' => now(),
            ])->save();
            Log::error('New In expiration: failed to dispatch mirror job.', [
                'operation_id' => $operation->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function assertOperationLocks(Lock $productLock, ?Lock $executionLock, int $reserveSeconds = 0): void
    {
        if ($executionLock !== null) {
            $this->locks->assertOwned($executionLock, 'New In execution', $reserveSeconds);
        }
        $this->locks->assertOwned($productLock, 'New In product operation', $reserveSeconds);
    }

    private function handleUncertainOrFailedMutation(
        NewInExpirationOperation $operation,
        string $productGid,
        array $tags,
        Throwable $exception,
        Lock $productLock,
        ?Lock $executionLock,
    ): void {
        $message = $exception->getMessage();
        $uncertain = str_contains(strtolower($message), 'uncertain');

        try {
            $fresh = $this->readProduct($productGid);
            $this->assertOperationLocks($productLock, $executionLock);
            if ($fresh !== null) {
                $still = [];
                foreach ($this->classifier->autoRemoveFrom($fresh['tags']) as $present) {
                    foreach ($tags as $wanted) {
                        if (TagNormalizer::normalizeToken($present) === TagNormalizer::normalizeToken($wanted)) {
                            $still[] = $present;
                        }
                    }
                }
                if ($still === []) {
                    $operation->forceFill([
                        'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
                        'remote_confirmed_at' => now(),
                        'meta' => array_merge((array) $operation->meta, ['tags_after' => $fresh['tags']]),
                        'last_error' => 'Resolved via readback after: '.$message,
                        'available_at' => now(),
                    ])->save();
                    $this->dispatchMirrorJob($operation);

                    return;
                }
            }
        } catch (Throwable) {
            // Fall through to needs_review / mutating retention.
        }

        $this->assertOperationLocks($productLock, $executionLock);
        $operation->forceFill([
            'status' => $uncertain
                ? NewInExpirationOperation::STATUS_MUTATING
                : NewInExpirationOperation::STATUS_NEEDS_REVIEW,
            'last_error' => $message,
            'available_at' => now()->addMinutes(5),
        ])->save();
    }

    /**
     * @param  array{id:string,title:?string,handle:?string,status:?string,createdAt:?string,tags:array<int,string>,skus:array<int,string>}  $fresh
     */
    private function noopConfirmedOperation(string $productGid, array $fresh): NewInExpirationOperation
    {
        return NewInExpirationOperation::query()->create([
            'product_gid' => $productGid,
            'active_product_gid' => null,
            'status' => NewInExpirationOperation::STATUS_CONFIRMED,
            'tags_to_remove' => [],
            'before_tags' => $fresh['tags'],
            'shopify_created_at_raw' => $fresh['createdAt'],
            'confirmed_at' => now(),
            'meta' => ['noop' => true],
        ]);
    }

    /**
     * @param  array<int, string>  $tags
     * @return array{tags: array<int, string>, created_at: ?string}
     */
    private function confirmRemovalViaReadback(string $productGid, array $tags): array
    {
        $verified = $this->readProduct($productGid);
        if ($verified === null) {
            throw new RuntimeException('Shopify did not confirm the product after tagsRemove.');
        }
        $this->assertTagsAbsent($verified['tags'], $tags);

        return [
            'tags' => $verified['tags'],
            'created_at' => $verified['createdAt'],
        ];
    }

    /**
     * @param  array<int, string>  $afterTags
     * @param  array<int, string>  $removed
     */
    private function assertTagsAbsent(array $afterTags, array $removed): void
    {
        $remaining = [];
        foreach ($removed as $tag) {
            $normalized = TagNormalizer::normalizeToken((string) $tag);
            foreach ($afterTags as $after) {
                if (TagNormalizer::normalizeToken((string) $after) === $normalized) {
                    $remaining[] = (string) $tag;
                    break;
                }
            }
        }
        if ($remaining !== []) {
            throw new RuntimeException('Shopify tagsRemove did not remove: '.implode(', ', array_unique($remaining)));
        }
    }

    /**
     * @param  array<string, true>  $seenCursors
     */
    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, mixed>
     */
    private function requireConnectionNodes(array $connection, string $label): array
    {
        if (! array_key_exists('nodes', $connection)) {
            throw new RuntimeException("Shopify {$label} connection omitted nodes.");
        }
        if ($connection['nodes'] === null) {
            throw new RuntimeException("Shopify {$label} connection nodes was null.");
        }
        if (! is_array($connection['nodes']) || ! array_is_list($connection['nodes'])) {
            throw new RuntimeException("Shopify {$label} connection nodes was malformed.");
        }

        return array_values($connection['nodes']);
    }

    private function nextCursor(mixed $pageInfo, array &$seenCursors, string $label): ?string
    {
        if (! is_array($pageInfo) || ! is_bool($pageInfo['hasNextPage'] ?? null)) {
            throw new RuntimeException("Shopify {$label} pageInfo.hasNextPage was missing.");
        }

        if (! $pageInfo['hasNextPage']) {
            return null;
        }

        if (! array_key_exists('endCursor', $pageInfo) || ! is_string($pageInfo['endCursor']) || $pageInfo['endCursor'] === '') {
            throw new RuntimeException("Shopify {$label} pageInfo.endCursor was missing while hasNextPage=true.");
        }

        $cursor = $pageInfo['endCursor'];
        if (isset($seenCursors[$cursor])) {
            throw new RuntimeException("Shopify {$label} pagination repeated cursor: {$cursor}");
        }
        $seenCursors[$cursor] = true;

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{id:string,title:?string,handle:?string,status:?string,createdAt:?string,tags:array<int,string>,skus:array<int,string>}
     */
    private function normalizeProductNode(array $node): array
    {
        $tags = $node['tags'] ?? null;
        if (! $this->isTagList($tags)) {
            throw new RuntimeException('Shopify product tags field was malformed.');
        }

        $skus = [];
        foreach ((array) data_get($node, 'variants.nodes', []) as $variant) {
            $sku = trim((string) ($variant['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        return [
            'id' => (string) $node['id'],
            'title' => isset($node['title']) ? (string) $node['title'] : null,
            'handle' => isset($node['handle']) ? (string) $node['handle'] : null,
            'status' => isset($node['status']) ? (string) $node['status'] : null,
            'createdAt' => isset($node['createdAt']) ? (string) $node['createdAt'] : null,
            'tags' => $tags,
            'skus' => $skus,
        ];
    }

    private function quoteSearchValue(string $value): string
    {
        $escaped = addcslashes($value, "\\':()");

        return "'{$escaped}'";
    }

    private function isTagList(mixed $tags): bool
    {
        return is_array($tags) && array_is_list($tags) && count(array_filter($tags, is_string(...))) === count($tags);
    }
}
