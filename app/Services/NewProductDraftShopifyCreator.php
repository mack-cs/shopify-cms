<?php

namespace App\Services;

use App\Models\NewProductDraft;
use Illuminate\Support\Collection;

final class NewProductDraftShopifyCreator
{
    private bool $draftIdentifierReady = false;

    private ?string $resolvedLocationId = null;

    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly ComplementaryProductAuditService $complementaryProducts,
    ) {}

    /**
     * @return array{
     *   created:int,
     *   skipped_not_approved:int,
     *   skipped_has_errors:int,
     *   skipped_missing_complementary:int,
     *   skipped_has_handle:int,
     *   failed:int,
     *   failures: array<int, array{
     *     id:int|null,
     *     title:string|null,
     *     reason:string,
     *     details:string|null
     *   }>
     *   ,warnings: array<int, array{
     *     id:int|null,
     *     title:string|null,
     *     reason:string,
     *     details:string|null
     *   }>
     * }
     */
    public function createApprovedDrafts(Collection $drafts): array
    {
        $created = 0;
        $skippedNotApproved = 0;
        $skippedHasErrors = 0;
        $skippedMissingComplementary = 0;
        $skippedHasHandle = 0;
        $failed = 0;
        $failures = [];
        $warnings = [];

        foreach ($drafts as $draft) {
            if (!$draft instanceof NewProductDraft) {
                continue;
            }

            if ($draft->handle) {
                $skippedHasHandle++;
                continue;
            }

            if (!$draft->isApprovedByTwo()) {
                $skippedNotApproved++;
                continue;
            }

            if (trim((string) ($draft->title ?? '')) === '') {
                $skippedHasErrors++;
                $failures[] = [
                    'id' => $draft->id,
                    'title' => $draft->title,
                    'reason' => 'missing_title',
                    'details' => 'A title is required before a draft can be sent to Shopify.',
                ];
                continue;
            }

            if (($draft->product?->has_errors ?? false) === true) {
                $skippedHasErrors++;
                continue;
            }

            if (!$this->complementaryProducts->hasRequiredMinimumForDraft($draft)) {
                $skippedMissingComplementary++;
                continue;
            }

            if ($this->availablePrice($draft) === null) {
                $skippedHasErrors++;
                $failures[] = [
                    'id' => $draft->id,
                    'title' => $draft->title,
                    'reason' => 'invalid_price',
                    'details' => 'A valid price greater than zero is required before a draft can be sent to Shopify.',
                ];
                continue;
            }

            try {
                $result = $this->createProduct($draft);
                if (!$result['handle'] || !$result['id'] || $result['error']) {
                    $failed++;
                    $failures[] = [
                        'id' => $draft->id,
                        'title' => $draft->title,
                        'reason' => $result['failure_reason'] ?? 'shopify_user_error',
                        'details' => $result['error'],
                    ];
                    continue;
                }

                if ($result['media_error']) {
                    $warnings[] = [
                        'id' => $draft->id,
                        'title' => $draft->title,
                        'reason' => 'media_failed',
                        'details' => $result['media_error'],
                    ];
                }

                if ($result['copy_error']) {
                    $warnings[] = [
                        'id' => $draft->id,
                        'title' => $draft->title,
                        'reason' => 'draft_fields_not_copied',
                        'details' => $result['copy_error'],
                    ];
                }

                $draft->update([
                    'handle' => $result['handle'],
                    'shopify_id' => $result['id'],
                ]);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'id' => $draft->id,
                    'title' => $draft->title,
                    'reason' => 'exception',
                    'details' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'skipped_not_approved' => $skippedNotApproved,
            'skipped_has_errors' => $skippedHasErrors,
            'skipped_missing_complementary' => $skippedMissingComplementary,
            'skipped_has_handle' => $skippedHasHandle,
            'failed' => $failed,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{id:?string, handle:?string, error:?string, media_error:?string, copy_error:?string, failure_reason:?string}
     */
    private function createProduct(NewProductDraft $draft): array
    {
        $this->ensureDraftIdentifierDefinition();

        $existing = $this->findProductForDraft($draft);
        if (is_array($existing)) {
            return $this->adoptExistingProduct($draft, $existing);
        }

        $duplicateSku = $this->conflictingSku($draft);
        if ($duplicateSku !== null) {
            return $this->manualReview(
                $draft,
                'duplicate_sku',
                "SKU {$duplicateSku} already belongs to another Shopify product. Creation was not sent."
            );
        }

        if (! $draft->isBundleOrStack() && $this->inventoryLocationId() === null) {
            return $this->failure('No Shopify location is configured, so the product was not created.');
        }

        try {
            $variables = ['input' => $this->productSetInput($draft)];
            if ($this->sendsInventory($draft)) {
                $variables['locationId'] = $this->inventoryLocationId();
            }
            $data = $this->client->graphql($this->productSetMutation($this->sendsInventory($draft)), $variables);
        } catch (\Throwable $e) {
            $recovered = $this->findProductForDraft($draft);
            if (is_array($recovered)) {
                return $this->adoptExistingProduct($draft, $recovered);
            }

            throw $e;
        }

        $payload = $data['productSet'] ?? null;
        $product = is_array($payload) ? ($payload['product'] ?? null) : null;
        $error = $this->userErrorMessage(
            is_array($payload) ? ($payload['userErrors'] ?? []) : [],
            'Missing productSet payload.'
        );
        if (! is_array($payload)) {
            $error = 'Missing productSet payload.';
        }

        if ($error !== null || ! is_array($product)) {
            $recovered = $this->findProductForDraft($draft);
            if (is_array($recovered)) {
                return $this->adoptExistingProduct($draft, $recovered);
            }

            return $this->failure($error ?? 'Shopify did not return the created product.');
        }

        $mismatch = $this->verificationFailure($draft, $product, checkQuantity: $this->sendsInventory($draft));
        if ($mismatch !== null) {
            return $this->manualReview($draft, 'manual_review', $mismatch);
        }

        $this->clearManualReview($draft);

        return $this->success($product);
    }

    private function availablePrice(NewProductDraft $draft): ?string
    {
        $raw = $draft->variant_price;
        if ($raw === null || ! is_numeric((string) $raw)) {
            return null;
        }

        $amount = (float) $raw;
        if ($amount <= 0) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }

    private function optionalMoney(mixed $raw): ?string
    {
        if ($raw === null || ! is_numeric((string) $raw)) {
            return null;
        }

        $amount = (float) $raw;
        if ($amount < 0) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }

    private function sendsInventory(NewProductDraft $draft): bool
    {
        return ! $draft->isBundleOrStack();
    }

    /**
     * @return array<string, mixed>
     */
    private function productSetInput(NewProductDraft $draft): array
    {
        $price = $this->availablePrice($draft);
        $input = [
            'title' => $draft->title,
            'status' => $this->mapStatus($draft->status ?? 'draft'),
            'productOptions' => [[
                'name' => 'Title',
                'values' => [['name' => 'Default Title']],
            ]],
            'variants' => [$this->variantSetInput($draft, (string) $price)],
            'metafields' => $this->metafields($draft),
        ];

        $description = trim((string) ($draft->body_html ?? ''));
        if ($description !== '') {
            $input['descriptionHtml'] = $description;
        }
        $vendor = trim((string) ($draft->vendor ?? ''));
        if ($vendor !== '') {
            $input['vendor'] = $vendor;
        }
        $type = trim((string) ($draft->type ?? ''));
        if ($type !== '') {
            $input['productType'] = $type;
        }
        $tags = TagNormalizer::parseTokens((string) ($draft->tags ?? ''));
        if ($tags !== []) {
            $input['tags'] = $tags;
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function variantSetInput(NewProductDraft $draft, string $price): array
    {
        $variant = [
            'optionValues' => [[
                'optionName' => 'Title',
                'name' => 'Default Title',
            ]],
            'price' => $price,
        ];
        $compareAt = $this->optionalMoney($draft->variant_compare_at_price);
        if ($compareAt !== null) {
            $variant['compareAtPrice'] = $compareAt;
        }

        $sku = trim((string) ($draft->sku ?? ''));
        $inventoryItem = [];
        if ($sku !== '') {
            $variant['sku'] = $sku;
            $variant['barcode'] = $sku;
            $inventoryItem['sku'] = $sku;
        }
        if ($this->sendsInventory($draft)) {
            $inventoryItem['tracked'] = true;
            $variant['inventoryQuantities'] = [[
                'locationId' => $this->inventoryLocationId(),
                'name' => 'on_hand',
                'quantity' => 0,
            ]];
        }
        if ($inventoryItem !== []) {
            $variant['inventoryItem'] = $inventoryItem;
        }

        return $variant;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function metafields(NewProductDraft $draft): array
    {
        $metafields = [[
            'namespace' => 'cms',
            'key' => 'draft_id',
            'type' => 'id',
            'value' => (string) $draft->id,
        ]];
        $uvp = $this->richTextMetafieldValue((string) ($draft->uvp_short_paragraph ?? ''));
        if ($uvp !== null) {
            $metafields[] = [
                'namespace' => 'custom',
                'key' => 'uvp_short_paragraph',
                'type' => 'rich_text_field',
                'value' => $uvp,
            ];
        }

        return $metafields;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findProductForDraft(NewProductDraft $draft): ?array
    {
        $identified = $this->client->graphql($this->productByIdentifierQuery(), [
            'identifier' => [
                'customId' => [
                    'namespace' => 'cms',
                    'key' => 'draft_id',
                    'value' => (string) $draft->id,
                ],
            ],
        ]);
        $product = $identified['productByIdentifier'] ?? null;
        if (is_array($product) && trim((string) ($product['id'] ?? '')) !== '') {
            return $product;
        }

        $sku = trim((string) ($draft->sku ?? ''));
        if ($sku === '') {
            return null;
        }

        $matches = $this->client->graphql($this->variantsBySkuQuery(), [
            'query' => $this->skuSearch($sku),
        ]);
        foreach ((array) data_get($matches, 'productVariants.nodes', []) as $node) {
            if (! is_array($node) || ! $this->sameSku((string) ($node['sku'] ?? ''), $sku)) {
                continue;
            }
            $candidate = $node['product'] ?? null;
            if (! is_array($candidate)) {
                continue;
            }
            if (trim((string) data_get($candidate, 'metafield.value', '')) === (string) $draft->id) {
                return $candidate;
            }
        }

        return null;
    }

    private function conflictingSku(NewProductDraft $draft): ?string
    {
        $sku = trim((string) ($draft->sku ?? ''));
        if ($sku === '') {
            return null;
        }

        $matches = $this->client->graphql($this->variantsBySkuQuery(), [
            'query' => $this->skuSearch($sku),
        ]);
        foreach ((array) data_get($matches, 'productVariants.nodes', []) as $node) {
            if (! is_array($node) || ! $this->sameSku((string) ($node['sku'] ?? ''), $sku)) {
                continue;
            }
            $marker = trim((string) data_get($node, 'product.metafield.value', ''));
            if ($marker === (string) $draft->id) {
                continue;
            }

            return $sku;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{id:?string, handle:?string, error:?string, media_error:?string, copy_error:?string, failure_reason:?string}
     */
    private function adoptExistingProduct(NewProductDraft $draft, array $product): array
    {
        $mismatch = $this->verificationFailure($draft, $product, checkQuantity: false);
        if ($mismatch !== null) {
            return $this->manualReview($draft, 'manual_review', $mismatch);
        }

        $this->clearManualReview($draft);

        return $this->success($product);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function verificationFailure(NewProductDraft $draft, array $product, bool $checkQuantity): ?string
    {
        $productId = trim((string) ($product['id'] ?? ''));
        $handle = trim((string) ($product['handle'] ?? ''));
        if ($productId === '' || $handle === '') {
            return 'Shopify did not return a product id and handle.';
        }

        $status = strtoupper(trim((string) ($product['status'] ?? '')));
        if ($status !== $this->mapStatus($draft->status ?? 'draft')) {
            return "Shopify status is {$status}; the draft status was not changed.";
        }

        $marker = trim((string) data_get($product, 'metafield.value', ''));
        if ($marker !== (string) $draft->id) {
            return 'The Shopify product is not marked with this CMS draft id.';
        }

        $variant = $this->matchingVariant($draft, $product);
        if ($variant === null) {
            return 'The Shopify product does not have the expected variant.';
        }

        $variantId = trim((string) ($variant['id'] ?? ''));
        $inventoryItemId = trim((string) data_get($variant, 'inventoryItem.id', ''));
        if ($variantId === '' || $inventoryItemId === '') {
            return 'Shopify did not return the variant and inventory item ids.';
        }

        $price = $this->availablePrice($draft);
        if ($price === null || $this->money($variant['price'] ?? null) !== $price) {
            return 'The Shopify variant price does not match the approved price.';
        }

        $compareAt = $this->optionalMoney($draft->variant_compare_at_price);
        if ($compareAt !== null && $this->money($variant['compareAtPrice'] ?? null) !== $compareAt) {
            return 'The Shopify compare-at price does not match the approved compare-at price.';
        }

        $sku = trim((string) ($draft->sku ?? ''));
        if ($sku !== '') {
            $variantSku = trim((string) ($variant['sku'] ?? ''));
            $inventorySku = trim((string) data_get($variant, 'inventoryItem.sku', ''));
            if (! $this->sameSku($variantSku, $sku) && ! $this->sameSku($inventorySku, $sku)) {
                return 'The Shopify SKU does not match the approved SKU.';
            }
            if (trim((string) ($variant['barcode'] ?? '')) !== $sku) {
                return 'The Shopify barcode does not match the approved SKU.';
            }
        }

        if ($this->sendsInventory($draft) && data_get($variant, 'inventoryItem.tracked') !== true) {
            return 'Inventory tracking is not enabled on the Shopify variant.';
        }

        if ($checkQuantity) {
            $onHand = $this->onHandQuantity($variant);
            if ($onHand !== 0) {
                return 'The initial on-hand quantity is not zero.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>|null
     */
    private function matchingVariant(NewProductDraft $draft, array $product): ?array
    {
        $variants = collect((array) data_get($product, 'variants.nodes', []))
            ->filter(fn (mixed $node): bool => is_array($node))
            ->values();
        $sku = trim((string) ($draft->sku ?? ''));
        if ($sku !== '') {
            $matched = $variants->first(function (array $node) use ($sku): bool {
                return $this->sameSku((string) ($node['sku'] ?? ''), $sku)
                    || $this->sameSku((string) data_get($node, 'inventoryItem.sku', ''), $sku);
            });

            return is_array($matched) ? $matched : null;
        }

        return $variants->count() === 1 && is_array($variants->first()) ? $variants->first() : null;
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private function onHandQuantity(array $variant): ?int
    {
        foreach ((array) data_get($variant, 'inventoryItem.inventoryLevel.quantities', []) as $quantity) {
            if (! is_array($quantity) || ($quantity['name'] ?? null) !== 'on_hand') {
                continue;
            }
            if (! is_numeric($quantity['quantity'] ?? null)) {
                return null;
            }

            return (int) $quantity['quantity'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array{id:?string, handle:?string, error:?string, media_error:?string, copy_error:?string, failure_reason:?string}
     */
    private function success(array $product): array
    {
        return [
            'id' => $product['id'] ?? null,
            'handle' => $product['handle'] ?? null,
            'error' => null,
            'media_error' => null,
            'copy_error' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * @return array{id:?string, handle:?string, error:?string, media_error:?string, copy_error:?string, failure_reason:?string}
     */
    private function failure(string $message, string $reason = 'shopify_user_error'): array
    {
        return [
            'id' => null,
            'handle' => null,
            'error' => $message,
            'media_error' => null,
            'copy_error' => null,
            'failure_reason' => $reason,
        ];
    }

    /**
     * @return array{id:?string, handle:?string, error:?string, media_error:?string, copy_error:?string, failure_reason:?string}
     */
    private function manualReview(NewProductDraft $draft, string $reason, string $details): array
    {
        $this->flagManualReview($draft, $reason, $details);

        return $this->failure($details, $reason);
    }

    private function flagManualReview(NewProductDraft $draft, string $reason, string $details): void
    {
        $warnings = collect(is_array($draft->shopify_sync_warnings) ? $draft->shopify_sync_warnings : [])
            ->reject(fn (mixed $warning): bool => is_array($warning) && ($warning['field'] ?? null) === 'shopify_creation_review')
            ->values()
            ->all();
        $warnings[] = [
            'field' => 'shopify_creation_review',
            'label' => 'Shopify creation needs review',
            'draft_value' => $reason,
            'shopify_value' => $details,
        ];

        NewProductDraft::withoutEvents(function () use ($draft, $warnings): void {
            $draft->forceFill(['shopify_sync_warnings' => $warnings])->save();
        });
    }

    private function clearManualReview(NewProductDraft $draft): void
    {
        $warnings = collect(is_array($draft->shopify_sync_warnings) ? $draft->shopify_sync_warnings : []);
        $remaining = $warnings
            ->reject(fn (mixed $warning): bool => is_array($warning) && ($warning['field'] ?? null) === 'shopify_creation_review')
            ->values()
            ->all();
        if (count($remaining) === $warnings->count()) {
            return;
        }

        NewProductDraft::withoutEvents(function () use ($draft, $remaining): void {
            $draft->forceFill(['shopify_sync_warnings' => $remaining])->save();
        });
    }

    private function ensureDraftIdentifierDefinition(): void
    {
        if ($this->draftIdentifierReady) {
            return;
        }

        $existing = $this->client->graphql($this->draftIdentifierDefinitionQuery(), []);
        $definitionId = trim((string) data_get($existing, 'metafieldDefinitions.nodes.0.id', ''));
        $definitionType = strtolower(trim((string) data_get($existing, 'metafieldDefinitions.nodes.0.type.name', '')));
        if ($definitionId !== '' && $definitionType === 'id') {
            $this->draftIdentifierReady = true;

            return;
        }

        if ($definitionId !== '') {
            $deleted = $this->client->graphql($this->draftIdentifierDefinitionDeleteMutation(), [
                'id' => $definitionId,
                'deleteAllAssociatedMetafields' => true,
            ]);
            $deleteError = $this->userErrorMessage($deleted['metafieldDefinitionDelete']['userErrors'] ?? [], '');
            if ($deleteError !== null) {
                throw new \RuntimeException('The CMS draft id metafield exists as the wrong type and could not be replaced: '.$deleteError);
            }
        }

        $created = $this->client->graphql($this->draftIdentifierDefinitionMutation(), [
            'definition' => [
                'name' => 'CMS draft id',
                'namespace' => 'cms',
                'key' => 'draft_id',
                'description' => 'Identifies the CMS draft that created this Shopify product.',
                'type' => 'id',
                'ownerType' => 'PRODUCT',
                'capabilities' => [
                    'uniqueValues' => ['enabled' => true],
                ],
            ],
        ]);
        $error = $this->userErrorMessage($created['metafieldDefinitionCreate']['userErrors'] ?? [], '');
        if ($error !== null && ! $this->draftIdentifierDefinitionIsReady()) {
            throw new \RuntimeException('CMS draft id metafield definition was not created: '.$error);
        }

        $this->draftIdentifierReady = true;
    }

    private function draftIdentifierDefinitionIsReady(): bool
    {
        $existing = $this->client->graphql($this->draftIdentifierDefinitionQuery(), []);
        $definitionType = strtolower(trim((string) data_get($existing, 'metafieldDefinitions.nodes.0.type.name', '')));

        return $definitionType === 'id';
    }

    private function inventoryLocationId(): ?string
    {
        $configured = trim((string) config('services.shopify.inventory_location_id'));
        if ($configured !== '') {
            return $configured;
        }

        if ($this->resolvedLocationId !== null) {
            return $this->resolvedLocationId;
        }

        $data = $this->client->graphql($this->locationsQuery(), []);
        $id = trim((string) data_get($data, 'locations.nodes.0.id', ''));
        $this->resolvedLocationId = $id !== '' ? $id : '';

        return $this->resolvedLocationId !== '' ? $this->resolvedLocationId : null;
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || ! is_numeric((string) $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function sameSku(string $left, string $right): bool
    {
        return trim($left) !== '' && trim($left) === trim($right);
    }

    private function skuSearch(string $sku): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], trim($sku));

        return 'sku:"'.$escaped.'"';
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    private function userErrorMessage(array $errors, string $fallback): ?string
    {
        if ($errors === []) {
            return null;
        }

        $messages = collect($errors)
            ->map(function (mixed $error): string {
                if (! is_array($error)) {
                    return '';
                }

                $field = isset($error['field']) ? implode('.', (array) $error['field']) : 'input';
                $message = $error['message'] ?? 'Unknown error';

                return "{$field}: {$message}";
            })
            ->filter()
            ->implode('; ');

        return $messages !== '' ? $messages : $fallback;
    }

    private function richTextMetafieldValue(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && (($decoded['type'] ?? null) === 'root')) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $text = trim(html_entity_decode(strip_tags($trimmed), ENT_QUOTES | ENT_HTML5));
        if ($text === '') {
            return null;
        }

        return json_encode([
            'type' => 'root',
            'children' => [[
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'text',
                    'value' => $text,
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function productSetMutation(bool $withInventoryLevel): string
    {
        $signature = $withInventoryLevel
            ? 'mutation ProductSetCreate($input: ProductSetInput!, $locationId: ID!)'
            : 'mutation ProductSetCreate($input: ProductSetInput!)';
        $inventoryLevel = $withInventoryLevel
            ? 'inventoryLevel(locationId: $locationId) { quantities(names: ["on_hand"]) { name quantity } }'
            : '';

        return <<<GQL
{$signature} {
  productSet(synchronous: true, input: \$input) {
    product {
      id
      handle
      status
      metafield(namespace: "cms", key: "draft_id") { value }
      variants(first: 5) {
        nodes {
          id
          sku
          barcode
          price
          compareAtPrice
          inventoryItem {
            id
            tracked
            sku
            {$inventoryLevel}
          }
        }
      }
    }
    userErrors { field message code }
  }
}
GQL;
    }

    private function productByIdentifierQuery(): string
    {
        return <<<'GQL'
query ProductByDraftIdentifier($identifier: ProductIdentifierInput!) {
  productByIdentifier(identifier: $identifier) {
    id
    handle
    status
    metafield(namespace: "cms", key: "draft_id") { value }
    variants(first: 5) {
      nodes {
        id
        sku
        barcode
        price
        compareAtPrice
        inventoryItem { id tracked sku }
      }
    }
  }
}
GQL;
    }

    private function variantsBySkuQuery(): string
    {
        return <<<'GQL'
query VariantsBySku($query: String!) {
  productVariants(first: 10, query: $query) {
    nodes {
      id
      sku
      product {
        id
        handle
        status
        metafield(namespace: "cms", key: "draft_id") { value }
        variants(first: 5) {
          nodes {
            id
            sku
            barcode
            price
            compareAtPrice
            inventoryItem { id tracked sku }
          }
        }
      }
    }
  }
}
GQL;
    }

    private function draftIdentifierDefinitionQuery(): string
    {
        return <<<'GQL'
query DraftIdentifierDefinition {
  metafieldDefinitions(first: 1, ownerType: PRODUCT, namespace: "cms", key: "draft_id") {
    nodes { id type { name } }
  }
}
GQL;
    }

    private function draftIdentifierDefinitionMutation(): string
    {
        return <<<'GQL'
mutation CreateDraftIdentifierDefinition($definition: MetafieldDefinitionInput!) {
  metafieldDefinitionCreate(definition: $definition) {
    createdDefinition { id }
    userErrors { field message code }
  }
}
GQL;
    }

    private function draftIdentifierDefinitionDeleteMutation(): string
    {
        return <<<'GQL'
mutation DeleteDraftIdentifierDefinition($id: ID!, $deleteAllAssociatedMetafields: Boolean!) {
  metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: $deleteAllAssociatedMetafields) {
    deletedDefinitionId
    userErrors { field message code }
  }
}
GQL;
    }

    private function locationsQuery(): string
    {
        return <<<'GQL'
query Locations {
  locations(first: 1) {
    nodes { id }
  }
}
GQL;
    }

    private function mapStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'active' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            default => 'DRAFT',
        };
    }
}
