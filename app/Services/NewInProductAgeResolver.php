<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Authoritative Shopify product age for New In expiration / reintroduction guards.
 *
 * Never falls back to CMS created_at. Distinguishes genuinely new (no Shopify GID)
 * products from existing Shopify products with incomplete local mappings.
 *
 * Callers must pass the resolved Shopify mutation target GID — never a stale
 * unrelated saved ID when the mutation targets a different product.
 */
final class NewInProductAgeResolver
{
    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly NewInTagClassifier $classifier,
        private readonly NewInExpirationLock $locks,
    ) {}

    public function enforcementEnabled(): bool
    {
        return (bool) config('new_in_expiration.enforcement_enabled', false);
    }

    public function cutoff(?CarbonImmutable $asOf = null): CarbonImmutable
    {
        $timezone = (string) config('new_in_expiration.timezone', 'Africa/Johannesburg');
        $days = max(1, (int) config('new_in_expiration.days', 75));
        $asOf ??= CarbonImmutable::now($timezone);

        return $asOf->utc()->subDays($days);
    }

    /**
     * @return array{
     *   product_gid: ?string,
     *   created_at: ?CarbonImmutable,
     *   created_at_raw: ?string,
     *   source: string,
     *   trusted: bool,
     *   past_window: bool,
     *   is_existing_shopify_product: bool
     * }
     */
    public function resolve(
        ?Product $product = null,
        ?string $productGid = null,
        bool $fetchRemoteWhenMissing = true,
        bool $persist = true,
        ?CarbonImmutable $asOf = null,
    ): array {
        $gid = $this->resolveGid($product, $productGid);
        $isExisting = $gid !== null && $gid !== '';

        if (! $isExisting) {
            return [
                'product_gid' => null,
                'created_at' => null,
                'created_at_raw' => null,
                'source' => 'new_local_product',
                'trusted' => true,
                'past_window' => false,
                'is_existing_shopify_product' => false,
            ];
        }

        $freshLocal = null;
        if ($product !== null) {
            $freshLocal = Product::query()->whereKey($product->getKey())->first() ?? $product;
        }
        if ($freshLocal === null) {
            $freshLocal = Product::query()->where('shopify_id', $gid)->latest('id')->first();
        }

        // Prefer live Shopify createdAt whenever New In enforcement needs a decision;
        // local cache is opportunistic and legacy values may have uncertain TZ provenance.
        if ($fetchRemoteWhenMissing) {
            $remote = $this->fetchCreatedAt($gid);
            if ($remote !== null) {
                if ($persist) {
                    $this->persistCreatedAt($gid, $remote['created_at']);
                }

                return [
                    'product_gid' => $gid,
                    'created_at' => $remote['created_at'],
                    'created_at_raw' => $remote['raw'],
                    'source' => 'shopify_graphql',
                    'trusted' => true,
                    'past_window' => $remote['created_at']->lessThanOrEqualTo($this->cutoff($asOf)),
                    'is_existing_shopify_product' => true,
                ];
            }
        }

        $localCreated = $freshLocal?->shopify_created_at;
        if ($localCreated instanceof \DateTimeInterface) {
            $created = CarbonImmutable::instance($localCreated)->utc();

            return [
                'product_gid' => $gid,
                'created_at' => $created,
                'created_at_raw' => $created->format('Y-m-d\TH:i:s\Z'),
                'source' => 'local_shopify_created_at_unverified',
                'trusted' => false,
                'past_window' => $created->lessThanOrEqualTo($this->cutoff($asOf)),
                'is_existing_shopify_product' => true,
            ];
        }

        return [
            'product_gid' => $gid,
            'created_at' => null,
            'created_at_raw' => null,
            'source' => $fetchRemoteWhenMissing ? 'remote_lookup_failed' : 'missing_untrusted',
            'trusted' => false,
            'past_window' => false,
            'is_existing_shopify_product' => true,
        ];
    }

    /**
     * Filter tags for a resolved mutation target without acquiring locks.
     * Caller must hold the product lock across filter + Shopify mutation.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function filterTagsForResolvedGid(
        array $tags,
        ?string $productGid,
        ?Product $product = null,
        bool $allowWhenTrulyNew = true,
    ): array {
        $tags = array_values($tags);

        if (! $this->enforcementEnabled()) {
            return $tags;
        }

        $gid = $this->resolveGid($product, $productGid);
        $hasNewIn = $this->classifier->hasAutoRemove($tags);

        if ($gid === null || $gid === '') {
            if ($allowWhenTrulyNew) {
                return $tags;
            }
            throw new RuntimeException('Cannot send New In tags without a resolved Shopify product GID.');
        }

        if (! $hasNewIn) {
            return $tags;
        }

        $remote = $this->fetchCreatedAt($gid);
        if ($remote === null) {
            // Existing Shopify product with unknown age: never allow New In through.
            return $this->classifier->stripAutoRemove($tags);
        }
        $this->persistCreatedAt($gid, $remote['created_at']);
        if ($remote['created_at']->lessThanOrEqualTo($this->cutoff())) {
            return $this->classifier->stripAutoRemove($tags);
        }

        return $tags;
    }

    /**
     * Hold the product lock for age check + mutation. Releases after the callback.
     *
     * @template T
     * @param  array<int, string>  $tags
     * @param  callable(array<int, string>, ?callable): T  $callback
     * @return T
     */
    public function withProductTagMutationGuard(
        string $productGid,
        array $tags,
        callable $callback,
        ?Product $product = null,
    ): mixed {
        if (! $this->enforcementEnabled()) {
            return $callback(array_values($tags), null);
        }

        $lock = $this->locks->blockProduct($productGid);

        try {
            $this->locks->assertOwned($lock, 'New In outbound tag guard');
            $filtered = $this->filterTagsForResolvedGid($tags, $productGid, $product, allowWhenTrulyNew: false);

            $beforeRequest = fn () => $this->locks->assertOwned(
                $lock, 'New In outbound tag guard', NewInExpirationLock::SHOPIFY_WRITE_RESERVE
            );

            return $callback($filtered, $beforeRequest);
        } finally {
            $this->locks->release($lock);
        }
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function guardOutboundTags(
        array $tags,
        ?Product $product = null,
        ?string $productGid = null,
        bool $allowWhenTrulyNew = true,
    ): array {
        return $this->filterTagsForResolvedGid($tags, $productGid, $product, $allowWhenTrulyNew);
    }

    /**
     * Local CMS assignment: refuse New In for existing Shopify GIDs that are past
     * the window or whose age cannot be established. Works with draft-only GIDs.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public function filterLocalAssignmentTags(
        array $tags,
        ?Product $product = null,
        ?string $productGid = null,
    ): array {
        return $this->filterTagsForResolvedGid($tags, $productGid, $product);
    }

    private function persistCreatedAt(string $productGid, CarbonImmutable $createdAt): void
    {
        $currentImport = Import::query()->where('is_current', true)->value('id');
        Product::query()
            ->where('shopify_id', $productGid)
            ->where('import_id', $currentImport)
            ->when($currentImport !== null, fn ($query) => $query->whereIn(
                'import_id', Import::query()->where('is_current', true)->select('id')
            ))
            ->toBase()
            ->update(['shopify_created_at' => $createdAt->format('Y-m-d H:i:s')]);
    }

    /**
     * @return array{created_at: CarbonImmutable, raw: string}|null
     */
    public function fetchCreatedAt(string $productGid): ?array
    {
        try {
            $data = $this->client->graphql(<<<'GQL'
query NewInProductCreatedAt($id: ID!) {
  product(id: $id) {
    id
    createdAt
  }
}
GQL, ['id' => $productGid]);
        } catch (Throwable $exception) {
            Log::warning('New In age resolver: Shopify createdAt lookup failed', [
                'product_gid' => $productGid,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $raw = data_get($data, 'product.createdAt');
        if (data_get($data, 'product.id') !== $productGid || ! is_string($raw)) {
            return null;
        }

        $createdAt = $this->parseShopifyCreatedAt($raw);

        return $createdAt === null ? null : ['created_at' => $createdAt, 'raw' => $raw];
    }

    public function parseShopifyCreatedAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }

        $parsed = date_parse($value);
        if ($parsed['warning_count'] || $parsed['error_count']) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function resolveGid(?Product $product, ?string $productGid): ?string
    {
        $gid = trim((string) ($productGid ?? ''));
        if ($gid !== '') {
            return $gid;
        }

        if ($product === null) {
            return null;
        }

        $gid = trim((string) ($product->shopify_id ?? ''));

        return $gid !== '' ? $gid : null;
    }

}
