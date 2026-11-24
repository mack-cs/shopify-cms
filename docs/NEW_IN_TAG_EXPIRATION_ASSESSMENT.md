# New In Tag Expiration Assessment — 75-Day Product Lifecycle

**Date:** 2026-11-24  
**Scope:** Investigation and assessment only. No application code, migrations, schedules, Shopify mutations, or production data changes were made.

---

## Current implementation

### Product import and local storage

- Shopify products enter the CMS primarily through catalogue import / draft seeding and are stored on `products` (`app/Models/Product.php`).
- Local CMS timestamps:
  - `products.created_at` — Laravel model creation time in the CMS.
  - `products.shopify_created_at` — nullable Shopify creation timestamp (`datetime` cast).
- `shopify_created_at` was added in `database/migrations/2026_07_31_081000_add_shopify_movement_fields_to_catalogue.php` (lines 11–13).
- Confirmed write path for `shopify_created_at`:
  - `App\Services\ProductInventorySyncService::refreshProductVariants()` reads Shopify GraphQL `createdAt` and updates the product when it differs (`app/Services/ProductInventorySyncService.php` approximately lines 524–540).
  - Queries include `createdAt` in `productInventoryByIdQuery()` / `productInventoryByHandleQuery()` (approximately lines 1000–1043).
- CSV / `HeaderStore` does **not** define a “Created At” import column (`app/Services/HeaderStore.php`). `Normalizer` does **not** populate `shopify_created_at`.
- Therefore local `shopify_created_at` is **opportunistically filled** during inventory refresh / movement workflows, not guaranteed complete for every catalogue product.
- Product Movement reporting already prefers Shopify creation time when present, falling back to CMS `created_at` (`app/Services/ProductMovementReportService.php` approximately lines 615–639, 731, 1023). That fallback is **not** acceptable for this 75-day rule.

### Tags: retrieval, storage, and New In vocabulary

Canonical managed New In tags are defined in `App\Services\NewInTagService`:

```php
public const TAGS = [
    'new-arrivals',
    'new-in',
    'newbies',
];
```

File: `app/Services/NewInTagService.php` lines 10–14.

In addition, collection-aware tags are generated:

- `{collection}-new-in` (e.g. `livi-road-new-in`, `pata-pata-new-in`, `elevated-basics-new-in`)
- `{collection}-{productType}-stacks-new-in` (e.g. `livi-road-bracelet-stacks-new-in`)
- Elevated Basics stacks:
  - `elevated-basics-stacks-new-in` (`ElevatedBasicsStackCatalog::NEW_IN_TAG`)
  - legacy `elevated-basics-bracelet-stacks-new-in` (`ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG`)

See `NewInTagService::tagsForNewProduct()` (lines 25–56), `isManagedCollectionNewInTag()` (lines 242–259), and `app/Services/ElevatedBasicsStackCatalog.php` lines 22–24.

**Exact business spelling in Shopify/CMS is hyphenated lowercase `new-in`, not the display phrase “New In”.**

Product tags are stored as a comma-separated string on:

- `products.tags`
- `new_product_drafts.tags`
- imported `ShopifyRow` “Tags” column via `HeaderStore::TAGS`

Tag tokens are normalized by `App\Services\TagNormalizer`.

### Where New In tags are added / removed today

| Path | Behavior | Location |
|------|----------|----------|
| New draft creation | Auto-applies full New In tag set for non-unlisted launches | `NewProductDraft` `creating` hook, `app/Models/NewProductDraft.php` lines 180–187 |
| Draft “Mark as new in” toggle / bulk action | Adds or removes managed New In tags | `NewProductDraftResource`, `NewInTagService::tagsForNewProduct()` / `removeManagedTags()` |
| CSV importer | Restores New In tags on import for new products | `NewProductDraftCsvImporter` + tests in `tests/Feature/NewProductDraftCsvImporterTest.php` |
| Product Shopify sync | Can push **full** tags array from CMS via `productUpdate` | `ProductShopifyUpdater` approximately lines 2017–2055, 2163–2169 |
| Shop Your Vibe | Has `tagsAdd` / `tagsRemove` helpers; does not manage New In lifecycle | `ShopYourVibeShopify::addProductTags()` / `removeProductTags()` lines 559–579 |

