<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Services\TagNormalizer;
use Illuminate\Support\Collection;

final class ProductShopifyUpdater
{
    /** @var array<string, array<string, string>> */
    private array $referenceLookupCache = [];

    /** @var array<string, array<int, string>> */
    private array $metaobjectTypesByLookupCache = [];

    /** @var array<string, array<int, array{id:string,displayName:string,handle:string,fields:array<int, array{key:string,value:string}>}>> */
    private array $metaobjectsByTypeCache = [];

    /** @var array<string, array<string, string>>|null */
    private ?array $acceptedValuesByHeaderCache = null;

    /** @var array<string, string|null> */
    private array $categoryIdByTypeCache = [];

    private ?string $shopCurrencyCodeCache = null;
    private ?bool $categoryGraphqlSupported = null;

    public function __construct(
        private readonly ShopifyApiClient $client,
    ) {}

    /**
     * @param Collection<int, Product> $products
     * @return array{
     *   updated:int,
     *   skipped_not_approved:int,
     *   skipped_missing_handle:int,
     *   failed:int,
     *   warnings:array<int, array{product_id:int, warning:string}>,
     *   failures:array<int, array{product_id:int, reason:string, details:string|null}>
     * }
     */
    public function updateApprovedProducts(Collection $products): array
    {
        $updated = 0;
        $skippedNotApproved = 0;
        $skippedMissingHandle = 0;
        $failed = 0;
        $warnings = [];
        $failures = [];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            if (!$product->handle) {
                $skippedMissingHandle++;
                continue;
            }

            if (!$product->isApprovedByTwo()) {
                $skippedNotApproved++;
                continue;
            }

            try {
                $warnings = array_merge($warnings, $this->updateProduct($product));
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'product_id' => $product->id,
                    'reason' => 'exception',
                    'details' => $e->getMessage(),
                ];
                logger()->error('Shopify product sync failed.', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'updated' => $updated,
            'skipped_not_approved' => $skippedNotApproved,
            'skipped_missing_handle' => $skippedMissingHandle,
            'failed' => $failed,
            'warnings' => $warnings,
            'failures' => $failures,
        ];
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function updateProduct(Product $product): array
    {
        $warnings = [];
        $productId = $this->resolveShopifyId($product->handle);
        if (!$productId) {
            throw new \RuntimeException('Unable to resolve Shopify product ID for handle.');
        }

        [$primaryRow, $variantRows, $imageRows] = $this->loadRows($product);
        $primaryData = $primaryRow?->data ?? [];
        $variantData = $variantRows[0] ?? $primaryData;
        $imageData = $imageRows[0] ?? $primaryData;

        $title = $this->valueFromRow($primaryData, HeaderStore::TITLE, $product->title);
        $vendor = $this->valueFromRow($primaryData, HeaderStore::VENDOR, $product->vendor);
        $productType = $this->valueFromRow($primaryData, HeaderStore::TYPE, $product->type);
        $bodyHtml = $this->valueFromRow($primaryData, HeaderStore::BODY_HTML, $product->body_html);
        $statusRaw = $this->valueFromRow($primaryData, HeaderStore::STATUS, $product->status ?? 'draft');
        $tagsRaw = $this->valueFromRow($primaryData, HeaderStore::TAGS, $product->tags);
        $productCategory = $this->valueFromRow($primaryData, HeaderStore::PRODUCT_CATEGORY, null);

        $input = [
            'id' => $productId,
        ];

        if ($title !== null) {
            $input['title'] = $title;
        }
        if ($vendor !== null) {
            $input['vendor'] = $vendor;
        }
        if ($productType !== null) {
            $input['productType'] = $productType;
        }
        if ($bodyHtml !== null) {
            $input['descriptionHtml'] = $bodyHtml;
        }
        if ($tagsRaw !== null) {
            $input['tags'] = TagNormalizer::parseTokens((string) $tagsRaw);
        }
        if ($statusRaw !== null) {
            $input['status'] = $this->mapStatus((string) $statusRaw);
        }
        if ($productCategory !== null) {
            $productCategory = $this->normalizeSingleAcceptedValue(HeaderStore::PRODUCT_CATEGORY, $productCategory) ?? $productCategory;
        }

        if ($primaryRow) {
            $seoTitle = $this->nullIfEmpty($primaryRow->get(HeaderStore::SEO_TITLE, ''));
            $seoDescription = $this->nullIfEmpty($primaryRow->get(HeaderStore::SEO_DESCRIPTION, ''));
            if ($seoTitle !== null || $seoDescription !== null) {
                $input['seo'] = [
                    'title' => $seoTitle,
                    'description' => $seoDescription,
                ];
            }
        }

        $data = $this->client->graphql($this->productUpdateMutation(), [
            'input' => $input,
        ]);

        $errors = data_get($data, 'productUpdate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            $messages = $this->formatUserErrors($errors);
            throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
        }

        $details = $this->productByHandleDetails($product->handle);
        $currentCategoryId = trim((string) data_get($details, 'category.id', ''));
        if ($currentCategoryId === '') {
            $currentCategoryId = trim((string) data_get($details, 'productCategory.productTaxonomyNode.id', ''));
        }
        $currentCategoryName = trim((string) data_get($details, 'category.name', ''));
        if ($currentCategoryName === '') {
            $currentCategoryName = trim((string) data_get($details, 'productCategory.productTaxonomyNode.fullName', ''));
        }

        if (!$this->hasCategoryContext($currentCategoryId, $currentCategoryName)) {
            $resolvedCategoryGid = $this->resolveProductCategoryGid($product, $productCategory, $productType);
            if ($resolvedCategoryGid !== null) {
                $warnings = array_merge($warnings, $this->attemptCategoryUpdate($product, $productId, $resolvedCategoryGid, $productType));
                $details = $this->productByHandleDetails($product->handle);
                $currentCategoryId = trim((string) data_get($details, 'category.id', ''));
                if ($currentCategoryId === '') {
                    $currentCategoryId = trim((string) data_get($details, 'productCategory.productTaxonomyNode.id', ''));
                }
                $currentCategoryName = trim((string) data_get($details, 'category.name', ''));
                if ($currentCategoryName === '') {
                    $currentCategoryName = trim((string) data_get($details, 'productCategory.productTaxonomyNode.fullName', ''));
                }
            }
        }

        $shopifyRawMetafields = $this->productMetafieldRawValuesByHandle($product->handle);

        if ($primaryRow) {
            $warnings = array_merge(
                $warnings,
                $this->updateMetafields(
                    $product,
                    $productId,
                    $primaryData,
                    $shopifyRawMetafields,
                    $currentCategoryId !== '' ? $currentCategoryId : null,
                    $currentCategoryName !== '' ? $currentCategoryName : null
                )
            );
        }

        $warnings = array_merge(
            $warnings,
            $this->updateVariantAndInventory($product, $productId, $variantRows, $details)
        );
        $warnings = array_merge(
            $warnings,
            $this->updateImages($product, $productId, $imageRows, $details)
        );
        $warnings = array_merge(
            $warnings,
            $this->buildSyncCoverageWarnings($product, $primaryData, $variantRows, $imageRows)
        );

        return $warnings;
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function updateMetafields(
        Product $product,
        string $productId,
        array $rowData,
        array $shopifyRawValues = [],
        ?string $currentCategoryId = null,
        ?string $currentCategoryName = null
    ): array
    {
        $warnings = [];
        $payload = $this->metafieldsFromRow(
            $product,
            $productId,
            $rowData,
            $warnings,
            $shopifyRawValues,
            $currentCategoryId,
            $currentCategoryName
        );
        $metafields = $payload['inputs'];
        $indexMap = $payload['indexMap'];
        if (empty($metafields)) {
            return $warnings;
        }

        $data = $this->client->graphql($this->metafieldsSetMutation(), [
            'metafields' => $metafields,
        ]);

        $errors = data_get($data, 'metafieldsSet.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            foreach ($errors as $error) {
                $field = $error['field'] ?? null;
                $index = null;
                if (is_array($field) && isset($field[1]) && is_numeric($field[1])) {
                    $index = (int) $field[1];
                }
                $meta = $index !== null ? ($indexMap[$index] ?? null) : null;
                $label = $meta
                    ? "{$meta['header']} ({$meta['namespace']}.{$meta['key']})"
                    : 'metafield';

                $message = $error['message'] ?? 'Unknown error';
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "{$label}: {$message}",
                ];
            }
        }

        return $warnings;
    }

