# Shared engraved bead implementation — 28 October 2026

## Readiness and production boundary

Local implementation and isolated tests are provided. **This is not a production-readiness approval.** No production Shopify mutation, publication, tracking change, inventory adjustment, feature-flag change, webhook registration or configured-application database migration was executed. After the instruction to leave the live store alone, no additional store requests were made. Subsequent external research used public Shopify documentation only.

Earlier read-only catalog and Shopify inspection found seven families, four products each, all 28 SKUs blank, all 28 variants tracked, and one active location fulfilling online orders. The store reported `shopifyPlus: false`. The existing tracked multi-bead inventories must not be summed, zeroed, or reclassified as physical source inventory without an approved cutover reconciliation. No family has been activated by this work.

The initial import failure remains explained by the pending `2026_10_26_150000_add_product_launch_and_engraved_bead_support` migration. Tests migrate disposable SQLite databases; they do not apply this migration to the configured MySQL application database.

## Architecture, schema and durable identity

`EngravedBeadFamilyDiscovery` discovers exact `*-bead-family` tags from synchronized products. Family names and Shopify IDs are not hardcoded. It rejects missing/duplicate/conflicting family and quantity tags; absent SKUs or product/variant/inventory-item IDs; anything other than four quantities 1–4; duplicate identities; untracked sources; independently tracked multi-bead members unless automation owns the disable; nonzero independent member on-hand stock; and unverified/ambiguous source locations. New groups remain pending. Invalid groups retain observed members and errors for review.

`2026_10_28_150000_create_engraved_bead_families` creates families, members and audit history, and adds family/version/historical provenance to existing reservation rows. Models are `EngravedBeadFamily`, `EngravedBeadFamilyMember`, and `EngravedBeadFamilyAudit`.

`2026_10_28_151000_add_engraved_bead_sellability_operations` adds owned source safety-stock counters, recalculation/failure fields, manual re-enable protection, `EngravedBeadSellabilityOperation`, and durable bead movement intents. Operation foreign keys restrict destructive deletion.

Approved membership snapshots contain Shopify identities, source location, a mapping hash and configuration version. Discovery flags drift instead of replacing an approved mapping. Existing reservations keep their snapshotted source item/location and family/version. Conflicts and reversal-review states do not automatically clear when tags are restored. Explicit reconciliation is required; the implementation does not offer unrestricted source reassignment of an active historical ledger.

## Physical accounting and reservation state machine

All quantities are individual beads. The tracked 1-bead source receives Shopify's native one-unit commitments; the CMS does not duplicate them. An untracked quantity-N member reserves **N × ordered units**, entirely against the source. An unmapped or independently tracked member cannot create a new shared reservation.

Authoritative effective availability is:

`source Shopify available + CMS-owned source safety stock − unapplied CMS reservations`

Applied `available → reserved` movements already reduce Shopify available and are **not subtracted again**. The implemented mutation path serializes source operations and blocks sellability whenever a physical movement is unconfirmed; it therefore does not advertise a guessed effective balance during an uncertain operation. The pure availability policy exposes the unapplied-reservation term explicitly.

Reservation counters record confirmed reserves, consumption, releases and restocks. Historical baseline units are separate provenance, never synthetic consumed stock. Pre-cutover orders create no new shared inventory movements. Post-cutover partial or fast fulfillments reserve the complete obligation and consume it through durable fulfillment identities. Missed fulfillment webhooks can be recovered from paginated live fulfillment snapshots by a guarded reconciliation job.

Partial cancellations, removed lines and later re-added lines reconcile outstanding demand without restoring consumed stock. Refunds without a restock signal restore nothing. Restocks are capped by confirmed CMS consumption minus prior restocks. A reversed shipment does not prove goods returned: it preserves stock movements and blocks the family for warehouse reconciliation.

Each movement persists intent and an idempotency identity before Shopify calls. Identity depends on immutable event/order-line/fulfillment/refund/action identity, not a clamped remainder. Split reservations snapshot available-versus-safety-stock amounts. A remote success followed by a local completion failure retries the same remote key and intent. Local counters and completion commit together under row locks. Unconfirmed operations block subsequent pool events. Uncertain operations older than 23 hours require explicit reconciliation rather than assuming indefinite remote idempotency retention.

## Shopify sellability transitions

Defaults disable inclusively at available balances ≤1, ≤2, ≤3 and ≤4. Thresholds are authorized, versioned and audited. Editing a threshold records a durable recalculation request. Manual re-enable protection is respected.

For multi-bead members, the adapter enforces `inventoryPolicy: DENY`, enables tracking, sets available quantity to zero with a compare-and-set expectation, then rereads Shopify. It confirms unavailable-for-sale before recording success. It never changes publication. Re-enabling requires an automation-owned disable, current product approval, active/unlisted live intent, no manual block, and sufficient stock; tracking is disabled and Shopify sellability verified. No positive artificial member quantity is assigned. Nonzero independent member on-hand/commitment/hold balances block transitions.