There is **no** existing scheduled job that expires New In tags by product age.

### Shopify GraphQL client behavior

- Client: `App\Services\ShopifyApiClient` (`app/Services/ShopifyApiClient.php`).
- Configured API version: `config('services.shopify.api_version')` default **`2026-01`** (`config/services.php`).
- Throttling: up to 4 attempts with cost-based / Retry-After delays (approximately lines 69–139).
- Mutations that return uncertain throttle outcomes are **not** blindly replayed (429 / partial throttle with data).
- `tagsRemove` is already used successfully by Shop Your Vibe (`ShopYourVibeShopify::tagMutation()`), confirming Product support on the current API version.

### Scheduler conventions

Registered in `routes/console.php`, timezone typically `Africa/Johannesburg`:

- `shopify:run-daily-pipeline` daily at **02:00**
- Image backup reconcile **02:00**
- SEO / Search Console around **04:00–05:00**
- Site audit **06:00**
- `procurement:run` daily at **06:30** (configurable)
- Inventory health live audit / stack alerts around **08:00**

Overlap protection patterns already used: `withoutOverlapping()`, `onOneServer()`, dedicated queues for long work.

---

## Existing functionality that can be reused

1. **`ShopifyApiClient`** — authenticated GraphQL, throttle/retry.
2. **`ShopYourVibeShopify::removeProductTags()`** pattern — or extract a shared thin Product tags helper using the same `tagsRemove` mutation.
3. **`NewInTagService`** — authoritative list of managed New In tags and collection-aware helpers; `removeManagedTags()` already knows which tags to strip locally.
4. **`TagNormalizer`** — parse/normalize tag tokens for local CMS updates.
5. **`Product.shopify_id` / `shopify_created_at`** — local cache aids reporting, not the authoritative eligibility source.
6. **Scheduler conventions** in `routes/console.php` — `dailyAt` + `timezone('Africa/Johannesburg')` + `withoutOverlapping` + `onOneServer`.
7. **Pagination patterns** in `ShopifyCollectionProductReportService` — `pageInfo { hasNextPage endCursor }`.
8. **Procurement reconcile command UX** — dry-run / `--limit` / confirm-before-live pattern recently used for mark-for-archive.

---

## Confirmed implementation gaps

1. No New In age-expiration command, job, or schedule exists.
2. No code currently queries Shopify for `tag` + `created_at` eligibility for expiration.
3. Local `shopify_created_at` is incomplete / opportunistic; cannot be the sole eligibility source.
4. Business requirement wording (“the New In tag”) understates the real tag set; removing only `new-in` would leave `new-arrivals`, `newbies`, and `{collection}-new-in` tags in place, which likely continue to drive automated New In collections.
5. CMS product/draft tag sync can **reintroduce** expired tags after a Shopify-only `tagsRemove` unless local state is updated and future syncs are constrained.
6. No dry-run reporting command for overdue New In products.

---

## Recommended architecture

### Preferred workflow (Shopify-authoritative)

```text
Daily schedule (Africa/Johannesburg)
  -> Artisan command (orchestration, locking, dry-run/limit)
    -> Queued job (or command-run service for small batches)
      1. Compute cutoff = now(UTC) - 75 days  (compare against Shopify createdAt)
      2. READ-ONLY paginated Shopify products query:
           tag:<target> AND created_at:<=<'CUTOFF_ISO8601'>
         Collect eligible product GIDs + createdAt + tags + status + title (+ variants/sku)
         Do NOT mutate during pagination.
      3. For each collected product (respect --limit):
           a. Re-read product tags + createdAt (optional safety)
           b. Confirm still has managed New In tag(s) and createdAt <= cutoff
           c. tagsRemove(id, tags: [...exact tags to remove...])
           d. Confirm via readback or empty userErrors + optional product.tags query
           e. Update local Product.tags / matching NewProductDraft.tags / ShopifyRow Tags
              using NewInTagService::removeManagedTags() (or scoped subset)
      4. Emit summary (examined / eligible / removed / skipped / failed)
```

