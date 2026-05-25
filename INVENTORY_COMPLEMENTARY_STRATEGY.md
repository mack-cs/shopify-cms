# Inventory And Complementary Products Strategy

## Goal

Make this app the source of truth for product inventory and product sellability, then use that local truth to keep Shopify complementary products valid at all times.

The two business outcomes are:

1. Inventory changes must be editable and syncable without the 2-approval product workflow.
2. Complementary products sent to Shopify must exclude products that are not sellable, while preserving the saved ranking/order and still filling Shopify's target of 3 whenever possible.

## Current State In Code

### Inventory

- Inventory currently lives on `variants.inventory_qty`.
- Shopify inventory sync already exists in `app/Services/ProductShopifyUpdater.php`.
- Inventory is pushed through `updateVariantAndInventory()` using `inventorySetQuantities`.
- Variant edits currently bump `products.approval_version` via `app/Observers/VariantObserver.php`.

### Complementary Products

- Local complementary ordering comes from:
  - `ShopifyRow` primary row value for `HeaderStore::COMPLEMENTARY_PRODUCTS`
  - fallback Shopify metafield value
- Shopify complementary references are sent from `ProductShopifyUpdater`.
- Current trimming logic is in:
  - `ProductShopifyUpdater::limitReferenceItemsForLookup()`
  - `ProductShopifyUpdater::filterActiveProductReferenceGids()`
- Current audit logic is in:
  - `app/Services/ComplementaryProductAuditService.php`
- Current eligibility for complementary products is effectively:
  - product status is `active`
  - and on Shopify the referenced product has a sellable variant

### Root Cause Of The Issue

The current local complementary sync filter only excludes products by local `products.status`.

It does **not** exclude products whose local inventory is `0` or below while status remains `active`.

That creates this mismatch:

1. Local app still treats the product as eligible.
2. Product gets sent as a complementary product.
3. Shopify does not display it because it is not sellable there.

## Proposed Design

## 1. Introduce A Separate Inventory Workflow

Inventory should not live inside the product approval workflow.

Inventory operations should:

- not require 2 approvals
- be editable by a limited set of users
- update local variant quantity and sellability state
- sync directly to Shopify
- trigger complementary-product re-evaluation

### Confirmed UI Direction

Add a dedicated Filament resource, for example:

- `InventoryResource`
- navigation group: `Catalog` or new group `Operations`
- visible only to users explicitly granted inventory/status permissions
- permission assignment itself remains a `SuperAdmin`-only responsibility

List columns:

- Product ID
- Product title
- Handle
- Shopify status
- Variant SKU
- Local quantity
- Sellable state
- Last synced at
- Sync state / dirty flag

Actions:

- modal-based quantity edit without leaving the list page
- modal-based status update without leaving the list page
- bulk sync inventory to Shopify
- targeted complementary refresh triggered only after successful Shopify push

### Why A Separate Resource Instead Of Reusing Product Edit

- inventory changes are operational, not editorial
- they should not bump the normal product approval workflow
- the UI can stay small and purpose-built for fast stock work
- users should not need to open the full product details page just to change stock or status

### Draft UI Impact

The current inventory field shown in the draft workflow should become read-only.

Reason:

- inventory is moving into an operational workflow
- drafts should show inventory for visibility only
- stock changes must be performed from the dedicated inventory section

## 2. Add A Shared Sellability Rule In Local Code

Create one service that decides whether a product is eligible for complementary use based on local state.

Suggested new service:

```php
final class ProductSellabilityService
{
    public function isLocallySellable(Product $product): bool {}

    public function primaryVariantQuantity(Product $product): ?int {}

    public function eligibilityReason(Product $product): ?string {}
}
```

### Confirmed Local Rule

A product is locally sellable when:

- `products.status` is `active`
- and at least one relevant variant has `inventory_qty > 0`

Optional future extension:

- support `inventory not tracked`
- support channel/location-specific inventory logic

### Variant Handling

This workflow must support products with variants.

That means:

- inventory editing must work at variant level
- the inventory list should show the variant SKU being edited
- complementary eligibility must consider variant-level sellability, not only product-level existence

## 3. Stop Inventory Changes From Bumping Product Approval

Right now `VariantObserver::updating()` bumps `products.approval_version` for all meaningful variant edits.

That is correct for pricing/options edits, but wrong for operational inventory changes.

### Recommended Observer Change

Split inventory-only dirty fields from approval-managed fields.

Example:

