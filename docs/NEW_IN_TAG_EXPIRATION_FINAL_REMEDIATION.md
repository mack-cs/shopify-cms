# New In Tag Expiration — Final Remediation

**Date:** 25 November 2026  
**Basis:** `docs/NEW_IN_TAG_EXPIRATION_FINAL_VERIFICATION.md`  
**Scope:** Local implementation only. No production deploy, live Shopify mutations, production cleanup, or schedule enablement.

| Decision | Result |
|---|---|
| 1. READY TO DEPLOY WITH ALL NEW ENFORCEMENT DISABLED | **YES** |
| 2. READY TO ENABLE OUTBOUND EXPIRATION ENFORCEMENT | **NO** |
| 3. READY FOR CONTROLLED LIVE CLEANUP | **NO** |
| 4. READY TO ENABLE DAILY SCHEDULE | **NO** |

Decisions 2–4 remain **NO** until staging completes live Shopify dry-run / single-GID pilot, shared-cache lock drills, and Shop Your Vibe delayed-membership verification. Decision 1 is **YES** because enforcement, recovery, and schedule all default off; normal CMS behaviour is preserved when flags are false (UTC cast remains active for storage correctness).

---

## Regression evidence

| Suite | Result |
|---|---|
| `NewInTagExpirationTest` | **37 passed** (182 assertions) |
| Related filter set (expiration + New In + SYV + CSV + inventory + movement + workflow + sync guard + unlisted) | **176 passed** (1159 assertions) |
| Shopify HTTP | Mocked only |
| Live Shopify mutations | Not executed |

---

## Finding matrix (final verification defects)