### Why collect-then-mutate

Removing tags changes the Shopify search result set while paging. Collecting IDs in a read-only pass, then mutating, avoids skipping or double-processing due to shifting cursors.

### Tag scope recommendation (requires product owner confirmation)

**Recommended default for true “leave New In” behavior:** remove the full managed set:

- `new-arrivals`, `new-in`, `newbies`
- any managed `{collection}-new-in` and `{collection}-*-stacks-new-in` present on the product
- Elevated Basics stack New In tags when present

**If the business insists on only `new-in`:** document that collection-specific New In tags will remain and may keep products in automated New In collections.

This assessment assumes the full managed set unless told otherwise.

---

## Proposed Shopify GraphQL queries and mutations

API version: **2026-01** (current CMS default).

### Eligible products query

Shopify Admin product search supports both `tag` and `created_at` filters ([search syntax](https://shopify.dev/docs/api/usage/search-syntax), [products query](https://shopify.dev/docs/api/admin-graphql/latest/queries/products)).

Exact tag token is `new-in` (hyphenated). Dates must be quoted.

```graphql
query ExpireNewInCandidates($query: String!, $first: Int!, $after: String) {
  products(first: $first, after: $after, query: $query) {
    edges {
      cursor
      node {
        id
        title
        handle
        status
        createdAt
        tags
        variants(first: 10) {
          nodes { id sku }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
```

Example query string:

```text
tag:new-in AND created_at:<='2025-09-10T10:00:00Z'
```

Notes:

- `created_at:<=` / `:<` are supported comparators.
- For the full managed set, either:
  - run one pass per tag (`tag:new-in`, `tag:new-arrivals`, `tag:newbies`, plus known collection tags), merging unique product IDs; or
  - query `tag:new-in` as the primary gate (most products carry the canonical trio together), then remove all managed New In tags present on each product.
- Verify live search behavior with a read-only smoke query before enabling writes (tag tokenization / quoting for unusual collection tags).

### Safe tag removal mutation

```graphql
mutation RemoveNewInTags($id: ID!, $tags: [String!]!) {
  tagsRemove(id: $id, tags: $tags) {
    node {
      ... on Product {
        id
        tags
        createdAt
      }
    }
    userErrors {
      field
      message
    }
  }
}
```

Compatible with existing Shop Your Vibe usage and Shopify’s current `tagsRemove` docs (Product is a supported resource).

**Do not** use `productUpdate(input: { tags: [...] })` for expiration. That replaces the full tag set and can race with other writers.

### Optional verification query

```graphql
query VerifyProductTags($id: ID!) {
  product(id: $id) {
    id
    createdAt
    tags
    status
  }
}
```

---

## Shopify-direct vs local CMS data

| Approach | Advantages | Limitations | API cost |
|----------|------------|-------------|----------|
| **Shopify-direct (preferred)** | Uses authoritative `createdAt` and live tags; not blocked by null local `shopify_created_at`; matches business preference | Needs pagination + throttle handling; search quirks must be validated | Moderate daily: one filtered product search + one `tagsRemove` per eligible product |
| **Local CMS scan** | Cheap; easy dry-run against DB | `shopify_created_at` incomplete; local tags can drift from Shopify; would still need Shopify writes/verification | Low read cost, but unsafe as sole eligibility source |

**Recommendation:** Shopify-direct eligibility every day. Optionally update local `shopify_created_at` and tags after successful removals for CMS consistency.

---

## Required file changes (proposed — not implemented)

### New files

- `app/Console/Commands/ExpireNewInTags.php` — Artisan command with `--dry-run`, `--limit=`, `--product=`, optional `--force`
- `app/Jobs/ExpireNewInTagsJob.php` — queued worker for larger runs / retries
- `app/Services/NewInTagExpirationService.php` — cutoff calculation, Shopify search pagination, `tagsRemove`, local mirror update, reporting
- `tests/Feature/NewInTagExpirationTest.php` — mocked GraphQL coverage
- (optional) `config/new_in_expiration.php` — days=75, enabled flag, schedule time, tag strategy

### Existing files to modify

- `routes/console.php` — register daily schedule after approval
- Possibly `app/Services/NewInTagService.php` — expose helpers for “managed New In tags present on this product” / expiration removal list
- Possibly thin reuse of `ShopYourVibeShopify` tag mutation helpers, or move shared Product tag mutations to a small dedicated service to avoid coupling expiration to Shop Your Vibe

### Explicitly do **not** change

- Procurement import / Ignore / mark-for-archive sync
- Inventory synchronization
- Shop Your Vibe sorting / preview push workflow
- Product activation / launch workflows (except ensuring they do not blindly re-add expired tags later)

---

## Database changes

**No migration required** for the preferred Shopify-authoritative design.

Optional later (not required for v1):

- backfill / maintain `products.shopify_created_at` more aggressively for reporting;
- audit table for expiration attempts — only if operational logging needs more than application logs / ChangeLog.

Prefer avoiding new columns/tables for v1.

---

## Proposed implementation sequence

1. Confirm tag scope with stakeholders (canonical `new-in` only vs full managed New In set).
2. Read-only Shopify smoke query validating `tag:new-in AND created_at:<=...` against production (no mutations).
3. Implement `NewInTagExpirationService` + command with **dry-run only**.
4. Add mocked Pest coverage (age boundaries, pagination collect-then-mutate, userErrors, local tag mirror).
5. Deploy behind disabled schedule / feature flag.
6. Dry-run in production; review report.
7. Live `--limit=5`, then `--limit=50`, then full cleanup.
8. Enable daily schedule.
9. Monitor for tag reintroduction via CMS product sync / draft New In toggle / CSV import; patch those paths if observed.

---

## Scheduler recommendation

**Recommended time:** `03:15` Africa/Johannesburg

**Justification:**

- After `shopify:run-daily-pipeline` (02:00) settles.
- Before SEO / site-audit / procurement peak (04:00–06:30).
- Low storefront traffic window.
- Avoids competing with procurement sheet pulls and mark-for-archive jobs.

Suggested registration shape (not applied yet):

```php
Schedule::command('products:expire-new-in-tags')
    ->dailyAt('03:15')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->when(fn () => config('new_in_expiration.enabled', false));
```

Use a queued job for the mutation phase if eligible volume is large; keep the Artisan command as the locked entrypoint.

---

## Risks and dependencies

### High: CMS tag reintroduction

`ProductShopifyUpdater` can push the full CMS `products.tags` array to Shopify during approved product sync (`CORE_FIELD_TAGS`). If local tags still contain New In tokens after Shopify removal, the next sync **restores** them.

**Mitigation:** after confirmed `tagsRemove`, update:

- `products.tags`
- matching `new_product_drafts.tags`
- `ShopifyRow` Tags for the product handle

Prefer `NewInTagService::removeManagedTags()` for consistency.

### High: incomplete tag removal scope

Removing only `new-in` while leaving `new-arrivals`, `newbies`, and `{collection}-new-in` will likely leave products in automated New In collections.

### Medium: automated collection membership

Shop Your Vibe docs note some linked collections are **automated** and membership is controlled by Shopify rules (`docs/shop-your-vibe.md`). Removing New In tags should remove products from tag-based New In collections automatically; manual collections are unaffected unless separately managed.

Shop Your Vibe **sorting drafts / approvals** do not appear to key off New In tags specifically; risk is mainly collection membership churn for automated New In collections.

### Medium: processes that can re-add New In

- Draft create hook (`NewProductDraft` creating) — for **new** drafts only.
- Draft “Mark as new in” / bulk mark (`NewInTagService::markDrafts`).
- CSV importer restoring New In tags.
- Manual Filament tag edits + product push.

Expiration must not auto-assign New In; operators can still intentionally re-mark a product.

### Low: procurement / inventory / stacks

No evidence that procurement Ignore sync, inventory sync, or stack image workflows depend on New In tags. Stack products can carry stack-specific New In tags and should be included in expiration if aged ≥ 75 days.

### Pagination / concurrency

- Collect IDs before mutating.
- `withoutOverlapping` + `onOneServer`.
- Idempotent reruns: products without managed New In tags are skipped.
- Treat GraphQL `userErrors` and uncertain throttle mutation outcomes as failures; do not mark success locally.

---

## Testing and deployment plan

### Mocked unit/feature tests (required before enablement)

- Age 74 days + `new-in` → skip
- Age exactly 75 days + `new-in` → remove (define inclusive boundary as `createdAt <= now - 75 days`)
- Age 76 days / multi-year → remove
- No New In tags → skip
- Multiple unrelated tags preserved
- Missing/invalid `createdAt` → skip + warning
- `tagsRemove` userErrors → failed, local tags unchanged
- Multi-page candidate collection then mutate
- Repeated execution → second run no-ops
- Dry-run → zero mutations
- Local CMS tag mirror updated only after success
- Concurrent overlapping command prevented

### Live Shopify verification (manual, staged)

1. Read-only query smoke test.
2. Dry-run report review.
3. `--limit=5` live removals; verify in Admin:
   - managed New In tags gone
   - other tags intact
   - status/inventory/price/images/metafields unchanged
   - automated New In collection membership updated as expected
4. Larger batches, then enable schedule.

### Deployment

1. Deploy code with schedule **disabled** / feature flag off.
2. Run dry-run.
3. Controlled live cleanup.
4. Enable daily schedule.
5. Watch logs for failures and for any CMS sync reintroduction incidents.

---

## Initial reconciliation / dry-run report fields

Dry-run should list each eligible product:

- Title
- SKU (first variant / all variant SKUs when useful)
- Shopify product ID
- Shopify `createdAt`
- Age in days
- Current managed New In tags found
- Proposed tags to remove

Summary counters:

- Total assessed
- Eligible for removal
- Skipped
- Successful removals (live only)
- Failed removals (live only)

Support `--limit` for controlled cleanup of already-overdue catalogue items.

---

## Reliability matrix (assessment)

| Case | Expected behavior |
|------|-------------------|
| Exactly 75 days old | Eligible (`createdAt <= cutoff`) |
| Younger than 75 days | Skip |
| Older than 75 days | Eligible if managed New In tag present |
| No New In tags | Skip |
| Missing/invalid createdAt | Skip + log; do not guess from CMS dates |
| Deleted mid-run | Treat as skip/fail; continue |
| Draft / archived with tag | Eligible if query returns them; optionally restrict to `status:active` after stakeholder decision |
| API failure / userErrors | Record failure; do not update local tags; retry later |
| Rate limiting | Use existing client backoff; pause between pages |
| Interrupted / concurrent runs | Overlap lock; idempotent resume |
| Tags changed mid-flight | Re-check tags before remove; remove only tags still present |
| Reintroduction by CMS | Local mirror update + monitor product tag sync paths |

---

## Final assessment

| Question | Answer |
|----------|--------|
| Is Shopify’s `createdAt` already available? | **Partially locally** via nullable `products.shopify_created_at`, filled mainly by inventory refresh. **Always available from Shopify GraphQL.** Use Shopify as authority. |
| Can the existing Shopify API integration be reused? | **Yes.** `ShopifyApiClient` + existing `tagsRemove` pattern. |
| Can eligible products be filtered directly through Shopify? | **Yes.** Product search supports `tag` and `created_at` together on Admin GraphQL. Validate the exact query string with a read-only smoke test. |
| Is a database migration required? | **No** for the preferred design. |
| Could another CMS process restore expired tags? | **Yes — material risk.** Product tag sync / draft New In tooling / CSV import can reintroduce tags unless local state is updated and monitored. |
| Safest implementation approach? | Daily Shopify-authoritative search → collect IDs → `tagsRemove` only managed New In tags → confirm → mirror local tags. Dry-run and `--limit` before full cleanup. Feature-flag the schedule. |
| Ready for implementation? | **Yes, pending stakeholder confirmation of tag scope** (canonical `new-in` only vs full managed New In set) and a read-only production query smoke test. |

---

## Approval gate

No implementation has been started.

Please confirm:

1. Remove **full managed New In tag set** (recommended) or **only `new-in`**?
2. Include draft/archived products, or active-only?
3. Approval to proceed with implementation after that confirmation?