```php
private function isInventoryOnlyChange(Variant $variant): bool
{
    $dirty = array_keys($variant->getDirty());

    $meaningful = array_diff($dirty, [
        'updated_at',
        'created_at',
        'sync_state',
        'local_dirty',
        'last_shopify_seen_at',
        'last_synced_at',
    ]);

    return $meaningful !== []
        && count(array_diff($meaningful, ['inventory_qty'])) === 0;
}
```

Then:

- inventory-only changes should **not** bump `approval_version`
- pricing / option / barcode / weight changes still should

This is the main separation needed to keep inventory independent from product approvals.

## 4. Add An Explicit Inventory Sync Path

Do not overload the current general product sync action.

Create a dedicated service, for example:

```php
final class ProductInventorySyncService
{
    public function syncProducts(Collection $products, ?int $userId = null): array {}

    public function syncProduct(Product $product, ?int $userId = null): array {}
}
```

This service should:

1. load the affected local variant or variants
2. resolve Shopify product and variant IDs
3. push local quantity to Shopify using the existing inventory mutation flow
4. optionally push local product status when an authorized user explicitly changed status
5. mark an inventory sync timestamp / batch ID
6. after successful push, trigger complementary refresh for impacted parent products only

### Important Separation

This service should not call:

- partial approvals
- product field approval checks
- draft sync rules

It should be operational only.

## 5. Confirmed Product Status Rule

Use these meanings:

- `active` = eligible to sell and display
- `draft` = intentionally unavailable, should not be used as complementary

Inventory screen actions become:

- quantity `<= 0` does **not** auto-change status
- quantity `> 0` does **not** auto-change status
- users must explicitly change status themselves
- status updates and inventory updates are separate permissions
- status should still be readable from Shopify and pushable to Shopify when changed locally by an authorized user

### Locked Decision

Do **not** automatically force `status = draft` when quantity hits `0`.

Reason:

- today the app already allows active-but-sold-out products
- status must remain a deliberate business decision
- complementary eligibility can still exclude zero-stock products without changing publication state

Instead:

- complementary eligibility should depend on sellability
- storefront publication should remain an explicit status choice

## 5A. Explicit Permissions

Two new permissions are required:

- `inventory.update`
- `inventory_status.update`

These must be independent:

- a user may have only inventory update permission
- a user may have only status update permission
- a user may have both

### Assignment Rule

These permissions must only be assignable by `SuperAdmin`.

That means:

- `SuperAdmin` can grant or revoke them
- ordinary admins must not be able to assign them

### Implementation Direction

The app already uses Spatie permissions and a central permission enum.

Expected touch points:

- `app/Enums/PermissionEnum.php`
- `database/seeders/RoleSeeder.php`
- `app/Filament/Resources/UserResource.php`

Recommended guardrails:

- only `SuperAdmin` can edit permission assignment UI
- if a non-superadmin can still manage users generally, they must not be able to grant these permissions
- inventory actions in the new resource must check these permissions directly

## 6. Complementary Selection Must Use Sellable Fallback, Not Just First 3

This is the most important logic change.

### Desired Behavior

Given a saved ordered list like:

```text
[A, B, C, D, E]
```

If:

- `B` becomes unsellable

Then Shopify should receive:

```text
[A, C, D]
```

When `B` becomes sellable again, and the user pushes that inventory change successfully to Shopify, Shopify should go back to:

```text
[A, B, C]
```

This means:

- original ranking stays unchanged in local storage
- Shopify payload is always the first 3 **eligible** items in that stored order

### Current Code Area To Change

Today the narrowing happens in:

```php
ProductShopifyUpdater::limitReferenceItemsForLookup()
ProductShopifyUpdater::filterActiveProductReferenceGids()
```

Replace `filterActiveProductReferenceGids()` with sellability-based filtering.

Suggested direction:

```php
private function filterSellableProductReferenceGids(array $gids): array
{
    $products = Product::query()
        ->whereIn('shopify_id', $gids)
        ->with(['variants' => fn ($query) => $query->orderBy('id')])
        ->get()
        ->keyBy('shopify_id');

    return array_values(array_filter($gids, function (string $gid) use ($products): bool {
        $product = $products->get($gid);

        return $product instanceof Product
            && app(ProductSellabilityService::class)->isLocallySellable($product);
    }));
}
```

Then keep:

```php
return array_slice($eligibleInOriginalOrder, 0, self::COMPLEMENTARY_PRODUCTS_SYNC_LIMIT);
```

That preserves ranking while filling gaps from later backups.

## 7. Complementary Audit Must Match The New Rule

`ComplementaryProductAuditService` currently uses Shopify live availability and local `active` status.

After the change, local analysis must also use the same sellability service.

### Areas To Update

- `analyzeProduct()`
- `analyzeDraft()` if drafts are still expected to preview complementary validity
- local ineligible reasons