    private function metafieldsFromRow(
        Product $product,
        string $productId,
        array $rowData,
        array &$warnings,
        array $shopifyRawValues = [],
        ?string $currentCategoryId = null,
        ?string $currentCategoryName = null
    ): array
    {
        $definitions = ShopifyMetafield::query()
            ->where('import_id', $product->import_id)
            ->get()
            ->groupBy(fn (ShopifyMetafield $field) => "{$field->namespace}.{$field->key}");
        $existingRawValues = ShopifyMetafield::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->get()
            ->mapWithKeys(fn (ShopifyMetafield $field): array => [
                "{$field->namespace}.{$field->key}" => (string) ($field->value ?? ''),
            ]);
        $existingRawValues = $existingRawValues->merge($shopifyRawValues);
        $rowCategoryValue = trim((string) ($rowData[HeaderStore::PRODUCT_CATEGORY] ?? ''));
        $rowCategoryIsGid = str_starts_with($rowCategoryValue, 'gid://');
        $currentCategoryLooksUncategorized = $currentCategoryName !== null
            && str_contains(strtolower(trim($currentCategoryName)), 'uncategorized');
        $hasCategoryContext = $rowCategoryIsGid
            || (
                $currentCategoryId !== null
                && $currentCategoryId !== ''
                && !$currentCategoryLooksUncategorized
            );

        $inputs = [];
        $indexMap = [];
        foreach ($rowData as $header => $value) {
            $identifier = $this->metafieldIdentifierFromHeader((string) $header);
            if (!$identifier) {
                continue;
            }

            $lookup = $identifier['namespace'] . '.' . $identifier['key'];
            $stringValue = is_scalar($value) ? trim((string) $value) : '';
            if ($stringValue === '') {
                continue;
            }

            $type = $definitions->get($lookup)?->first()?->type;
            if (!$type) {
                $type = $this->fallbackMetafieldType($header, $stringValue);
            }

            if (!$type) {
                continue;
            }

            if ($this->isSubtypeConstrainedShopifyMetafield($identifier['namespace'], $identifier['key']) && !$hasCategoryContext) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Skipped metafield {$lookup}: missing Shopify taxonomy category (Product Category must be a Shopify GID or already set on product).",
                ];
                continue;
            }

