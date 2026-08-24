<?php

namespace App\Services;

use App\Models\NewProductDraft;
use Illuminate\Support\Collection;

final class NewProductDraftShopifyCreator
{
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

            if (($draft->product?->has_errors ?? false) === true) {
                $skippedHasErrors++;
                continue;
            }

            $complementaryProducts = array_values(array_unique(
                $this->complementaryProducts->parseReferenceTokens($draft->complementary_products)
            ));
            if (count($complementaryProducts) < ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT) {
                $skippedMissingComplementary++;
                continue;
            }

            try {
                $result = $this->createProduct($draft);
                if (!$result['handle'] || !$result['id'] || $result['error']) {
                    $failed++;
                    $failures[] = [
                        'id' => $draft->id,
                        'title' => $draft->title,
                        'reason' => 'shopify_user_error',
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
     * @return array{id:?string, handle:?string, error:?string, media_error:?string}
     */
    private function createProduct(NewProductDraft $draft): array
    {
        $input = [
            'title' => $draft->title,
            'status' => $this->mapStatus($draft->status ?? 'draft'),
        ];

        if ($draft->body_html) {
            $input['descriptionHtml'] = $draft->body_html;
        }
        if ($draft->vendor) {
            $input['vendor'] = $draft->vendor;
        }
        // Product category is stored but not sent here to avoid API errors
        // when a non-GID value is provided.

        // NOTE: Shopify ProductInput does not accept variants in this API version.
        // Variants must be created in a follow-up mutation after productCreate.

        $data = $this->client->graphql($this->mutation(), ['input' => $input]);
        $payload = $data['productCreate'] ?? null;
        if (!$payload) {
            return [
                'id' => null,
                'handle' => null,
                'error' => 'Missing productCreate payload.',
                'media_error' => null,
            ];
        }

        $errors = $payload['userErrors'] ?? [];
        if (!empty($errors)) {
            $messages = collect($errors)
                ->map(function (array $error): string {
                    $field = isset($error['field']) ? implode('.', (array) $error['field']) : 'input';
                    $message = $error['message'] ?? 'Unknown error';
                    return "{$field}: {$message}";
                })
                ->implode('; ');

            return [
                'id' => null,
                'handle' => null,
                'error' => $messages !== '' ? $messages : 'Unknown user error.',
                'media_error' => null,
            ];
        }

        $product = $payload['product'] ?? null;
        $mediaError = null;

        if ($product) {
            $inventoryError = $this->configureInventoryTracking($draft, $product);
            if ($inventoryError !== null) {
                return [
                    'id' => null,
                    'handle' => null,
                    'error' => $inventoryError,
                    'media_error' => null,
                ];
            }

            $mediaSources = $this->mediaSources($draft);
            if ($mediaSources !== []) {
                $mediaError = $this->attachImages($product['id'] ?? null, $mediaSources);
            }
        }

        return [
            'id' => $product['id'] ?? null,
            'handle' => $product['handle'] ?? null,
            'error' => null,
            'media_error' => $mediaError,
        ];
    }

    /** @param array<string, mixed> $product */
    private function configureInventoryTracking(NewProductDraft $draft, array $product): ?string
    {
        $inventoryItemId = trim((string) data_get($product, 'variants.nodes.0.inventoryItem.id', ''));
        if ($inventoryItemId === '') {
            return 'Shopify created the product without a resolvable default variant inventory item.';
        }

        $tracked = !$this->isStackOrBundle($draft);
        $data = $this->client->graphql($this->inventoryItemUpdateMutation(), [
            'id' => $inventoryItemId,
            'input' => ['tracked' => $tracked],
        ]);
        $payload = $data['inventoryItemUpdate'] ?? null;
        if (!is_array($payload)) {
            return 'Missing inventoryItemUpdate payload after Shopify product creation.';
        }

        $errors = $payload['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $messages = collect($errors)
                ->map(function (array $error): string {
                    $field = isset($error['field']) ? implode('.', (array) $error['field']) : 'input';
                    $message = $error['message'] ?? 'Unknown error';

                    return "{$field}: {$message}";
                })
                ->implode('; ');

            return $messages !== '' ? $messages : 'Shopify rejected the inventory tracking update.';
        }

        $confirmed = data_get($payload, 'inventoryItem.tracked');
        if (!is_bool($confirmed) || $confirmed !== $tracked) {
            return 'Shopify did not confirm the required inventory tracking state.';
        }

        if (!$tracked) {
            return null;
        }

        $quantity = $draft->variant_inventory_qty ?? NewProductDraft::DEFAULT_VARIANT_INVENTORY_QTY;
        if (!is_numeric($quantity) || (int) $quantity < 0) {
            return 'Initial inventory must be zero or greater.';
        }

        return $this->setInitialInventoryQuantity($inventoryItemId, (int) $quantity, $draft);
    }

    private function setInitialInventoryQuantity(
        string $inventoryItemId,
        int $quantity,
        NewProductDraft $draft
    ): ?string {
        $locationData = $this->client->graphql($this->locationsQuery());
        $locationId = trim((string) data_get($locationData, 'locations.nodes.0.id', ''));
        if ($locationId === '') {
            return 'No Shopify location was available for the initial inventory quantity.';
        }

        $reference = $draft->id
            ? 'logistics://shopify-editor/new-product-draft/' . $draft->id
            : 'logistics://shopify-editor/new-product-draft';
        $data = $this->client->graphql($this->inventorySetMutation(), [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'ignoreCompareQuantity' => true,
                'referenceDocumentUri' => $reference,
                'quantities' => [[
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $quantity,
                ]],
            ],
        ]);

        $errors = data_get($data, 'inventorySetQuantities.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            $messages = collect($errors)
                ->map(fn (array $error): string => (string) ($error['message'] ?? 'Unknown inventory error'))
                ->filter()
                ->implode('; ');

            return $messages !== '' ? $messages : 'Shopify rejected the initial inventory quantity.';
        }

        return null;
    }

    private function isStackOrBundle(NewProductDraft $draft): bool
    {
        if (is_array($draft->bundle_product_ids) && $draft->bundle_product_ids !== []) {
            return true;
        }

        foreach ([$draft->tags, $draft->type, $draft->title] as $value) {
            if (TagNormalizer::containsBundleOrStackTag(is_string($value) ? $value : null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function mediaSources(NewProductDraft $draft): array
    {
        $sources = [];

        $primary = trim((string) ($draft->imageUrl() ?? ''));
        if ($primary !== '') {
            $sources[] = $primary;
        }

        $bundleImageUrls = is_array($draft->bundle_image_urls) ? $draft->bundle_image_urls : [];
        foreach ($bundleImageUrls as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '') {
                $sources[] = $url;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param array<int, string> $imageUrls
     */
    private function attachImages(?string $productId, array $imageUrls): ?string
    {
        if (!$productId) {
            return 'Missing product id for media upload.';
        }

        $imageUrls = array_values(array_filter(array_map(
            static fn (string $url): string => trim($url),
            $imageUrls
        ), static fn (string $url): bool => $url !== ''));

        if ($imageUrls === []) {
            return 'Unable to resolve image URL.';
        }

        $data = $this->client->graphql($this->mediaMutation(), [
            'productId' => $productId,
            'media' => collect($imageUrls)
                ->map(fn (string $imageUrl): array => [
                    'originalSource' => $imageUrl,
                    'mediaContentType' => 'IMAGE',
                ])
                ->values()
                ->all(),
        ]);

        $payload = $data['productCreateMedia'] ?? null;
        if (!$payload) {
            return 'Missing productCreateMedia payload.';
        }

        $errors = $payload['mediaUserErrors'] ?? [];
        if (!empty($errors)) {
            $messages = collect($errors)
                ->map(function (array $error): string {
                    $field = isset($error['field']) ? implode('.', (array) $error['field']) : 'media';
                    $message = $error['message'] ?? 'Unknown error';
                    return "{$field}: {$message}";
                })
                ->implode('; ');
            return $messages !== '' ? $messages : 'Unknown media error.';
        }

        return null;
    }

    private function mutation(): string
    {
        return <<<'GQL'
mutation ProductCreate($input: ProductInput!) {
  productCreate(input: $input) {
    product {
      id
      handle
      variants(first: 1) {
        nodes {
          inventoryItem {
            id
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function inventoryItemUpdateMutation(): string
    {
        return <<<'GQL'
mutation InventoryItemUpdate($id: ID!, $input: InventoryItemInput!) {
  inventoryItemUpdate(id: $id, input: $input) {
    inventoryItem {
      id
      tracked
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function locationsQuery(): string
    {
        return <<<'GQL'
query LocationsForNewProductInventory {
  locations(first: 1) {
    nodes {
      id
    }
  }
}
GQL;
    }

    private function inventorySetMutation(): string
    {
        return <<<'GQL'
mutation InventorySetNewProductQuantity($input: InventorySetQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    userErrors {
      field
      message
      code
    }
  }
}
GQL;
    }

    private function mediaMutation(): string
    {
        return <<<'GQL'
mutation ProductCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
  productCreateMedia(productId: $productId, media: $media) {
    media {
      ... on MediaImage {
        id
      }
    }
    mediaUserErrors {
      field
      message
    }
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

    private function mapInventoryPolicy(string $policy): string
    {
        $normalized = strtolower(trim($policy));
        return $normalized === 'continue' ? 'CONTINUE' : 'DENY';
    }
}
