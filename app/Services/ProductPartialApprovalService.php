<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\RequiredField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductPartialApprovalService
{
    public function actionableRequestsQuery(int $userId): Builder
    {
        return ProductPartialApprovalRequest::query()
            ->with(['product', 'requester'])
            ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
            ->where('requested_by', '!=', $userId)
            ->whereHas('product', function (Builder $query): void {
                $query->whereColumn('products.approval_version', 'product_partial_approval_requests.approval_version');
            });
    }

    /**
     * @param array<int, string>|null $scopes
     * @param array<int, string>|null $coreFields
     * @param array<int, string>|null $metafieldFields
     * @return array{scopes:array<int, string>,core_fields:array<int, string>}
     */
    public function normalizeSelections(?array $scopes, ?array $coreFields, ?array $metafieldFields = null): array
    {
        $normalizedScopes = array_values(array_unique(array_filter(array_map(
            'strval',
            $scopes ?? []
        ), fn (string $scope): bool => in_array($scope, ProductShopifyUpdater::availableSyncScopes(), true))));

        $normalizedCoreFields = array_values(array_unique(array_filter(array_map(
            'strval',
            $coreFields ?? []
        ), fn (string $field): bool => in_array($field, ProductShopifyUpdater::availableProductCoreFields(), true))));

        $normalizedMetafieldFields = array_values(array_unique(array_filter(array_map(
            'strval',
            $metafieldFields ?? []
        ), fn (string $field): bool => in_array($field, ProductShopifyUpdater::availableMetafieldFields(), true))));

        if (!in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $normalizedScopes, true)) {
            $normalizedCoreFields = [];
        }

        if (!in_array(ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS, $normalizedScopes, true)) {
            $normalizedMetafieldFields = [];
        }

        return [
            'scopes' => $normalizedScopes,
            'core_fields' => array_values(array_unique(array_merge($normalizedCoreFields, $normalizedMetafieldFields))),
        ];
    }

    public function isActiveProduct(Product $product): bool
    {
        return strtolower(trim((string) ($product->status ?? ''))) === 'active';
    }

    /**
     * @param Collection<int, Product> $products
     * @param array<int, string> $scopes
     * @param array<int, string> $coreFields
     * @return array{
     *   requested:int,
     *   skipped_inactive:int,
     *   skipped_existing:int,
     *   skipped_invalid:int,
     *   invalid_products:array<int, array{id:int,title:string,errors:array<int,string>}>,
     *   requested_products:array<int,int>
     * }
     */
    public function request(
        Collection $products,
        int $userId,
        array $scopes,
        array $coreFields,
    ): array {
        $summary = [
            'requested' => 0,
            'skipped_inactive' => 0,
            'skipped_existing' => 0,
            'skipped_invalid' => 0,
            'invalid_products' => [],
            'requested_products' => [],
        ];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            if (!$this->isActiveProduct($product)) {
                $summary['skipped_inactive']++;
                continue;
            }

            $invalidErrors = $this->selectedFieldErrors($product, $scopes, $coreFields);
            if ($invalidErrors !== []) {
                $summary['skipped_invalid']++;
                $summary['invalid_products'][] = [
                    'id' => (int) $product->id,
                    'title' => trim((string) ($product->title ?? '')) ?: ('Product #' . $product->id),
                    'errors' => $invalidErrors,
                ];
                continue;
            }

            $existing = ProductPartialApprovalRequest::query()
                ->where('product_id', $product->id)
                ->where('approval_version', $product->approval_version)
                ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
                ->whereJsonContains('scopes', $scopes)
                ->exists();

            if ($existing) {
                $summary['skipped_existing']++;
                continue;
            }

            ProductPartialApprovalRequest::create([
                'product_id' => $product->id,
                'approval_version' => $product->approval_version,
                'requested_by' => $userId,
                'status' => ProductPartialApprovalRequest::STATUS_PENDING,
                'scopes' => $scopes,
                'core_fields' => $coreFields,
            ]);

            $summary['requested']++;
            $summary['requested_products'][] = (int) $product->id;
        }

        return $summary;
    }

    /**
     * @param Collection<int, Product> $products
     * @return array{approved:int,skipped:int}
     */
    public function approve(Collection $products, int $userId): array
    {
        $approved = 0;
        $skipped = 0;

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $requests = ProductPartialApprovalRequest::query()
                ->where('product_id', $product->id)
                ->where('approval_version', $product->approval_version)
                ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
                ->get();

            if ($requests->isEmpty()) {
                $skipped++;
                continue;
            }

            foreach ($requests as $request) {
                if ((int) $request->requested_by === $userId) {
                    $skipped++;
                    continue;
                }

                $request->forceFill([
                    'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ])->save();

                $approved++;
            }
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param Collection<int, ProductPartialApprovalRequest> $requests
     * @return array{approved:int,skipped:int}
     */
    public function approveRequests(Collection $requests, int $userId): array
    {
        $approved = 0;
        $skipped = 0;

        foreach ($requests as $request) {
            if (!$request instanceof ProductPartialApprovalRequest) {
                continue;
            }

            if ($request->status !== ProductPartialApprovalRequest::STATUS_PENDING) {
                $skipped++;
                continue;
            }

            $product = $request->product;
            if (!$product instanceof Product || (int) $request->approval_version !== (int) ($product->approval_version ?? 0)) {
                $skipped++;
                continue;
            }

            if ((int) $request->requested_by === $userId) {
                $skipped++;
                continue;
            }

            $request->forceFill([
                'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            $approved++;
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<int, string> $requestedScopes
     * @param array<int, string> $requestedCoreFields
     * @return array{scopes:array<int,string>,core_fields:array<int,string>}
     */
    public function allowedSelections(Product $product, array $requestedScopes, array $requestedCoreFields): array
    {
        $approved = ProductPartialApprovalRequest::query()
            ->where('product_id', $product->id)
            ->where('approval_version', $product->approval_version)
            ->where('status', ProductPartialApprovalRequest::STATUS_APPROVED)
            ->get();

        $allowedScopes = [];
        $allowedCoreFields = [];

        foreach ($approved as $request) {
            foreach (($request->scopes ?? []) as $scope) {
                if (is_string($scope) && in_array($scope, $requestedScopes, true)) {
                    $allowedScopes[$scope] = $scope;
                }
            }

            foreach (($request->core_fields ?? []) as $field) {
                if (is_string($field) && in_array($field, $requestedCoreFields, true)) {
                    $allowedCoreFields[$field] = $field;
                }
            }
        }

        if (!isset($allowedScopes[ProductShopifyUpdater::SYNC_SCOPE_PRODUCT])) {
            $allowedCoreFields = [];
        }

        return [
            'scopes' => array_values($allowedScopes),
            'core_fields' => array_values($allowedCoreFields),
        ];
    }

    public function pendingStatusLabel(Product $product): string
    {
        $pending = ProductPartialApprovalRequest::query()
            ->where('product_id', $product->id)
            ->where('approval_version', $product->approval_version)
            ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
            ->count();

        if ($pending <= 0) {
            return 'None';
        }

        return "Pending {$pending}";
    }

    /**
     * @param array<int, string> $scopes
     * @param array<int, string> $coreFields
     * @return array<int, string>
     */
    public function requestFieldLabels(array $scopes, array $coreFields): array
    {
        $labels = [];

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $scopes, true)) {
            foreach ($coreFields as $field) {
                if (in_array($field, ProductShopifyUpdater::availableProductCoreFields(), true)) {
                    $labels[] = ProductShopifyUpdater::productCoreFieldLabels()[$field] ?? $field;
                }
            }
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_SEO, $scopes, true)) {
            $labels[] = 'SEO title and description';
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS, $scopes, true)) {
            foreach ($coreFields as $field) {
                if (in_array($field, ProductShopifyUpdater::availableMetafieldFields(), true)) {
                    $labels[] = ProductShopifyUpdater::metafieldFieldLabels()[$field] ?? $field;
                }
            }
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_VARIANTS, $scopes, true)) {
            $labels[] = ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_VARIANTS];
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGES, $scopes, true)) {
            $labels[] = ProductShopifyUpdater::syncScopeLabels()[ProductShopifyUpdater::SYNC_SCOPE_IMAGES];
        }

        return array_values(array_unique(array_filter(array_map('strval', $labels))));
    }

    /**
     * @param array<int, string> $scopes
     * @param array<int, string> $coreFields
     * @return array<int, string>
     */
    public function selectedFieldErrors(Product $product, array $scopes, array $coreFields): array
    {
        $errorFields = $product->error_fields;
        if (!is_array($errorFields) || $errorFields === []) {
            return [];
        }

        $selectedLabels = $this->selectedLabels($scopes, $coreFields);
        if ($selectedLabels === []) {
            return [];
        }

        $selectedLower = array_map('strtolower', $selectedLabels);
        $matches = [];

        foreach ($errorFields as $error) {
            $label = $this->errorLabel($error);
            if ($label === null) {
                continue;
            }

            if (in_array(strtolower($label), $selectedLower, true)) {
                $matches[] = $label;
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param array<int, string> $scopes
     * @param array<int, string> $coreFields
     * @return array<int, string>
     */
    private function selectedLabels(array $scopes, array $coreFields): array
    {
        $labels = [];
        $coreFieldLabels = ProductShopifyUpdater::coreFieldLabels();
        $rowAttributeMap = [
            ProductShopifyUpdater::CORE_FIELD_COLOR => HeaderStore::COLOR_METAFIELD,
            ProductShopifyUpdater::CORE_FIELD_MATERIALS_AND_DIMENSIONS => HeaderStore::MATERIALS_AND_DIMENSIONS,
            ProductShopifyUpdater::CORE_FIELD_JEWELRY_MATERIAL => HeaderStore::JEWELRY_MATERIAL,
            ProductShopifyUpdater::CORE_FIELD_JEWELRY_TYPE => HeaderStore::JEWELRY_TYPE,
            ProductShopifyUpdater::CORE_FIELD_TARGET_GENDER => HeaderStore::TARGET_GENDER,
            ProductShopifyUpdater::CORE_FIELD_AGE_GROUP => HeaderStore::AGE_GROUP,
            ProductShopifyUpdater::CORE_FIELD_BRACELET_DESIGN => HeaderStore::BRACELET_DESIGN,
            ProductShopifyUpdater::CORE_FIELD_PATTERN_CATEGORY => HeaderStore::PATTERN_CATEGORY,
            ProductShopifyUpdater::CORE_FIELD_PRODUCT_METALS => HeaderStore::PRODUCT_METALS,
            ProductShopifyUpdater::CORE_FIELD_SIBLINGS => HeaderStore::SIBLINGS,
            ProductShopifyUpdater::CORE_FIELD_COMPLEMENTARY_PRODUCTS => HeaderStore::COMPLEMENTARY_PRODUCTS,
            ProductShopifyUpdater::CORE_FIELD_UVP_SHORT_PARAGRAPH => HeaderStore::UVP_SHORT_PARAGRAPH,
            ProductShopifyUpdater::CORE_FIELD_SEO_DEINDEX => HeaderStore::SEO_DEINDEX,
        ];

        foreach ($coreFields as $field) {
            foreach ($this->requiredLabelsForSourceAndAttribute('product', $field) as $label) {
                $labels[] = $label;
            }

            if (isset($rowAttributeMap[$field])) {
                foreach ($this->requiredLabelsForSourceAndAttribute('row', $rowAttributeMap[$field]) as $label) {
                    $labels[] = $label;
                }
            }

            $label = $coreFieldLabels[$field] ?? $field;
            $labels[] = $label;
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_SEO, $scopes, true)) {
            $labels[] = 'SEO title';
            $labels[] = 'SEO description';
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_VARIANTS, $scopes, true)) {
            foreach ($this->requiredLabelsForSource('variant') as $label) {
                $labels[] = $label;
            }
        }

        if (in_array(ProductShopifyUpdater::SYNC_SCOPE_IMAGES, $scopes, true)) {
            foreach ($this->requiredLabelsForSource('image') as $label) {
                $labels[] = $label;
            }
        }

        return array_values(array_unique(array_filter($labels, fn (string $label): bool => trim($label) !== '')));
    }

    /**
     * @return array<int, string>
     */
    private function requiredLabelsForSource(string $source): array
    {
        return RequiredField::query()
            ->where('required', true)
            ->where('source', $source)
            ->pluck('label')
            ->filter(fn ($label) => is_string($label) && trim($label) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function requiredLabelsForSourceAndAttribute(string $source, string $attribute): array
    {
        return RequiredField::query()
            ->where('required', true)
            ->where('source', $source)
            ->where('attribute', $attribute)
            ->pluck('label')
            ->filter(fn ($label) => is_string($label) && trim($label) !== '')
            ->values()
            ->all();
    }

    private function errorLabel(mixed $error): ?string
    {
        $value = trim((string) $error);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'missing:')) {
            return trim(substr($value, strlen('missing:')));
        }

        return null;
    }
}
