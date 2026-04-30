<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\RequiredField;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductPartialApprovalService
{
    public function hasApprovedTitleForCurrentVersion(Product $product): bool
    {
        return ProductPartialApprovalRequest::query()
            ->where('product_id', $product->id)
            ->where('approval_version', $product->approval_version)
            ->where('status', ProductPartialApprovalRequest::STATUS_APPROVED)
            ->whereJsonContains('scopes', ProductShopifyUpdater::SYNC_SCOPE_PRODUCT)
            ->whereJsonContains('core_fields', ProductShopifyUpdater::CORE_FIELD_TITLE)
            ->exists();
    }

    public function actionableRequestsQuery(int $userId): Builder
    {
        return ProductPartialApprovalRequest::query()
            ->with(['product', 'requester', 'targetApprover'])
            ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
            ->where('requested_by', '!=', $userId)
            ->where(function (Builder $query) use ($userId): void {
                $query->whereNull('target_approver_id')
                    ->orWhere('target_approver_id', $userId);
            })
            ->whereHas('product', function (Builder $query): void {
                $query->whereColumn('products.approval_version', 'product_partial_approval_requests.approval_version');
            });
    }

    public function eligibleApproversQuery(?int $excludeUserId = null): Builder
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereHas('roles', function (Builder $roleQuery): void {
                $roleQuery->whereIn('name', [
                    RolesEnum::SuperAdmin->value,
                    RolesEnum::Admin->value,
                    RolesEnum::SeoReviewer->value,
                ]);
            })
            ->orderBy('name');

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $query->whereKeyNot($excludeUserId);
        }

        return $query;
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
     *   inactive_products:array<int, array{id:int,title:string,status:string}>,
     *   skipped_existing:int,
     *   existing_products:array<int, array{id:int,title:string}>,
     *   skipped_invalid:int,
     *   invalid_products:array<int, array{id:int,title:string,errors:array<int,string>}>,
     *   requested_products:array<int,int>,
     *   request_batch_id:string|null
     * }
     */
    public function request(
        Collection $products,
        int $userId,
        array $scopes,
        array $coreFields,
        ?int $targetApproverId = null,
        ?string $requestNote = null,
    ): array {
        $summary = [
            'requested' => 0,
            'skipped_inactive' => 0,
            'inactive_products' => [],
            'skipped_existing' => 0,
            'existing_products' => [],
            'skipped_invalid' => 0,
            'invalid_products' => [],
            'requested_products' => [],
            'request_batch_id' => null,
        ];

        $targetApproverId = $this->normalizeTargetApproverId($targetApproverId, $userId);
        $requestNote = $this->normalizeRequestNote($requestNote);
        $requestBatchId = (string) Str::uuid();

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            if (!$this->isActiveProduct($product)) {
                $summary['skipped_inactive']++;
                $summary['inactive_products'][] = [
                    'id' => (int) $product->id,
                    'title' => trim((string) ($product->title ?? '')) ?: ('Product #' . $product->id),
                    'status' => trim((string) ($product->status ?? '')) ?: 'unknown',
                ];
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
                $summary['existing_products'][] = [
                    'id' => (int) $product->id,
                    'title' => trim((string) ($product->title ?? '')) ?: ('Product #' . $product->id),
                ];
                continue;
            }

            ProductPartialApprovalRequest::create([
                'product_id' => $product->id,
                'approval_version' => $product->approval_version,
                'requested_by' => $userId,
                'request_batch_id' => $requestBatchId,
                'target_approver_id' => $targetApproverId,
                'status' => ProductPartialApprovalRequest::STATUS_PENDING,
                'scopes' => $scopes,
                'core_fields' => $coreFields,
                'request_note' => $requestNote,
            ]);

            $summary['requested']++;
            $summary['requested_products'][] = (int) $product->id;
            $summary['request_batch_id'] = $requestBatchId;
        }

        return $summary;
    }

    /**
     * @param Collection<int, Product> $products
     * @return array{
     *   approved:int,
     *   skipped:int,
     *   skipped_no_pending:int,
     *   skipped_own_request:int,
     *   skipped_targeted:int
     * }
     */
    public function approve(Collection $products, int $userId): array
    {
        $approved = 0;
        $skipped = 0;
        $skippedNoPending = 0;
        $skippedOwnRequest = 0;
        $skippedTargeted = 0;

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
                $skippedNoPending++;
                continue;
            }

            foreach ($requests as $request) {
                if ((int) $request->requested_by === $userId) {
                    $skipped++;
                    $skippedOwnRequest++;
                    continue;
                }

                if ($request->target_approver_id !== null && (int) $request->target_approver_id !== $userId) {
                    $skipped++;
                    $skippedTargeted++;
                    continue;
                }

                $request->forceFill([
                    'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ])->save();

                $this->syncApprovedHandleWhenTitleApproved($request);
                $approved++;
            }
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'skipped_no_pending' => $skippedNoPending,
            'skipped_own_request' => $skippedOwnRequest,
            'skipped_targeted' => $skippedTargeted,
        ];
    }

    /**
     * @param Collection<int, ProductPartialApprovalRequest> $requests
     * @return array{
     *   approved:int,
     *   skipped:int,
     *   skipped_not_pending:int,
     *   skipped_stale:int,
     *   skipped_own_request:int,
     *   skipped_targeted:int
     * }
     */
    public function approveRequests(Collection $requests, int $userId): array
    {
        $approved = 0;
        $skipped = 0;
        $skippedNotPending = 0;
        $skippedStale = 0;
        $skippedOwnRequest = 0;
        $skippedTargeted = 0;

        foreach ($requests as $request) {
            if (!$request instanceof ProductPartialApprovalRequest) {
                continue;
            }

            if ($request->status !== ProductPartialApprovalRequest::STATUS_PENDING) {
                $skipped++;
                $skippedNotPending++;
                continue;
            }

            $product = $request->product;
            if (!$product instanceof Product || (int) $request->approval_version !== (int) ($product->approval_version ?? 0)) {
                $skipped++;
                $skippedStale++;
                continue;
            }

            if ((int) $request->requested_by === $userId) {
                $skipped++;
                $skippedOwnRequest++;
                continue;
            }

            if ($request->target_approver_id !== null && (int) $request->target_approver_id !== $userId) {
                $skipped++;
                $skippedTargeted++;
                continue;
            }

            $request->forceFill([
                'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            $this->syncApprovedHandleWhenTitleApproved($request);
            $approved++;
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'skipped_not_pending' => $skippedNotPending,
            'skipped_stale' => $skippedStale,
            'skipped_own_request' => $skippedOwnRequest,
            'skipped_targeted' => $skippedTargeted,
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

            if (
                in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $requestedScopes, true)
                && in_array(ProductShopifyUpdater::CORE_FIELD_HANDLE, $requestedCoreFields, true)
                && in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, (array) ($request->scopes ?? []), true)
                && in_array(ProductShopifyUpdater::CORE_FIELD_TITLE, (array) ($request->core_fields ?? []), true)
            ) {
                $allowedScopes[ProductShopifyUpdater::SYNC_SCOPE_PRODUCT] = ProductShopifyUpdater::SYNC_SCOPE_PRODUCT;
                $allowedCoreFields[ProductShopifyUpdater::CORE_FIELD_HANDLE] = ProductShopifyUpdater::CORE_FIELD_HANDLE;
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

    private function syncApprovedHandleWhenTitleApproved(ProductPartialApprovalRequest $request): void
    {
        $scopes = is_array($request->scopes) ? $request->scopes : [];
        $coreFields = is_array($request->core_fields) ? $request->core_fields : [];

        if (
            !in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $scopes, true)
            || !in_array(ProductShopifyUpdater::CORE_FIELD_TITLE, $coreFields, true)
        ) {
            return;
        }

        $product = $request->product;
        if (!$product instanceof Product) {
            return;
        }

        app(ProductHandleService::class)->syncApprovedHandleToCurrentTitle($product);
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

    public function isHandleAffectingRequest(ProductPartialApprovalRequest $request): bool
    {
        $scopes = is_array($request->scopes) ? $request->scopes : [];
        $coreFields = is_array($request->core_fields) ? $request->core_fields : [];

        return in_array(ProductShopifyUpdater::SYNC_SCOPE_PRODUCT, $scopes, true)
            && (
                in_array(ProductShopifyUpdater::CORE_FIELD_HANDLE, $coreFields, true)
                || in_array(ProductShopifyUpdater::CORE_FIELD_TITLE, $coreFields, true)
            );
    }

    public function batchLabel(?string $batchId): string
    {
        $value = trim((string) $batchId);

        if ($value === '') {
            return 'Legacy';
        }

        return strtoupper(substr(str_replace('-', '', $value), 0, 8));
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

    private function normalizeTargetApproverId(?int $targetApproverId, int $requesterId): ?int
    {
        if ($targetApproverId === null || $targetApproverId <= 0 || $targetApproverId === $requesterId) {
            return null;
        }

        return $this->eligibleApproversQuery($requesterId)
            ->whereKey($targetApproverId)
            ->exists()
            ? $targetApproverId
            : null;
    }

    private function normalizeRequestNote(?string $requestNote): ?string
    {
        $value = trim((string) $requestNote);

        return $value === '' ? null : $value;
    }
}