For the source, tracking always stays on and on-hand is never set to zero. The control moves available beads into a CMS-owned `safety_stock` ledger, preserving on-hand. Once eligible, it releases only that owned hold. Multi-bead reservations can move directly from the owned source safety-stock ledger into their reservation ledger; releases and confirmed restocks return to the owned safety-stock ledger while the source remains automation-disabled. They do not temporarily reopen the source by releasing held beads into available stock.

These use Shopify's documented inventory-state model and move API, not a badge or local availability flag. **Actual sold-out behavior and transition semantics are not verified on a test store.** The adapter rejects multiple or unverified fulfillment locations and incomplete Shopify responses. This is an intentional deployment block, not a claim to support arbitrary multi-location or fulfillment-service setups. See [inventory state management](https://shopify.dev/docs/apps/build/inventory-management-apps) and [inventory states](https://help.shopify.com/en/manual/inventory-and-locations/fundamentals/inventory-states).

## Synchronization, queues and CMS

Inventory webhooks, audited inventory changes, order movement workflows, inventory corrections and supplier receiving through the shared inventory adjustment service mark affected families for recalculation. Catalog import invokes local mapping reconciliation. The minute scheduler dispatches pending work and recovers disabled order events and pending fulfillments; periodic family reconciliation refreshes stock even after a missed inventory webhook.

`inventory-ledger` uses a 300-second worker timeout, 420-second source/order lock leases and 480-second queue visibility. Source operations share an item/location lock across orders. The required cache backend must support shared cross-worker atomic locks. The actual MySQL/cache/worker deployment still needs staging verification. Separate-process tests use a disposable SQLite database and its shared database lock backend.

`product-launch` has a 900-second timeout, 960-second product locks and 1,020-second visibility. Launch rechecks the execution-time switch, actor permission and permitted publications; preserves item operation keys on retries; and refuses to reinterpret available stock as an on-hand correction. Use the approved inventory-correction workflow separately. Engraved launch requires approved shared mapping and stock checks. General engraved product sync cannot activate a product, and new engraved products are created as drafts.

The Filament family resource lists validation and synchronization failures; opens members, SKUs, tracking/sellability snapshots, source on-hand/available stock, reservations, pending operations and audits; edits thresholds; controls automatic re-enable permission; reconciles synchronized catalog data without Shopify writes; and approves validated activation behind store-contract verification. Inventory numbers shown there are clearly identified as synchronized snapshots. `engraved-beads:discover` is catalog-only; `--persist` saves pending mappings locally and still makes no Shopify request.

## Procurement and the external Python pipeline

**Superseded on 29 October 2026:** the confirmed architecture is ordinary tracked-source forecasting, not weighted family forecasting. The actual external Python pipeline has now been corrected. Only an approved tracked 1-bead source is a candidate, including eligible unlisted sources. The other three products are excluded. Python does not multiply or aggregate their order quantities.

The existing order-line pipeline had a physical-demand gap: inventory availability events did not include CMS consumption as sales. A dedicated authenticated feed now supplies completed, already-measured source consumption, deduplicated against native source sales and stack expansion. Forecast formulas are unchanged. Laravel accepts ordinary source forecasts without weighted-family metadata.

See [SOURCE_ONLY_FORECASTING_2026-10-29.md](SOURCE_ONLY_FORECASTING_2026-10-29.md) for the current contract, actual Python files changed, tests, historical limits and production blockers. The test results below are the historical 28 October snapshot; the revised two audit reproductions now exercise approved-source eligibility and confirmed physical consumption instead of obsolete weighted-order expectations.

## Audit remediation and test evidence

The independent report `INDEPENDENT_CODEBASE_AUDIT_2026-10-27.md` was reviewed. The seven PHP audit assertions run against the shared-source architecture. Expected physical quantities changed from extra-only beads to full shared-pool demand; safety assertions against duplicate consumption/restocking, phantom historical movements, disabled writes and unauthorized launches remain.

Implemented remedies cover I01 (guarded mutation boundary), O01/O02 (stable operation identity), O03/O04 (historical provenance and cutover), O05 (fast fulfillment and safe reversal review), O06 (partial/removed/re-added lines), O07 (pool serialization and atomic local completion), L01–L03 (launch stock semantics, execution checks, retry state and authorization), engraved L04 paths, P02/P03 (quiet ingestion and invalid eligibility), D01 (source identity), and D02 (deferred recovery). P01's external pipeline deployment remains outstanding. Existing ordinary-product activation behavior outside the engraved workflow is preserved; this is not a global publication-policy rewrite.