            $stringValue = $this->normalizeMetafieldInputFromAccepted((string) $header, $stringValue, $type);
            $formatted = $this->formatMetafieldValue(
                $type,
                $stringValue,
                is_string($existingRawValues->get($lookup)) ? $existingRawValues->get($lookup) : null,
                $lookup,
                (string) $header
            );
            if ($formatted === null) {
                if ($this->isReferenceType($type)) {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => "Skipped metafield {$lookup}: unable to resolve reference value to a Shopify GID.",
                    ];
                }
                continue;
            }

            $inputs[] = [
                'ownerId' => $productId,
                'namespace' => $identifier['namespace'],
                'key' => $identifier['key'],
                'type' => $type,
                'value' => $formatted,
            ];
            $indexMap[count($inputs) - 1] = [
                'header' => (string) $header,
                'namespace' => $identifier['namespace'],
                'key' => $identifier['key'],
            ];
        }

        return [
            'inputs' => $inputs,
            'indexMap' => $indexMap,
        ];
    }

    private function resolveShopifyId(string $handle): ?string
    {
        $data = $this->client->graphql($this->productByHandleQuery(), [
            'handle' => $handle,
        ]);

        return data_get($data, 'productByHandle.id');
    }

    private function productByHandleDetails(string $handle): array
    {
        $data = $this->client->graphql($this->productByHandleDetailsQuery(), [
            'handle' => $handle,
        ]);

        return data_get($data, 'productByHandle', []) ?: [];
    }

    /**
     * @return array<string, string>
     */
    private function productMetafieldRawValuesByHandle(string $handle): array
    {
        $data = $this->client->graphql($this->productByHandleMetafieldsQuery(), [
            'handle' => $handle,
        ]);

        $nodes = data_get($data, 'productByHandle.metafields.nodes', []);
        if (!is_array($nodes) || empty($nodes)) {
            return [];
        }

        $map = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $namespace = trim((string) ($node['namespace'] ?? ''));
            $key = trim((string) ($node['key'] ?? ''));
            $value = (string) ($node['value'] ?? '');
            if ($namespace === '' || $key === '' || $value === '') {
                continue;
            }

            $map["{$namespace}.{$key}"] = $value;
        }

        return $map;
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function updateVariantAndInventory(
        Product $product,
        string $productId,
        array $rowDataList,
        array $details
    ): array
    {
        $warnings = [];
        $variantNodes = data_get($details, 'variants.nodes', []);
        if (!is_array($variantNodes) || empty($variantNodes)) {
            return $warnings;
        }

        $shopifyVariants = collect($variantNodes)
            ->filter(fn ($node) => is_array($node) && !empty($node['id']))
            ->mapWithKeys(function (array $node): array {
                $sku = trim((string) ($node['sku'] ?? ''));
                $key = $sku !== '' ? $sku : ($node['id'] ?? '');
                return [$key => $node];
            });

        $locationId = null;
        $variantInputs = [];

        foreach ($rowDataList as $rowData) {
            if (!is_array($rowData)) {
                continue;
            }

            $rowSku = $this->valueFromRow($rowData, HeaderStore::VARIANT_SKU, null);
            $variantNode = $rowSku !== null
                ? $shopifyVariants->get($rowSku)
                : $shopifyVariants->first();

            if (!$variantNode) {
                continue;
            }

            $variantId = $variantNode['id'] ?? null;
            $inventoryItemId = data_get($variantNode, 'inventoryItem.id');
            $currencyCode = data_get($variantNode, 'inventoryItem.unitCost.currencyCode');

            if ($variantId) {
                $input = ['id' => $variantId];
                $price = $this->valueFromRow($rowData, HeaderStore::VARIANT_PRICE, null);
                $compareAt = $this->valueFromRow($rowData, HeaderStore::VARIANT_COMPARE_AT, null);
                $weightUnit = $this->valueFromRow($rowData, HeaderStore::VARIANT_WEIGHT_UNIT, null);
                $barcode = $this->valueFromRow($rowData, HeaderStore::VARIANT_BARCODE, null);
                $grams = $this->normalizeNumeric($rowData[HeaderStore::VARIANT_GRAMS] ?? null);

                $optionValues = $this->variantOptionValuesFromRow($rowData, $variantNode);

                if ($rowSku !== null) {
                    $input['sku'] = $rowSku;
                }
                if ($price !== null) {
                    $input['price'] = (string) $price;
                }
                if ($compareAt !== null) {
                    $input['compareAtPrice'] = (string) $compareAt;
                }
                if ($barcode !== null) {
                    $input['barcode'] = (string) $barcode;
                }
                if (!empty($optionValues)) {
                    $input['optionValues'] = $optionValues;
                }
                if ($grams !== null) {
                    $unit = $this->mapWeightUnit($weightUnit);
                    $input['weightUnit'] = $unit;
                    $input['weight'] = $this->weightFromGrams($grams, $unit);
                } elseif ($weightUnit !== null) {
                    $input['weightUnit'] = $this->mapWeightUnit($weightUnit);
                }

                if (count($input) > 1) {
                    $variantInputs[] = $input;
                }
            }

            $costPerItem = $this->normalizeNumeric($rowData[HeaderStore::COST_PER_ITEM] ?? null);
            if ($inventoryItemId && $costPerItem !== null && $currencyCode) {
                $data = $this->client->graphql($this->inventoryItemUpdateMutation(), [
                    'input' => [
                        'id' => $inventoryItemId,
                        'unitCost' => [
                            'amount' => (string) $costPerItem,
                            'currencyCode' => $currencyCode,
                        ],
                    ],
                ]);
                $errors = data_get($data, 'inventoryItemUpdate.userErrors', []);
                if (is_array($errors) && !empty($errors)) {
                    $messages = $this->formatUserErrors($errors);
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => $messages !== '' ? $messages : 'Shopify cost per item update failed.',
                    ];
                }
            } elseif ($inventoryItemId && $costPerItem !== null && !$currencyCode) {
                $fallbackCurrencyCode = $this->shopCurrencyCode();
                if ($fallbackCurrencyCode !== null) {
                    $data = $this->client->graphql($this->inventoryItemUpdateMutation(), [
                        'input' => [
                            'id' => $inventoryItemId,
                            'unitCost' => [
                                'amount' => (string) $costPerItem,
                                'currencyCode' => $fallbackCurrencyCode,
                            ],
                        ],
                    ]);
                    $errors = data_get($data, 'inventoryItemUpdate.userErrors', []);
                    if (is_array($errors) && !empty($errors)) {
                        $messages = $this->formatUserErrors($errors);
                        $warnings[] = [
                            'product_id' => $product->id,
                            'warning' => $messages !== '' ? $messages : 'Shopify cost per item update failed.',
                        ];
                    }
                } else {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => 'Missing currency code for cost per item.',
                    ];
                }
            }

            $inventoryQty = $this->normalizeNumeric($rowData[HeaderStore::VARIANT_INVENTORY_QTY] ?? null);
            if ($inventoryItemId && $inventoryQty !== null) {
                $locationId = $locationId ?? $this->firstLocationId();
                if ($locationId) {
                    $data = $this->client->graphql($this->inventorySetMutation(), [
                        'input' => [
                            'inventoryItemId' => $inventoryItemId,
                            'locationId' => $locationId,
                            'quantity' => (int) $inventoryQty,
                        ],
                    ]);
                    $errors = data_get($data, 'inventorySetOnHand.userErrors', []);
                    if (is_array($errors) && !empty($errors)) {
                        $messages = $this->formatUserErrors($errors);
                        $warnings[] = [
                            'product_id' => $product->id,
                            'warning' => $messages !== '' ? $messages : 'Shopify inventory update failed.',
                        ];
                    }
                } else {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => 'No Shopify location found for inventory update.',
                    ];
                }
            }
        }

        if (!empty($variantInputs)) {
            $data = $this->client->graphql($this->variantsBulkUpdateMutation(), [
                'productId' => $productId,
                'variants' => $variantInputs,
            ]);
            $errors = data_get($data, 'productVariantsBulkUpdate.userErrors', []);
            if (is_array($errors) && !empty($errors)) {
                $messages = $this->formatUserErrors($errors);
                throw new \RuntimeException($messages !== '' ? $messages : 'Shopify variant update failed.');
            }
        }

        return $warnings;
    }

    private function firstLocationId(): ?string
    {
        $data = $this->client->graphql($this->locationsQuery(), []);
        return data_get($data, 'locations.nodes.0.id');
    }

    private function productByHandleQuery(): string
    {
        return <<<'GQL'
query ProductByHandle($handle: String!) {
  productByHandle(handle: $handle) {
    id
  }
}
GQL;
    }

    private function productByHandleDetailsQuery(): string
    {
        return <<<'GQL'
query ProductByHandleDetails($handle: String!) {
  productByHandle(handle: $handle) {
    id
    category {
      id
      name
    }
    productCategory {
      productTaxonomyNode {
        id
        fullName
      }
    }
    variants(first: 250) {
      nodes {
        id
        sku
        selectedOptions {
          name
          value
        }
        inventoryItem {
          id
          unitCost { currencyCode }
        }
      }
    }
    images(first: 50) {
      nodes { url }
    }
  }
}
GQL;
    }

    private function productByHandleMetafieldsQuery(): string
    {
        return <<<'GQL'
query ProductByHandleMetafields($handle: String!) {
  productByHandle(handle: $handle) {
    metafields(first: 250) {
      nodes {
        namespace
        key
        value
      }
    }
  }
}
GQL;
    }

    private function productUpdateMutation(): string
    {
        return <<<'GQL'
mutation ProductUpdate($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function variantsBulkUpdateMutation(): string
    {
        return <<<'GQL'
mutation ProductVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function productCreateMediaMutation(): string
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

    private function inventoryItemUpdateMutation(): string
    {
        return <<<'GQL'
mutation InventoryItemUpdate($input: InventoryItemInput!) {
  inventoryItemUpdate(input: $input) {
    inventoryItem { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function inventorySetMutation(): string
    {
        return <<<'GQL'
mutation InventorySetOnHand($input: InventorySetOnHandInput!) {
  inventorySetOnHand(input: $input) {
    inventoryLevel { id }
    userErrors { field message }
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

    private function shopCurrencyQuery(): string
    {
        return <<<'GQL'
query ShopCurrency {
  shop {
    currencyCode
  }
}
GQL;
    }

    private function metafieldsSetMutation(): string
    {
        return <<<'GQL'
mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function metafieldDefinitionQuery(): string
    {
        return <<<'GQL'
query MetafieldDefinition($namespace: String!, $key: String!) {
  metafieldDefinition(ownerType: PRODUCT, namespace: $namespace, key: $key) {
    validations {
      name
      value
    }
  }
}
GQL;
    }

    private function metaobjectDefinitionTypesQuery(): string
    {
        return <<<'GQL'
query MetaobjectDefinitionTypes($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on MetaobjectDefinition {
      id
      type
    }
  }
}
GQL;
    }

    private function metaobjectsByTypeQuery(): string
    {
        return <<<'GQL'
query MetaobjectsByType($type: String!) {
  metaobjects(type: $type, first: 250) {
    nodes {
      id
      displayName
      handle
      fields {
        key
        value
      }
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

    private function shopCurrencyCode(): ?string
    {
        if ($this->shopCurrencyCodeCache !== null) {
            return $this->shopCurrencyCodeCache;
        }

        try {
            $data = $this->client->graphql($this->shopCurrencyQuery(), []);
        } catch (\Throwable) {
            return null;
        }

        $currency = trim((string) data_get($data, 'shop.currencyCode', ''));
        if ($currency === '') {
            try {
                $rest = $this->client->rest('GET', 'shop.json');
                $currency = trim((string) data_get($rest, 'shop.currency', ''));
            } catch (\Throwable) {
                $currency = '';
            }
        }
        if ($currency === '') {
            return null;
        }

        $this->shopCurrencyCodeCache = $currency;
        return $currency;
    }

    private function resolveProductCategoryGid(Product $product, ?string $productCategory, ?string $productType): ?string
    {
        $normalizedCategory = $productCategory !== null ? trim($productCategory) : '';
        if ($normalizedCategory !== '' && str_starts_with($normalizedCategory, 'gid://')) {
            return $normalizedCategory;
        }

        if ($normalizedCategory !== '' && !$this->looksUncategorizedCategory($normalizedCategory)) {
            $taxonomyId = $this->findTaxonomyNodeIdByCategoryName($normalizedCategory);
            if ($taxonomyId !== null) {
                return $taxonomyId;
            }

            $fromPeers = $this->findCategoryGidFromPeerProducts($product, $productType, $normalizedCategory);
            if ($fromPeers !== null) {
                return $fromPeers;
            }
        }

        $typeCategory = trim((string) $productType);
        if ($typeCategory !== '') {
            $taxonomyId = $this->findTaxonomyNodeIdByCategoryName($typeCategory);
            if ($taxonomyId !== null) {
                return $taxonomyId;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    // private function attemptCategoryUpdate(Product $product, string $productId, string $taxonomyGid, ?string $productType): array
    // {
    //     $warnings = [];
    //     $lastError = null;
    //     if ($this->categoryGraphqlSupported !== false) {
    //         $idCandidates = $this->taxonomyIdCandidates($taxonomyGid);
    //         foreach ($idCandidates as $candidate) {
    //             $input = [
    //                 'id' => $productId,
    //                 'category' => ['id' => $candidate],
    //             ];
    //             try {
    //                 $data = $this->client->graphql($this->productUpdateMutation(), [
    //                     'input' => $input,
    //                 ]);
    //             } catch (\Throwable $e) {
    //                 $message = $e->getMessage();
    //                 if ($this->isUnsupportedCategoryInputMessage($message)) {
    //                     $this->categoryGraphqlSupported = false;
    //                     break;
    //                 }
    //                 $lastError = $message;
    //                 continue;
    //             }

    //             $errors = data_get($data, 'productUpdate.userErrors', []);
    //             if (!is_array($errors) || empty($errors)) {
    //                 $this->categoryGraphqlSupported = true;
    //                 return [];
    //             }

    //             $messages = $this->formatUserErrors($errors);
    //             if ($this->isUnsupportedCategoryInputMessage($messages)) {
    //                 $this->categoryGraphqlSupported = false;
    //                 break;
    //             }
    //             $lastError = $messages;
    //         }
    //     }

    //     $restSet = $this->attemptCategoryUpdateViaRest($productId, $taxonomyGid);
    //     if ($restSet) {
    //         return [];
    //     }

    //     if ($lastError !== null) {
    //         $warnings[] = [
    //             'product_id' => $product->id,
    //             'warning' => "Unable to set Shopify taxonomy category automatically: {$lastError}",
    //         ];
    //     }

    //     return $warnings;
    // }

    /**
 * @return array<int, array{product_id:int, warning:string}>
 */
private function attemptCategoryUpdate(Product $product, string $productId, string $taxonomyGid, ?string $productType): array
{
    $warnings = [];

    // Shopify 2026-01 expects ProductInput.category to be an ID (string), typically a TaxonomyCategory GID.
    $candidates = $this->taxonomyIdCandidates($taxonomyGid);

    $lastError = null;

    foreach ($candidates as $candidate) {
        // ✅ Correct shape for GraphQL ProductInput on modern versions:
        $input = [
            'id' => $productId,
            'category' => $candidate, // <-- MUST be string ID, not ['id' => ...]
        ];

        try {
            $data = $this->client->graphql($this->productUpdateMutation(), [
                'input' => $input,
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // If Shopify says the field doesn't exist on ProductInput, then (and only then)
            // treat GraphQL category as unsupported.
            if ($this->isUnsupportedCategoryInputMessage($message)) {
                $this->categoryGraphqlSupported = false;
                break;
            }

            $lastError = $message;
            continue;
        }

        $errors = data_get($data, 'productUpdate.userErrors', []);
        if (!is_array($errors) || empty($errors)) {
            $this->categoryGraphqlSupported = true;
            return $warnings; // success
        }

        $messages = $this->formatUserErrors($errors);

        // If the schema doesn't support it, mark unsupported.
        if ($this->isUnsupportedCategoryInputMessage($messages)) {
            $this->categoryGraphqlSupported = false;
            break;
        }

        // Otherwise keep last error and try next candidate form.
        $lastError = $messages;
    }

    // REST fallback is optional; on 2026-01 you should not need it.
    // Keep it if you want, but it often fails / is inconsistent across stores.
    if ($this->categoryGraphqlSupported === false) {
        $restSet = $this->attemptCategoryUpdateViaRest($productId, $taxonomyGid);
        if ($restSet) {
            return $warnings;
        }
    }

    if ($lastError !== null) {
        $warnings[] = [
            'product_id' => $product->id,
            'warning' => "Unable to set Shopify taxonomy category automatically: {$lastError}",
        ];
    }

    return $warnings;
}


    private function attemptCategoryUpdateViaRest(string $productId, string $taxonomyGid): bool
    {
        $numericProductId = $this->extractNumericId($productId, 'Product');
        $taxonomyNodeId = $this->extractTaxonomyNodeTail($taxonomyGid);
        if ($numericProductId === null || $taxonomyNodeId === null) {
            return false;
        }

        try {
            $this->client->rest('PUT', "products/{$numericProductId}.json", [
                'product' => [
                    'id' => $numericProductId,
                    'product_taxonomy_node_id' => $taxonomyNodeId,
                ],
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractNumericId(string $gid, string $resource): ?string
    {
        if (!preg_match('#gid://shopify/' . preg_quote($resource, '#') . '/([0-9]+)$#', trim($gid), $m)) {
            return null;
        }

        return $m[1] ?? null;
    }

    private function extractTaxonomyNodeTail(string $gid): ?string
{
    if (!preg_match('#gid://shopify/(?:TaxonomyCategory|ProductTaxonomyNode)/([0-9]+)$#', trim($gid), $m)) {
        return null;
    }

    $tail = trim((string) ($m[1] ?? ''));
    return $tail === '' ? null : $tail;
}


    // private function extractTaxonomyNodeTail(string $gid): ?string
    // {
    //     if (!preg_match('#gid://shopify/(?:TaxonomyCategory|ProductTaxonomyNode)/(.+)$#', trim($gid), $m)) {
    //         return null;
    //     }

    //     $tail = trim((string) ($m[1] ?? ''));
    //     return $tail === '' ? null : $tail;
    // }

    /**
     * @return array<int, string>
     */
    // private function taxonomyIdCandidates(string $taxonomyGid): array
    // {
    //     $value = trim($taxonomyGid);
    //     if ($value === '') {
    //         return [];
    //     }

    //     $candidates = [$value];
    //     if (str_contains($value, 'gid://shopify/ProductTaxonomyNode/')) {
    //         $candidates[] = str_replace('gid://shopify/ProductTaxonomyNode/', 'gid://shopify/TaxonomyCategory/', $value);
    //     } elseif (str_contains($value, 'gid://shopify/TaxonomyCategory/')) {
    //         $candidates[] = str_replace('gid://shopify/TaxonomyCategory/', 'gid://shopify/ProductTaxonomyNode/', $value);
    //     }

    //     return array_values(array_unique(array_filter($candidates)));
    // }

    /**
 * @return array<int, string>
 */
private function taxonomyIdCandidates(string $taxonomyGid): array
{
    $value = trim($taxonomyGid);
    if ($value === '') {
        return [];
    }

    $candidates = [];

    // Prefer TaxonomyCategory for modern ProductInput.category usage
    if (str_contains($value, 'gid://shopify/TaxonomyCategory/')) {
        $candidates[] = $value;
        $candidates[] = str_replace('gid://shopify/TaxonomyCategory/', 'gid://shopify/ProductTaxonomyNode/', $value); // fallback
    } elseif (str_contains($value, 'gid://shopify/ProductTaxonomyNode/')) {
        // Convert ProductTaxonomyNode -> TaxonomyCategory first
        $candidates[] = str_replace('gid://shopify/ProductTaxonomyNode/', 'gid://shopify/TaxonomyCategory/', $value);
        $candidates[] = $value; // fallback
    } else {
        // Unknown format, try as-is
        $candidates[] = $value;
    }

    // Filter empties + unique
    $candidates = array_values(array_unique(array_filter($candidates, fn($v) => is_string($v) && trim($v) !== '')));

    return $candidates;
}

    private function isUnsupportedCategoryInputMessage(string $message): bool
    {
        $normalized = strtolower($message);
        return str_contains($normalized, 'field is not defined on productinput')
            || str_contains($normalized, 'unknown argument')
            || str_contains($normalized, 'expected type')
            || str_contains($normalized, 'provided invalid value for category')
            || str_contains($normalized, 'provided invalid value for productcategory')
            || str_contains($normalized, 'provided invalid value for producttaxonomynodeid');
    }

    private function findTaxonomyNodeIdByCategoryName(string $fullName): ?string
    {
        $normalizedTarget = strtolower(trim($fullName));
        if ($normalizedTarget === '') {
            return null;
        }

        $queries = [
            $this->taxonomyCategoriesQueryA(),
            $this->taxonomyCategoriesQueryB(),
            $this->taxonomyCategoriesQueryC(),
            $this->taxonomyCategoriesQueryD(),
        ];

        foreach ($queries as $query) {
            try {
                $data = $this->client->graphql($query, ['query' => $fullName]);
            } catch (\Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'cannot query field')) {
                    continue;
                }
                return null;
            }

            $nodes = data_get($data, 'taxonomy.categories.nodes', []);
            if (!is_array($nodes) || empty($nodes)) {
                $edges = data_get($data, 'taxonomy.categories.edges', []);
                if (is_array($edges)) {
                    $nodes = collect($edges)->map(fn ($edge) => is_array($edge) ? ($edge['node'] ?? null) : null)->filter()->values()->all();
                }
            }
            if (!is_array($nodes) || empty($nodes)) {
                $nodes = data_get($data, 'productTaxonomyNodes.nodes', []);
            }
            if (!is_array($nodes) || empty($nodes)) {
                $edges = data_get($data, 'productTaxonomyNodes.edges', []);
                if (is_array($edges)) {
                    $nodes = collect($edges)->map(fn ($edge) => is_array($edge) ? ($edge['node'] ?? null) : null)->filter()->values()->all();
                }
            }
            if (!is_array($nodes) || empty($nodes)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $name = strtolower(trim((string) ($node['fullName'] ?? ($node['name'] ?? ''))));
                $id = trim((string) ($node['id'] ?? ''));
                if ($name === '' || $id === '') {
                    continue;
                }

                if ($name === $normalizedTarget || str_ends_with($name, '> ' . $normalizedTarget)) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function taxonomyCategoriesQueryA(): string
    {
        return <<<'GQL'
query TaxonomyCategoriesA($query: String!) {
  taxonomy {
    categories(first: 25, query: $query) {
      nodes {
        id
        fullName
      }
    }
  }
}
GQL;
    }

    private function taxonomyCategoriesQueryB(): string
    {
        return <<<'GQL'
query TaxonomyCategoriesB($query: String!) {
  productTaxonomyNodes(first: 25, query: $query) {
    nodes {
      id
      fullName
    }
  }
}
GQL;
    }

    private function taxonomyCategoriesQueryC(): string
    {
        return <<<'GQL'
query TaxonomyCategoriesC($query: String!) {
  taxonomy {
    categories(first: 25, search: $query) {
      edges {
        node {
          id
          fullName
        }
      }
    }
  }
}
GQL;
    }

    private function taxonomyCategoriesQueryD(): string
    {
        return <<<'GQL'
query TaxonomyCategoriesD($query: String!) {
  productTaxonomyNodes(first: 25, search: $query) {
    edges {
      node {
        id
        fullName
      }
    }
  }
}
GQL;
    }

    private function looksUncategorizedCategory(string $value): bool
    {
        return str_contains(strtolower(trim($value)), 'uncategorized');
    }

    private function hasCategoryContext(?string $currentCategoryId, ?string $currentCategoryName): bool
    {
        $id = trim((string) ($currentCategoryId ?? ''));
        if ($id === '') {
            return false;
        }

        $name = trim((string) ($currentCategoryName ?? ''));
        return $name === '' || !$this->looksUncategorizedCategory($name);
    }

    private function findCategoryGidFromPeerProducts(Product $product, ?string $productType, ?string $preferredCategoryName): ?string
    {
        return null;
    }

    private function metafieldIdentifierFromHeader(string $header): ?array
    {
        if (!preg_match('/\\(product\\.metafields\\.([^.]+)\\.([^)]+)\\)/', $header, $matches)) {
            return null;
        }

        return [
            'namespace' => $matches[1],
            'key' => $matches[2],
        ];
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function fallbackMetafieldType(string $header, string $value): ?string
    {
        if ($header === HeaderStore::SEO_DEINDEX) {
            return 'boolean';
        }

        return 'single_line_text_field';
    }

    private function formatMetafieldValue(
        string $type,
        string $value,
        ?string $existingRawValue = null,
        ?string $lookup = null,
        ?string $header = null
    ): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if ($type === 'boolean') {
            $normalized = strtolower($trimmed);
            return in_array($normalized, ['1', 'true', 'yes', 'y'], true) ? 'true' : 'false';
        }

        if ($type === 'number_integer') {
            $number = $this->normalizeNumeric($trimmed);
            return $number === null ? null : (string) (int) $number;
        }

        if ($type === 'number_decimal') {
            $number = $this->normalizeNumeric($trimmed);
            return $number === null ? null : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
        }

        if (str_starts_with($type, 'list.') && str_ends_with($type, 'reference')) {
            if ($header !== null) {
                $trimmed = $this->normalizeListAcceptedValue($header, $trimmed) ?? $trimmed;
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = array_values(array_filter($decoded, fn ($item) => is_string($item) && str_starts_with($item, 'gid://')));
                return empty($items) ? null : json_encode($items);
            }

            $parts = str_contains($trimmed, ';')
                ? array_map('trim', explode(';', $trimmed))
                : array_map('trim', explode(',', $trimmed));
            $items = array_values(array_filter($parts, fn ($item) => str_starts_with($item, 'gid://')));
            if (!empty($items)) {
                return json_encode($items);
            }

            $fallback = $this->referenceFallbackFromExistingRaw($type, $existingRawValue);
            if ($fallback !== null) {
                return $fallback;
            }
            if ($lookup !== null) {
                return $this->resolveReferenceValueFromShopify($lookup, $type, $trimmed);
            }
            return null;
        }

        if (str_ends_with($type, 'reference')) {
            if ($header !== null) {
                $trimmed = $this->normalizeSingleAcceptedValue($header, $trimmed) ?? $trimmed;
            }
            if (str_starts_with($trimmed, 'gid://')) {
                return $trimmed;
            }
            $fallback = $this->referenceFallbackFromExistingRaw($type, $existingRawValue);
            if ($fallback !== null) {
                return $fallback;
            }
            if ($lookup !== null) {
                return $this->resolveReferenceValueFromShopify($lookup, $type, $trimmed);
            }
            return null;
        }

        if (str_starts_with($type, 'list.')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = array_values(array_filter(array_map('trim', $decoded)));
                return empty($items) ? null : json_encode($items);
            }

            $parts = str_contains($trimmed, ';')
                ? array_map('trim', explode(';', $trimmed))
                : array_map('trim', explode(',', $trimmed));
            $items = array_values(array_filter($parts, fn ($item) => $item !== ''));
            return empty($items) ? null : json_encode($items);
        }

        return $trimmed;
    }

    private function referenceFallbackFromExistingRaw(string $type, ?string $existingRawValue): ?string
    {
        if ($existingRawValue === null) {
            return null;
        }

        $trimmed = trim($existingRawValue);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($type, 'list.') && str_ends_with($type, 'reference')) {
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                return null;
            }

            $items = array_values(array_filter($decoded, fn ($item) => is_string($item) && str_starts_with($item, 'gid://')));
            return empty($items) ? null : json_encode($items);
        }

        if (str_ends_with($type, 'reference') && str_starts_with($trimmed, 'gid://')) {
            return $trimmed;
        }

        return null;
    }

    private function resolveReferenceValueFromShopify(string $lookup, string $type, string $raw): ?string
    {
        $tokens = str_contains($raw, ';')
            ? array_map('trim', explode(';', $raw))
            : array_map('trim', explode(',', $raw));
        $tokens = array_values(array_filter($tokens, fn ($item) => $item !== ''));

        if (empty($tokens)) {
            return null;
        }

        if (str_starts_with($type, 'list.') && str_ends_with($type, 'reference')) {
            $resolved = [];
            foreach ($tokens as $token) {
                $gid = $this->resolveReferenceTokenFromShopify($lookup, $token);
                if ($gid !== null) {
                    $resolved[] = $gid;
                }
            }
            $resolved = array_values(array_unique($resolved));
            return empty($resolved) ? null : json_encode($resolved);
        }

        return $this->resolveReferenceTokenFromShopify($lookup, $tokens[0]);
    }

    private function resolveReferenceTokenFromShopify(string $lookup, string $token): ?string
    {
        if (str_starts_with($token, 'gid://')) {
            return $token;
        }

        $map = $this->referenceLookupMap($lookup);
        if (empty($map)) {
            return null;
        }

        $normalized = $this->normalizeReferenceLabel($token);
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return $this->fuzzyReferenceMatch($map, $normalized);
    }

    /**
     * @return array<string, string>
     */
    private function referenceLookupMap(string $lookup): array
    {
        if (array_key_exists($lookup, $this->referenceLookupCache)) {
            return $this->referenceLookupCache[$lookup];
        }

        [$namespace, $key] = array_pad(explode('.', $lookup, 2), 2, null);
        if ($namespace === null || $key === null) {
            $this->referenceLookupCache[$lookup] = [];
            return [];
        }

        $types = $this->metaobjectTypesForMetafieldLookup($namespace, $key);
        $map = [];
        foreach ($types as $type) {
            foreach ($this->metaobjectsForType($type) as $metaobject) {
                $id = $metaobject['id'];
                $displayName = $metaobject['displayName'];
                $handle = $metaobject['handle'];

                if ($displayName !== '') {
                    $map[$this->normalizeReferenceLabel($displayName)] = $id;
                }
                if ($handle !== '') {
                    $map[$this->normalizeReferenceLabel($handle)] = $id;
                }

                foreach ($metaobject['fields'] as $field) {
                    if ($field['value'] !== '') {
                        $map[$this->normalizeReferenceLabel($field['value'])] = $id;
                    }
                }
            }
        }

        $this->referenceLookupCache[$lookup] = $map;
        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function metaobjectTypesForMetafieldLookup(string $namespace, string $key): array
    {
        $lookup = "{$namespace}.{$key}";
        if (array_key_exists($lookup, $this->metaobjectTypesByLookupCache)) {
            return $this->metaobjectTypesByLookupCache[$lookup];
        }

        $types = [];
        try {
            $data = $this->client->graphql($this->metafieldDefinitionQuery(), [
                'namespace' => $namespace,
                'key' => $key,
            ]);
        } catch (\Throwable) {
            $data = [];
        }

        $validations = data_get($data, 'metafieldDefinition.validations', []);
        $definitionIds = [];
        if (is_array($validations)) {
            foreach ($validations as $validation) {
                if (!is_array($validation)) {
                    continue;
                }
                $name = strtolower(trim((string) ($validation['name'] ?? '')));
                if (!str_contains($name, 'metaobject')) {
                    continue;
                }
                $value = trim((string) ($validation['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_string($item) && str_starts_with($item, 'gid://shopify/MetaobjectDefinition/')) {
                            $definitionIds[] = $item;
                        }
                    }
                    continue;
                }

                if (str_starts_with($value, 'gid://shopify/MetaobjectDefinition/')) {
                    $definitionIds[] = $value;
                }
            }
        }

        $definitionIds = array_values(array_unique($definitionIds));
        if (!empty($definitionIds)) {
            try {
                $nodeData = $this->client->graphql($this->metaobjectDefinitionTypesQuery(), [
                    'ids' => $definitionIds,
                ]);
            } catch (\Throwable) {
                $nodeData = [];
            }
            $nodes = data_get($nodeData, 'nodes', []);
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    $type = trim((string) data_get($node, 'type', ''));
                    if ($type !== '') {
                        $types[] = $type;
                    }
                }
            }
        }

        if (empty($types)) {
            $types[] = str_replace('-', '_', $key);
            $types[] = "shopify--{$key}";
        }

        $types = array_values(array_unique(array_filter($types)));
        $this->metaobjectTypesByLookupCache[$lookup] = $types;
        return $types;
    }

    /**
     * @return array<int, array{id:string,displayName:string,handle:string,fields:array<int, array{key:string,value:string}>}>
     */
    private function metaobjectsForType(string $type): array
    {
        if (array_key_exists($type, $this->metaobjectsByTypeCache)) {
            return $this->metaobjectsByTypeCache[$type];
        }

        try {
            $data = $this->client->graphql($this->metaobjectsByTypeQuery(), [
                'type' => $type,
            ]);
        } catch (\Throwable) {
            $data = [];
        }

        $nodes = data_get($data, 'metaobjects.nodes', []);
        if (!is_array($nodes)) {
            $this->metaobjectsByTypeCache[$type] = [];
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $fields = [];
            $fieldNodes = $node['fields'] ?? [];
            if (is_array($fieldNodes)) {
                foreach ($fieldNodes as $fieldNode) {
                    if (!is_array($fieldNode)) {
                        continue;
                    }
                    $fieldValue = trim((string) ($fieldNode['value'] ?? ''));
                    if ($fieldValue === '') {
                        continue;
                    }
                    $fields[] = [
                        'key' => trim((string) ($fieldNode['key'] ?? '')),
                        'value' => $fieldValue,
                    ];
                }
            }

            $result[] = [
                'id' => $id,
                'displayName' => trim((string) ($node['displayName'] ?? '')),
                'handle' => trim((string) ($node['handle'] ?? '')),
                'fields' => $fields,
            ];
        }

        $this->metaobjectsByTypeCache[$type] = $result;
        return $result;
    }

    private function normalizeReferenceLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['_', ' '], '-', $normalized);
        $normalized = preg_replace('/[^a-z0-9-]+/', '', $normalized) ?? '';
        $normalized = preg_replace('/-+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }

    /**
     * @param array<string, string> $map
     */
    private function fuzzyReferenceMatch(array $map, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $bestKey = null;
        foreach ($map as $key => $gid) {
            if (!str_contains($normalized, $key) && !str_contains($key, $normalized)) {
                continue;
            }

            if ($bestKey === null || strlen($key) > strlen($bestKey)) {
                $bestKey = $key;
            }
        }

        return $bestKey !== null ? ($map[$bestKey] ?? null) : null;
    }

    private function isReferenceType(string $type): bool
    {
        return str_ends_with($type, 'reference');
    }

    private function isSubtypeConstrainedShopifyMetafield(string $namespace, string $key): bool
    {
        if ($namespace !== 'shopify') {
            return false;
        }

        return in_array($key, [
            'age-group',
            'jewelry-type',
            'target-gender',
            'bracelet-design',
            'earring-design',
            'necklace-design',
            'jewelry-material',
            'material',
            'color-pattern',
        ], true);
    }

    private function normalizeMetafieldInputFromAccepted(string $header, string $value, string $type): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($type, 'list.')) {
            return $this->normalizeListAcceptedValue($header, $trimmed) ?? $trimmed;
        }

        if ($this->isReferenceType($type)) {
            return $this->normalizeSingleAcceptedValue($header, $trimmed) ?? $trimmed;
        }

        return $trimmed;
    }

    private function normalizeSingleAcceptedValue(string $header, string $value): ?string
    {
        $map = $this->acceptedValuesByHeader()[$header] ?? [];
        if (empty($map)) {
            return null;
        }

        $normalized = $this->normalizeReferenceLabel($value);
        if ($normalized === '') {
            return null;
        }

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        $fuzzy = $this->fuzzyAcceptedToken($map, $normalized);
        return $fuzzy;
    }

    private function normalizeListAcceptedValue(string $header, string $value): ?string
    {
        $map = $this->acceptedValuesByHeader()[$header] ?? [];
        if (empty($map)) {
            return null;
        }

        $parts = str_contains($value, ';')
            ? array_map('trim', explode(';', $value))
            : array_map('trim', explode(',', $value));
        $parts = array_values(array_filter($parts, fn ($item) => $item !== ''));
        if (empty($parts)) {
            return null;
        }

        $tokens = [];
        $seen = [];
        foreach ($parts as $part) {
            $normalized = $this->normalizeReferenceLabel($part);
            if ($normalized === '') {
                continue;
            }

            $canonical = $map[$normalized] ?? $this->fuzzyAcceptedToken($map, $normalized);
            if ($canonical === null) {
                $canonical = $normalized;
            }

            $canonicalNorm = $this->normalizeReferenceLabel($canonical);
            if ($canonicalNorm === '' || isset($seen[$canonicalNorm])) {
                continue;
            }

            $seen[$canonicalNorm] = true;
            $tokens[] = $canonical;
        }

        return empty($tokens) ? null : implode('; ', $tokens);
    }

    /**
     * @param array<string, string> $map
     */
    private function fuzzyAcceptedToken(array $map, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $best = null;
        foreach ($map as $key => $canonical) {
            if (!str_contains($normalized, $key) && !str_contains($key, $normalized)) {
                continue;
            }

            if ($best === null || strlen($key) > strlen($best)) {
                $best = $key;
            }
        }

        return $best !== null ? ($map[$best] ?? null) : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function acceptedValuesByHeader(): array
    {
        if ($this->acceptedValuesByHeaderCache !== null) {
            return $this->acceptedValuesByHeaderCache;
        }

        $path = $this->acceptedValuesCsvPath();
        if ($path === null || !is_file($path)) {
            $this->acceptedValuesByHeaderCache = [];
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->acceptedValuesByHeaderCache = [];
            return [];
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            $this->acceptedValuesByHeaderCache = [];
            return [];
        }

        $headers = array_map(function ($header): string {
            $value = trim((string) $header);
            return ltrim($value, "\xEF\xBB\xBF");
        }, $headers);

        $map = [];
        while (($row = fgetcsv($handle)) !== false) {
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $raw = trim((string) ($row[$index] ?? ''));
                if ($raw === '') {
                    continue;
                }

                $tokens = $this->tokenizeAcceptedCell($header, $raw);
                foreach ($tokens as $token) {
                    $normalized = $this->normalizeReferenceLabel($token);
                    if ($normalized === '' || isset($map[$header][$normalized])) {
                        continue;
                    }
                    $map[$header][$normalized] = $token;
                }
            }
        }

        fclose($handle);
        $this->acceptedValuesByHeaderCache = $map;
        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeAcceptedCell(string $header, string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if ($header === HeaderStore::TAGS || str_contains($header, '(product.metafields.shopify.')) {
            $parts = str_contains($trimmed, ';')
                ? array_map('trim', explode(';', $trimmed))
                : array_map('trim', explode(',', $trimmed));
            return array_values(array_filter($parts, fn ($item) => $item !== ''));
        }

        return [$trimmed];
    }

    private function acceptedValuesCsvPath(): ?string
    {
        $candidates = [
            storage_path('app/private/templates/shopify-accepted-values.csv'),
            storage_path('app/private/shoppify-acceeped-values.csv'),
            storage_path('app/private/shopify-accepted-values.csv'),
            storage_path('app/private/shopify-acceppted-values.csv'),
            storage_path('app/private/tempates/shopify-acceppted-values.csv'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function updateImages(Product $product, string $productId, array $rowDataList, array $details): array
    {
        $warnings = [];
        $existing = collect(data_get($details, 'images.nodes', []))
            ->pluck('url')
            ->filter()
            ->map(fn ($url) => trim((string) $url))
            ->all();

        foreach ($rowDataList as $rowData) {
            if (!is_array($rowData)) {
                continue;
            }

            $imageUrl = $this->nullIfEmpty($rowData[HeaderStore::IMAGE_SRC] ?? null);
            if ($imageUrl === null) {
                continue;
            }

            if (in_array($imageUrl, $existing, true)) {
                continue;
            }

            $data = $this->client->graphql($this->productCreateMediaMutation(), [
                'productId' => $productId,
                'media' => [
                    [
                        'originalSource' => $imageUrl,
                        'mediaContentType' => 'IMAGE',
                    ],
                ],
            ]);

            $payload = $data['productCreateMedia'] ?? null;
            if (!$payload) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => 'Missing productCreateMedia payload.',
                ];
                continue;
            }

            $errors = $payload['mediaUserErrors'] ?? [];
            if (!empty($errors)) {
                $messages = $this->formatUserErrors($errors, 'media');

                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => $messages !== '' ? $messages : 'Unknown media error.',
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return array{0:?ShopifyRow,1:array<int, array>,2:array<int, array>}
     */
    private function loadRows(Product $product): array
    {
        $rows = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->whereIn('row_type', ['product_primary', 'variant', 'image'])
            ->get();

        $primary = $rows->firstWhere('row_type', 'product_primary');
        $variantRows = $rows->where('row_type', 'variant')->values();
        $imageRows = $rows->where('row_type', 'image')->values();

        $variantData = $variantRows->isNotEmpty()
            ? $variantRows->map(fn (ShopifyRow $row) => $row->data ?? [])->all()
            : ($primary ? [$primary->data ?? []] : []);

        $imageData = $imageRows->isNotEmpty()
            ? $imageRows->map(fn (ShopifyRow $row) => $row->data ?? [])->all()
            : ($primary ? [$primary->data ?? []] : []);

        return [$primary, $variantData, $imageData];
    }

    private function valueFromRow(array $rowData, string $header, mixed $fallback = null): ?string
    {
        $value = $rowData[$header] ?? $fallback;
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $raw = is_scalar($value) ? (string) $value : null;
        if ($raw === null) {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
        if ($normalized === null || $normalized === '') {
            return null;
        }
        $parts = explode('.', $normalized);
        if (count($parts) > 2) {
            $normalized = array_shift($parts) . '.' . implode('', $parts);
        }
        return (float) $normalized;
    }

    /**
     * @return array<int, array{optionName:string,name:string}>
     */
    private function variantOptionValuesFromRow(array $rowData, array $variantNode): array
    {
        $optionValues = [];
        $pairs = [
            [HeaderStore::OPTION1_NAME, HeaderStore::OPTION1_VALUE, 0],
            [HeaderStore::OPTION2_NAME, HeaderStore::OPTION2_VALUE, 1],
            [HeaderStore::OPTION3_NAME, HeaderStore::OPTION3_VALUE, 2],
        ];

        foreach ($pairs as [$nameHeader, $valueHeader, $index]) {
            $value = $this->valueFromRow($rowData, $valueHeader, null);
            if ($value === null) {
                continue;
            }

            $name = $this->valueFromRow($rowData, $nameHeader, null)
                ?? $this->nullIfEmpty((string) data_get($variantNode, "selectedOptions.{$index}.name"));
            if ($name === null) {
                continue;
            }

            $optionValues[] = [
                'optionName' => $name,
                'name' => $value,
            ];
        }

        return $optionValues;
    }

    private function mapWeightUnit(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            'kg', 'kilogram', 'kilograms' => 'KILOGRAMS',
            'oz', 'ounce', 'ounces' => 'OUNCES',
            'lb', 'lbs', 'pound', 'pounds' => 'POUNDS',
            default => 'GRAMS',
        };
    }

    private function weightFromGrams(float $grams, string $unit): float
    {
        return match ($unit) {
            'KILOGRAMS' => $grams / 1000,
            'OUNCES' => $grams / 28.3495,
            'POUNDS' => $grams / 453.592,
            default => $grams,
        };
    }

    /**
     * @param array<int, array{field?:array|string|null,message?:string|null}> $errors
     */
    private function formatUserErrors(array $errors, string $fallbackField = 'input'): string
    {
        return collect($errors)
            ->map(function (array $error) use ($fallbackField): string {
                $field = $error['field'] ?? null;
                $fieldPath = $fallbackField;
                if (is_array($field)) {
                    $fieldPath = implode('.', $field);
                } elseif (is_string($field) && $field !== '') {
                    $fieldPath = $field;
                }
                $message = $error['message'] ?? 'Unknown error';
                return "{$fieldPath}: {$message}";
            })
            ->filter()
            ->implode('; ');
    }

    /**
     * @param array<int, array> $variantRows
     * @param array<int, array> $imageRows
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function buildSyncCoverageWarnings(
        Product $product,
        array $primaryData,
        array $variantRows,
        array $imageRows
    ): array {
        $warnings = [];

        $primaryHeaders = array_keys($primaryData);
        foreach ($primaryHeaders as $header) {
            $value = $primaryData[$header] ?? null;
            if ($this->isEmptyValue($value)) {
                continue;
            }

            if ($this->isMetafieldHeader($header)) {
                continue;
            }

            $supported = [
                HeaderStore::TITLE,
                HeaderStore::BODY_HTML,
                HeaderStore::VENDOR,
                HeaderStore::TAGS,
                HeaderStore::TYPE,
                HeaderStore::STATUS,
                HeaderStore::PRODUCT_CATEGORY,
                HeaderStore::SEO_TITLE,
                HeaderStore::SEO_DESCRIPTION,
            ];

            if (in_array($header, $supported, true)) {
                continue;
            }

            if ($header === HeaderStore::PUBLISHED) {
                continue;
            }

            if (in_array($header, [HeaderStore::GOOGLE_SHOPPING_GENDER, HeaderStore::GOOGLE_SHOPPING_AGE_GROUP], true)) {
                continue;
            }
        }

        foreach ($variantRows as $rowData) {
            if (!is_array($rowData)) {
                continue;
            }

            $variantHeaders = array_keys($rowData);
            foreach ($variantHeaders as $header) {
                $value = $rowData[$header] ?? null;
                if ($this->isEmptyValue($value)) {
                    continue;
                }

                $supported = [
                    HeaderStore::VARIANT_SKU,
                    HeaderStore::VARIANT_PRICE,
                    HeaderStore::VARIANT_COMPARE_AT,
                    HeaderStore::VARIANT_BARCODE,
                    HeaderStore::VARIANT_GRAMS,
                    HeaderStore::VARIANT_WEIGHT_UNIT,
                    HeaderStore::OPTION1_VALUE,
                    HeaderStore::OPTION2_VALUE,
                    HeaderStore::OPTION3_VALUE,
                ];

                if (in_array($header, $supported, true)) {
                    continue;
                }

                if (in_array($header, [HeaderStore::OPTION1_NAME, HeaderStore::OPTION2_NAME, HeaderStore::OPTION3_NAME], true)) {
                    continue;
                }
            }
        }

        foreach ($imageRows as $rowData) {
            if (!is_array($rowData)) {
                continue;
            }

            $imageHeaders = array_keys($rowData);
            foreach ($imageHeaders as $header) {
                $value = $rowData[$header] ?? null;
                if ($this->isEmptyValue($value)) {
                    continue;
                }

                if ($header === HeaderStore::IMAGE_SRC) {
                    continue;
                }

                if (in_array($header, [HeaderStore::IMAGE_ALT_TEXT, HeaderStore::IMAGE_POSITION], true)) {
                    continue;
                }
            }
        }

        return $warnings;
    }

    private function isMetafieldHeader(string $header): bool
    {
        return (bool) preg_match('/\\(product\\.metafields\\.([^.]+)\\.([^)]+)\\)/', $header);
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '';
    }
}