| # | Original defect | Root cause | Exact correction | Files / methods | Regression test | Actual result | Remaining requirement | Status |
|---|---|---|---|---|---|---|---|---|
| 1 | Age guard used CMS model identity instead of resolved mutation GID | `filterOutboundTags` / draft guards keyed off saved `shopify_id` before handle/SKU resolution | Authoritative GID is the mutation target; `withGuardedTagMutation($resolvedGid, …)` after `resolveProductId` / handle lookup; prefer live Shopify `createdAt` | `ProductShopifyUpdater` (resolved `$productId`), `NewProductDraftShopifyCreator::updateUnlistedProduct` / `productSetInput`, `NewInProductAgeResolver::filterTagsForResolvedGid`, `NewInTagExpirationService::withGuardedTagMutation` | `uses the resolved mutation GID…`, `keeps an empty tags array when withGuardedTagMutation…` | PASS | Staging capture of real `productUpdate` / `productSet` variables for handle-resolved and conflicting-ID fixtures | **PASS** (mocked) |
| 1b | Empty filtered tags omitted from draft `productSet` | Conditional `if ($tags !== [])` skipped empty replacement | Prefiltered path always sets `tags` (including `[]`); guarded GID path always assigns `array_values($tags)` | `NewProductDraftShopifyCreator::productSetInput` | Empty-tags guarded mutation test | PASS | Live confirmation that Shopify accepts empty tag replacement on `productSet` | **PASS** (mocked) |
| 2 | CSV filtered only in one prepopulation branch; SKU ownership after age check; bulk used optional Product; draft-only GID treated as new | Assignment ran before identity resolution; draft-only path skipped age | CSV assigns New In only after Shopify identity resolution (row / draft / SKU owner); draft-only GIDs call `filterLocalAssignmentTags`; `NewInTagService::markDrafts` enforces draft-only GIDs | `NewProductDraftCsvImporter`, `NewInTagService::markDrafts` / `tagsForNewProduct`, `NewInProductAgeResolver::filterLocalAssignmentTags` | `refuses New In assignment for draft-only Shopify GIDs…`; related CSV / NewInTagService suites | PASS | Full CSV-to-Shopify E2E on staging | **PASS** (mocked) |
| 3 | Outbound product lock released before Shopify mutation | Guard filtered then released; mutation ran unlocked | `withProductTagMutationGuard` holds product lock across filter + caller mutation (including updater fallbacks inside callback) | `NewInProductAgeResolver::withProductTagMutationGuard`, updater / draft creator call sites | Lock + empty-tags guarded tests; concurrent threshold crossing still needs shared-cache multi-process drill | PASS (single-process) | Staging multi-process lock drill across expiration and outbound write | **PASS** (unit/array). Multi-process **NOT VERIFIED** |
| 4 | Unfinished ops needed manual `--recover-local` or dispatched mirror job | No scheduled independent recovery | `Schedule::command('… --recover-local')->everyFiveMinutes()` gated by `recovery_enabled` **and** `enforcement_enabled`; manual `--recover-local` retained | `routes/console.php`, `ExpireNewInTags`, `recoverPendingLocalSyncs` | Schedule registration tests | PASS | Staging: kill worker after remote success; confirm auto recovery without search hit | **PASS** (mocked schedule). Live recovery drill **NOT VERIFIED** |
| 5 | `remote_confirmed` could complete while tags still present | Recovery skipped re-remove for that status | Always re-remove when intended tags remain; re-verify readback before `confirmed`; SYV failure leaves `remote_confirmed` | `NewInTagExpirationService::recoverOperation` | `re-removes tags when recovering a remote_confirmed…`, SYV failure/recovery test | PASS | Staging restart drill | **PASS** (mocked) |
| 6 | Legacy JSON two-entry same-GID lost second delta | Import skipped duplicate GID | Merge removal deltas via `claimOperation`; archive only after durable account; corrupt files reported | `migrateLegacyJsonPendingIfPresent` | `merges multiple legacy JSON recovery entries…` | PASS | Production legacy file presence check (read-only) | **PASS** |
| 7 | Mirror mutated historical Product rows for same GID | All `shopify_id` matches updated | Restrict Product updates to current import (`Import.is_current`); preserve historical snapshots | `mirrorLocalStateForOperation` | `does not mutate historical import product rows…` | PASS | Staging confirm against real multi-import data | **PASS** (mocked) |
| 8 | Manual execution had no lease expiry fencing | `isOwnedByCurrentProcess` alone; ArrayLock keeps owner after soft expiry | `assertOwned` detects ArrayLock `expiresAt` past; `renewIfOwned` refreshes ArrayLock lease; run loop renews execution lock; old owner cannot release successor | `NewInExpirationLock`, `NewInTagExpirationService::run` | `stops unsafe work when the execution lock lease…` | PASS (array cache + Carbon time travel) | Staging database/redis lock store multi-process | **PASS** (array). Shared production backend **NOT VERIFIED** |
| 9 | Missing/null `nodes` accepted as empty discovery | Only cursor validated | `requireConnectionNodes` rejects omitted / null / non-array nodes for `productTags` and `products` before mutations | `requireConnectionNodes`, discovery + candidate search | missing/null productTags + products tests; empty nodes accepted | PASS | — | **PASS** |
| 10 | Outer whitespace trimmed before removal | Classifier normalized for mutation lists | Classification normalizes internally; `classifyMany` preserves exact original strings including outer whitespace | `NewInTagClassifier::classifyMany` | `preserves outer whitespace…` | PASS | Confirm Shopify ever stores outer-whitespace tags (read-only) | **PASS** |
| 11 | Legacy `shopify_created_at` TZ provenance uncertain | Prior writers used default cast / session TZ | Prefer live Shopify `createdAt` for enforcement decisions; local value treated unverified if remote missing; no blanket backfill | `NewInProductAgeResolver::resolve` / `filterTagsForResolvedGid` | Missing local date fetch test; prior MySQL cast round-trip still valid | PASS (policy) | Read-only production: sample `shopify_created_at` vs Shopify `createdAt` near 75-day boundary | **PARTIAL** — policy correct; production provenance **NOT VERIFIED** |
| 12 | SYV reconcile exceptions swallowed into `confirmed` | Catch marked operation complete | Failures leave `remote_confirmed` + `syv_reconcile_failed`; recovery retries reconcile | Expiration `executeRemoval` / `recoverOperation` | `leaves Shop Your Vibe reconcile failures recoverable…`; existing membership gate suite | PASS | Staging delayed smart-collection membership + reapprove/push | **PASS** (mocked). Live delayed membership **NOT VERIFIED** |
| 13 | `NEW_IN_EXPIRATION_ENABLED` only gated schedule; outbound still active | Single flag | Separate `NEW_IN_EXPIRATION_ENFORCEMENT_ENABLED` and `NEW_IN_EXPIRATION_RECOVERY_ENABLED` (both default false). Schedule requires `enabled`+`enforcement`. Recovery schedule requires `recovery`+`enforcement`. Live CLI mutations require enforcement or `--force` | `config/new_in_expiration.php`, `.env.example`, command, schedule | enforcement-off + schedule/recovery gate tests | PASS | — | **PASS** |
| 14 | Local `la_shopify_seo.new_in_expiration_operations` side effect | Prior MySQL probe created table | **Read-only inspect only** (no drop/truncate). Table exists, **0 rows**, **no migrations record** | — | Inspect script | See below | Before local `migrate`, either fake the migration row or authorize cleanup on a disposable DB | **PASS** (inspection) |

### Local database side effect (item 14)

| Check | Result |
|---|---|
| Database | `la_shopify_seo` (mysql @ 127.0.0.1) |
| `new_in_expiration_operations` exists | **yes** |
| Row count | **0** |
| `migrations` row for `%new_in_expiration%` | **(none)** |
| Orphan risk | **yes** — next `php artisan migrate` may attempt `CREATE TABLE` and fail |
| Action taken | None (no schema change without authorization) |