The exact implementation/change inventory, including the mapping stage retained in the intervening commit and formatting changes, is [shared-bead-files.txt](shared-bead-files.txt).

Final verification:

| Check | Result | Evidence |
| --- | --- | --- |
| Focused PHP workflow/regression suite | **127 passed, 695 assertions** | `storage/logs/shared-bead-focused-tests.log` |
| Seven independent PHP audit reproductions | **Passed**, included above | Same focused log; tests retain safety assertions with shared-pool units/cutover provenance |
| Separate-process serialization and remote-success/local-failure tests | **2 passed**, included above | Disposable SQLite databases and fake remote journal; no store calls |
| Pure Python shared-pool adapter | **5 passed** | `storage/logs/shared-bead-python-tests.log` |
| Full PHP suite | **824 passed, 35 failed, 5,031 assertions** | `storage/logs/shared-bead-full-suite.log` |
| Original external Python audit | **2 failed** | `storage/logs/shared-bead-external-python-audit.log`: unlisted eligibility remains false; expected 40 physical beads remains 10 in the unchanged external pipeline |
| Formatting and whitespace | Pint completed; `git diff --check` passed | `storage/logs/shared-bead-format.log` |

The full PHP failures are in collection-dropdown unit tests; material normalization; authentication/registration/verification/profile routes; product/collection approval fixtures missing required import IDs; a homepage response expectation; draft editing; product-workflow expectations; and legacy complementary-product/draft/stack UI tests attempting unmocked HTTP requests. These are recorded failures, not a green regression result. No separate pristine-baseline run was performed, so this report does not assert that every full-suite failure predates this work. The focused shared-bead, launch, inventory-webhook and stack reservation suites pass.

Network calls in tests are blocked globally with `Http::preventStrayRequests`; missing legacy mocks are reported rather than allowed to reach the live store.

## Checkout limitations and remaining verification

No checkout enforcement app or function was installed. The store is not Plus. Shopify documents custom Function-based checkout apps as Plus-only, while public-app capabilities depend on the app and plan; see [checkout app compatibility](https://help.shopify.com/en/manual/checkout-settings/checkout-customization) and [cart/checkout validation](https://shopify.dev/docs/apps/build/checkout/cart-checkout-validation).

A future eligible checkout integration must evaluate `Σ(member beads × cart quantity)` across every line in a family, including multiple versions and split lines. Webhook reservations and asynchronous tracking changes cannot guarantee protection against simultaneous checkouts. Source-ledger worker serialization does not make untracked Shopify checkouts atomic. This residual overselling risk is not claimed solved.

Other release gates: real Shopify API schema/permissions and idempotency behavior for the configured version; storefront/channel sold-out behavior; transitions at the actual fulfillment location; independent member-stock reconciliation and SKUs; return/restock intake coverage; warehouse review of reversals/uncertain operations; a MySQL migration rehearsal and concurrency run; shared-cache lease behavior after process termination; external Python integration; and unresolved full regression failures. Active mappings with outstanding or uncertain historical movements require explicit operator reconciliation, not blind remapping or counter edits.

## Production activation and rollback — not executed

1. Keep `ENGRAVED_BEAD_INVENTORY_WRITES=false` and `ENGRAVED_BEAD_STORE_CONTRACT_VERIFIED=false`. Do not change them as part of deploying this branch.
2. Back up the application database and rehearse all migrations on a copy, including the pending 26 October migration. Deploy schema before readers and restart long-lived workers only during an approved rollout.
3. Correct real SKUs and reconcile existing independent member inventories and old reservation/movement history. Establish an explicit approved cutover. Do not add all four member balances together.
4. In an authorized test store, verify source safety-stock ownership, on-hand preservation, member zero/tracking transitions, deny policy, channels, fulfillment location, return intake and uncertain-operation retries. Deliberately verify a supported API version of 2026-04 or newer without blindly changing the global version used by legacy services.
5. Integrate and verify the external Python contract. Establish checkout enforcement or explicitly accept the documented residual risk. Run local catalog discovery and review every family. Activation is a separate authorized operator action.
6. Provision dedicated workers: `queue:work inventory-ledger --queue=inventory-ledger --timeout=300` and `queue:work product-launch --queue=product-launch --timeout=900`, plus a shared atomic-lock cache and the scheduler. Review old queued job payloads/connections during rollout.
7. Only after those gates and explicit production authorization should flags and approved families be activated. Reconcile one family first, then inspect remote state and audit history before expanding.

For rollback, stop the new consumers and turn the engraved write/verification flags off. Preserve mappings, reservation counters, operation identities and audits. Do not run schema down-migrations over a live ledger. Reconcile uncertain remote effects first. Do not blindly release safety stock, reopen products, zero source stock, or replay historical events. Any necessary remote compensation requires an approved inventory reconciliation against the retained ledger.