### New Local Ineligible Reasons

Examples:

- `Local status is DRAFT`
- `Local inventory is 0`
- `Local inventory is below 0`
- `Missing active variant`

This keeps audits aligned with what the sync layer actually sends.

## 8. Complementary Refresh Must Be Triggered By Successful Inventory Push

When a product's sellability changes, two categories of products are affected:

1. the product itself
2. all products that reference it in their complementary list

### Required New Dependency Lookup

We need a service that finds "parent" products that reference a given child product in their saved complementary list.

Suggested service:

```php
final class ComplementaryDependencyService
{
    /**
     * @return Collection<int, Product>
     */
    public function productsReferencingProduct(Product $child): Collection {}
}
```

It should search saved local complementary tokens from:

- `ShopifyRow` primary row data first
- fallback compatible local metafield storage if needed

### Refresh Action

When inventory changes locally:

- no complementary change should be pushed yet
- local saved ranking stays untouched

When the user manually pushes inventory/status to Shopify and that push succeeds:

1. detect whether sellability actually changed
2. find impacted parent products
3. resync only complementary metafield for those parents

That means we need a narrow sync action like:

```php
$updater->updateApprovedProducts(
    $products,
    scopes: [ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS],
    coreFields: [ProductShopifyUpdater::CORE_FIELD_COMPLEMENTARY_PRODUCTS],
);
```

This is good because the updater already supports scoped sync.

### Important Constraint

This follow-up repair is responsible only for complementary products.

It must not:

- resync unrelated product fields
- resync variants broadly
- change draft approvals
- trigger general product sync behavior

## 9. Add Inventory Sync Tracking

Product-level `last_synced_at` is currently editorial/product sync oriented.

Inventory should have its own tracking so users can see whether stock changes were pushed.

### Recommended Fields

Either on `variants` or `products`:

- `inventory_last_synced_at`
- `inventory_sync_batch_id`
- `inventory_local_dirty`
- `inventory_sync_error`

### Recommendation

For now:

- use variant-level tracking for correctness
- expose a product-level summary in the inventory table

## 10. Suggested Implementation Phases

### Phase 1: Core Rule And Safe Filtering

- add `ProductSellabilityService`
- update complementary sync filtering to use sellability
- update complementary audit to use sellability
- add inventory-only observer exception so approval version is not bumped

This fixes the business issue first with minimal UI work.

### Phase 2: Inventory Console

- add `InventoryResource`
- add search/filter/sort by SKU, title, status, quantity
- add modal-based inventory edit actions
- add modal-based status edit actions
- add bulk inventory sync action

### Phase 3: Dependency Refresh

- add `ComplementaryDependencyService`
- detect sellability transitions
- resync impacted parent complementary metafields automatically

### Phase 4: Operational Polish

- add audit/history for inventory sync
- add notifications for failed inventory pushes
- optionally add scheduled consistency checks against Shopify

## Recommended Database And Code Touch Points

### Likely Files To Change

- `app/Observers/VariantObserver.php`
- `app/Models/Variant.php`
- `app/Models/Product.php`
- `app/Services/ProductShopifyUpdater.php`
- `app/Services/ComplementaryProductAuditService.php`
- `app/Filament/Resources/ProductResource.php`
- new `app/Filament/Resources/InventoryResource.php`
- new `app/Services/ProductSellabilityService.php`
- new `app/Services/ProductInventorySyncService.php`
- new `app/Services/ComplementaryDependencyService.php`

### Likely Migrations

Depending on chosen tracking model:

```php
Schema::table('variants', function (Blueprint $table) {
    $table->timestamp('inventory_last_synced_at')->nullable();
    $table->string('inventory_sync_batch_id')->nullable();
    $table->boolean('inventory_local_dirty')->default(false);
    $table->text('inventory_sync_error')->nullable();
});
```

## Remaining Clarification

One detail still needs explicit confirmation before implementation:

1. Should the new inventory page itself be visible to anyone with either of the two new permissions, or should it also require a broader role gate on top of those permissions?

## V1 Summary

The safest first implementation is:

- local inventory becomes operational and approval-free
- quantity `0` does not auto-change product status
- status remains manually controlled
- variant inventory is editable from inventory modals
- complementary eligibility uses `status = active` plus variant sellability
- Shopify always receives the first 3 eligible complementary products in saved order
- if one of the top 3 becomes unsellable, the next eligible backup fills the slot
- when that product becomes sellable again and inventory push succeeds, it returns to its original ranked place
- the complementary repair runs automatically only after successful push and only for affected parent products

That fixes the current issue without rewriting the draft/product editorial workflow.