Use a dedicated disposable schema for further migration/concurrency tests.

---

## Configuration matrix

| Flag | Default | When false | When true |
|---|---|---|---|
| `NEW_IN_EXPIRATION_ENABLED` | `false` | Daily 03:15 sweep not scheduled | Sweep scheduled **only if** enforcement also true |
| `NEW_IN_EXPIRATION_ENFORCEMENT_ENABLED` | `false` | No outbound age stripping, no product locks for New In, no live expiration mutations (unless `--force`) | Outbound guards + assignment filters + expiration mutations allowed |
| `NEW_IN_EXPIRATION_RECOVERY_ENABLED` | `false` | No 5-minute recovery schedule | Recovery schedule **only if** enforcement also true |
| UTC cast on `products.shopify_created_at` | Always on | N/A | Storage/read correctness (not an enforcement switch) |

Recovery cannot run on the schedule while enforcement is disabled. Manual `--recover-local` requires enforcement, recovery, or `--force`.

---

## Deploy / rollback (enforcement disabled)

### Deploy

1. Deploy code + migration `2026_11_25_093000_create_new_in_expiration_operations_table`.
2. Ensure env:
   ```
   NEW_IN_EXPIRATION_ENABLED=false
   NEW_IN_EXPIRATION_ENFORCEMENT_ENABLED=false
   NEW_IN_EXPIRATION_RECOVERY_ENABLED=false
   ```
3. `php artisan migrate --path=database/migrations/2026_11_25_093000_create_new_in_expiration_operations_table.php`
4. `php artisan schedule:list` — confirm daily New In and recovery entries remain filtered off.
5. Smoke normal product update / draft create without expecting tag stripping.

### Local orphan table note

If `la_shopify_seo` already has the table without a migrations row, either:

- `php artisan migrate --pretend` first and, with authorization, insert the migrations row after verifying schema matches, **or**
- run migrations against a disposable database.

Do not drop the local table without explicit authorization.

### Rollback

1. Keep all three flags false.
2. Rolling back the migration **drops** `new_in_expiration_operations` (preserve/export open ops first if any).
3. Shopify tag state already changed by any prior pilot is **not** reverted by code rollback.
4. Revert code deploy if needed; UTC cast rollback should be coordinated with any persisted UTC values.

---

## Staging verification before enabling enforcement / cleanup / schedule

```bash
php artisan test --filter=NewInTagExpirationTest
# plus related suites used in this remediation

php artisan migrate --pretend --path=database/migrations/2026_11_25_093000_create_new_in_expiration_operations_table.php
php artisan products:expire-new-in-tags --dry-run
php artisan schedule:list
```

Then, on staging only:

1. Shared cache lock contention between expire, recover, and queued job.
2. Single-GID `--dry-run --product=…` for ACTIVE/DRAFT/ARCHIVED/UNLISTED fixtures.
3. Controlled `--force --product=…` pilot after dry-run approval.
4. Worker kill after remote success → automatic recovery with `RECOVERY_ENABLED=true` and `ENFORCEMENT_ENABLED=true`.
5. Shop Your Vibe: expire fixture, delay collection membership, refresh/reapprove/push; confirm unrelated memberships preserved.
6. Read-only compare stored `shopify_created_at` vs Shopify `createdAt` near the 75-day boundary.

Only after those succeed:

- Enable **enforcement** for outbound protection.
- Authorize **controlled live cleanup** (`--force` / queued single-GID then broader dry-run).
- Enable **daily schedule** (`ENABLED=true` with enforcement true).
- Enable **recovery schedule** (`RECOVERY_ENABLED=true` with enforcement true).

---

## Changed files (this remediation)

- `app/Services/NewInProductAgeResolver.php`
- `app/Services/NewInTagExpirationService.php`
- `app/Services/NewInExpirationLock.php`
- `app/Services/NewInTagService.php`
- `app/Services/ProductShopifyUpdater.php`
- `app/Services/NewProductDraftShopifyCreator.php`
- `app/Services/NewProductDraftCsvImporter.php`
- `app/Services/NewInTagClassifier.php` (exact string preservation)
- `app/Console/Commands/ExpireNewInTags.php`
- `config/new_in_expiration.php`
- `.env.example`
- `routes/console.php`
- `tests/Feature/NewInTagExpirationTest.php`
- `docs/NEW_IN_TAG_EXPIRATION_FINAL_REMEDIATION.md`

---

## Intentionally not done

- Production deploy, live Shopify mutations, production cleanup, schedule enablement
- Drop/truncate of local `la_shopify_seo.new_in_expiration_operations`
- Blanket timestamp backfill
- Shop Your Vibe redesign
- Unrelated architectural refactors
