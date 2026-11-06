# Independent audit — Leigh Avenue CMS

Audit date: 27 October 2026 (Africa/Johannesburg). Baseline commit: `444373d74558dc17a5663fd5af936d8f3ecadf6d`, **including the existing modified and untracked implementation files in the working tree**. This is a review of the actual working tree, not only committed code.

## Verdict

| Area | Assessment | Principal evidence |
|---|---|---|
| Product launch | **NOT READY** | L01–L04: incorrect inventory source, ineffective queued kill switch, incomplete retries, alternative activation path |
| Engraved bead procurement eligibility | **NOT READY** | P01–P02: Python excludes unlisted products; quiet imports leave eligibility stale |
| Engraved bead inventory writes | **NOT READY** | I01, O01–O04: write flag bypass, duplicate consumption/restocking, historical stock creation |
| Order reservation and fulfillment | **NOT READY** | O01–O06: retry, reversal, race, recovery and partial-cancellation gaps |
| Historical order safety | **NOT READY** | O03–O04: a historical baseline is treated as actually deducted stock |
| Overall deployment | **NOT READY** | Confirmed failing safety tests; do not enable engraved inventory writes before remediation |

No application fixes, feature flag changes, database migrations against the configured application database, publication, or live Shopify inventory adjustments were performed. Only audit tests, this report and test output files were added. Existing user changes were retained. No `.env` secrets were read. Production settings, remote stock and installed webhook subscriptions were not verified; configuration statements below describe code defaults and examples.

## Method and reproducibility

Inspected UI actions, approval hooks, models, migrations, inventory writers, webhook controllers, jobs, order import, forecasting integration and tests. Followed the configured default external procurement dependency at `D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock`; its source was read without running its operational pipeline. Whether a deployed installation uses that exact checkout requires confirmation.

PHP is not on the sandbox PATH. Used the installed Herd PHP 8.4 executable with tool-approved elevated execution. The test harness forces `APP_ENV=testing`, SQLite `:memory:` and a separate testing config-cache path before database refresh (`tests/TestCase.php:11–56`, `phpunit.xml:21–35`). New PHP tests additionally prevent stray HTTP and mock inventory calls. Python tests import actual dependency functions with a substituted configuration module and temporary fixture directories; they do not import its real configuration, read its `.env`, fetch inputs, train models or publish predictions.

Results:

| Run | Result |
|---|---|
| Existing `EngravedBeadConfigurationTest`, `EngravedBeadProcurementTest`, `EngravedBeadReservationTest`, `ProductLaunchTest` | **30 passed, 174 assertions** |
| Existing stack reservations, on-hand sync, inventory CSV import, procurement prediction and supplier-order workflow suites, together with the then-six audit cases | **84 passed, 6 failed, 529 assertions**; all six failures were new audit cases |
| Final `IndependentCodebaseAuditTest.php` | **7 failed, 27 assertions**, reproducing the defects below |
| `tests/independent_procurement_audit.py` | **2 failed**: unlisted candidate rejected; expected 40 physical beads, actual 10 |

The audit assertions express required behavior and intentionally remain red until fixes are implemented. They execute real services/functions with mocked external boundaries; they are not source-text tests. They do not establish multi-process concurrency or real Shopify API contract correctness.

Commands, from repository root:

```powershell
& 'C:\Users\shona\.config\herd\bin\php84\php.exe' vendor/pestphp/pest/bin/pest tests/Feature/EngravedBeadConfigurationTest.php tests/Feature/EngravedBeadProcurementTest.php tests/Feature/EngravedBeadReservationTest.php tests/Feature/ProductLaunchTest.php --compact
& 'C:\Users\shona\.config\herd\bin\php84\php.exe' vendor/pestphp/pest/bin/pest tests/Feature/IndependentCodebaseAuditTest.php --compact --colors=never
& 'D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock\venv\Scripts\python.exe' tests/independent_procurement_audit.py
```

Saved outputs: `storage/logs/independent-audit-tests.txt`, `storage/logs/independent-audit-regressions.txt`, `storage/logs/independent-audit-python.txt`. The complete repository suite was not run. Python emitted pandas/NumPy warnings as well as the two assertion failures; these warnings did not cause the failures.

## Actual execution paths

### Approval and launch

`ApprovalObserver::created` → `ProductImageApprovalWorkflowService::handleApprovalCreated` handles approval-related image work. The new launch action is separate: `ProductResource.php:2194–2231` bulk action → `ProductLaunchService::queue` → persisted run/items → `LaunchProductsOnShopifyJob::handle` → `ProductLaunchService::run/launchItem` → `ProductLaunchShopify::read` → optional `ShopifyInventoryAdjustmentService::setOnHand` → `activate` → `publish` → confirmation for each selected channel → local status/change log/item outcome.

A single product can be selected in the same bulk action. There is no separate row launch action in the reviewed implementation. Approval is rechecked per item, including current approval version through `Product::isApprovedByTwo`. Individual item exceptions are caught so other products continue. Run/item records preserve selected channels, actor, stock proposal and outcomes; stock writes also use the inventory audit recorder.

### Order inventory

`routes/web.php` order webhook → `ShopifyStackOrderWebhookController` (HMAC, topic validation, webhook-ID persistence) → `ProcessShopifyStackOrderEventJob` → order cache lock → `StackOrderFulfillmentInspector::liveOrder` (paginated current quantities) → stack mapping or `EngravedBeadOrderProcessor::prepareLine` → reservation snapshot → `StackInventoryMovementService` → Shopify quantity move `available → reserved`.

Fulfillment webhook → `ShopifyFulfillmentWebhookController` → persisted fulfillment → `ProcessShopifyStackFulfillmentJob` → same order lock → reservation lookup → movement `consume` → `inventoryAdjustQuantities(name: reserved, delta: -additional)` → local consumed counter. Shopify's original ordered unit is not explicitly deducted by this CMS path. Refund processing is embedded in **order** webhooks via `payload.refunds`, not a dedicated refund/return handler.

Historical bulk/JSONL order import uses `ShopifyOrderJsonlProcessor` and `ShopifyOrderUpsertService`; that path stores analytics records and does not invoke this reservation processor. Thus bulk import alone does not make additional reservations. Webhook replay and subsequent fulfillment processing are the hazardous historical paths.

### Procurement

Normal product save → `EngravedBeadConfiguration` stores denormalized configuration/quantity → shared Product eligibility scopes → inventory workspace/search, inventory CSV, supplier orders, selection/price/incoming-stock/sheets queries. Movement reports scale direct sales by bead count. `ProcurementPipelineService`/`RunProcurementPipelineJob` then invoke the **separate Python pipeline**, which cleans its own product catalog, expands order demand, trains/calculates forecasts and posts predictions back to Laravel. That second eligibility/demand implementation was not updated.

## Findings: product launch

### L01 — Available stock is reused as an absolute on-hand launch target

**Severity: High · Status: FAIL · Confirmed code defect.**

**Files/lines:** `app/Services/Shopify/ProductLaunchService.php:125–154,257–294`; `app/Services/ProductInventorySyncService.php:280–292`; `app/Services/ProductInventoryCsvExporter.php:75–84`.

**Expected:** Launch uses an explicitly approved on-hand target and leaves existing physical stock/reservations intact. Already-live products should not acquire an unrelated stock correction from launch.

**Actual/evidence:** Refresh sets `inventory_qty` to **available**, while launch sets `$proposed = $variant->inventory_qty` and sends it as **on_hand**. The separate `current_on_hand_quantity` is used only as a stale-data check. Example: physical on-hand 10, reserved 3, committed 0, available 7; a normal refresh produces local on-hand 10/`inventory_qty` 7. Launch sees matching snapshot 10, accepts committed 0, and sets on-hand to 7. Even an already ACTIVE/published product reaches this inventory plan before the already-live check. No pending adjustment is required to authorize the differing number; absence of a pending request is treated as sufficient.

**Impact:** Physical stock can be reduced by existing reserved/unavailable stock a second time. Launch can silently become a stock correction.

**Correction:** Use a distinct approved on-hand target/version, skip inventory on already-live products unless an explicit correction is authorized, and test reserved/damaged/safety stock as well as committed quantities.

### L02 — Disabling launch does not stop queued launches; transient failures do not get the declared retries

**Severity: High · Status: FAIL · Confirmed code behavior.**

**Files/lines:** `app/Services/Shopify/ProductLaunchService.php:25–29,64–99`; `app/Jobs/LaunchProductsOnShopifyJob.php:18–41`; `app/Models/ProductLaunchRun.php:39–46`.

**Expected:** A disabled launch switch blocks pending external publication; recoverable failures can resume the same persisted operation safely.

**Actual/evidence:** `product_launch.enabled` is checked only when queuing and displaying the action. `run()` never checks it. A previously queued run still writes stock/activates/publishes. `run()` catches each exception and saves `completed_with_failures`; the job returns successfully. Its `tries=3` does not retry those exceptions. A redelivered job exits because the run is finished. No retry control is present on the result page.

**Impact:** Operators cannot stop queued publication using the feature flag. API timeouts may leave stock applied or a product activated with incomplete publication and no automatic recovery. A fresh run creates a new inventory idempotency key instead of resuming the original item.

**Correction:** Check the switch immediately before external mutations; define a resumable item state machine and an explicit retry action retaining original operation keys. Expose confirmed partial progress, including activation before publication failure.

### L03 — Launch authorization exists in UI visibility, not the service contract

**Severity: Medium · Status: NEEDS REVIEW · Service gap confirmed; unauthorized HTTP exploit not demonstrated.**

**Files/lines:** `app/Filament/Resources/ProductResource.php:2198–2199,2218–2223`; `app/Services/Shopify/ProductLaunchService.php:25–61,64–99`; `app/Filament/Pages/ProductLaunchResult.php:24–30`.

**Expected:** The mutation boundary validates the acting user's launch permission and permissible publication IDs.

**Actual/evidence:** Queue accepts any nullable user ID and nonempty channel strings; it neither authorizes the actor nor validates IDs against configured choices. The result page checks permission and action visibility checks `ShopifyPushProducts`, but neither becomes service-level authorization. Audit case `AUDIT ignores an unauthorized launch request at the service boundary` queues successfully for a user without permission rather than throwing authorization failure. Bus is faked, so this reproduction does not publish anything.

**Impact:** Any future/internal caller can bypass UI restrictions; revoked permissions are not rechecked by workers. UI visibility alone should not be claimed as universal authorization. Filament's own action protection may prevent direct UI invocation and was not bypass-tested.

**Correction:** Enforce actor authorization and configured channel selection at the server mutation boundary; specify how revocation affects queued work and test the actual Livewire action with an unauthorized actor.

### L04 — Other product synchronization can still activate products outside launch

**Severity: High · Status: NEEDS REVIEW · Alternative write path confirmed; channel visibility depends on remote state.**

**Files/lines:** `app/Services/ProductShopifyUpdater.php:1451–1454`; `app/Services/NewProductDraftShopifyCreator.php:294–296`; `app/Services/Shopify/ProductLaunchService.php:223–251`.

**Expected:** If launch is the required publication gate, other sync/create actions must not bypass it.

**Actual/evidence:** General product sync maps CMS status into Shopify product update; draft creation also maps draft status into product creation. These are not routed through launch inventory/channel validation or the launch flag. An ACTIVE update to a product already assigned to a channel can restore sellability without this launch workflow. Approval alone did not trigger the new launch in tests, but that does not prove all activation paths are gated.

**Impact:** Launch approval/inventory safeguards are not a global publication policy.

**Correction:** Decide whether these older activation paths remain authorized business workflows. If launch is mandatory, centralize activation/publication permission and readiness checks. Verify all existing-channel scenarios in a test store.

## Findings: procurement and forecasting

### P01 — Python forecasting excludes the unlisted exception and undercounts physical bead demand

**Severity: High · Status: FAIL · Confirmed in the default local dependency.**

**Files/lines:** `app/Services/ProcurementPythonRunner.php:16–18,34–44`; `config/procurement.php:14`; external `D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock\src\clean_products.py:32–47,64–74`; `src\build_daily_sales.py:20–26`; `src\expand_stack_components.py:60–62`; `src\forecast.py:283–314,338–350`; CMS `app/Services/ProductMovementReportService.php:570–579`.

**Expected:** Eligible unlisted bead products reach forecasts, and 10 four-bead products produce 40 physical units of direct demand, with stack physical components included once.

**Actual/evidence:** Python only considers `status == active`; tags/engraved eligibility are not carried into candidate selection. Noncandidate demand is dropped before forecasts. Direct demand is assigned from raw order `quantity` without a bead multiplier. CMS movement report scaling does not replace that daily training series. Both executable Python audit tests fail: unlisted candidate is false; direct demand totals 10 instead of 40.

**Impact:** Inventory search and supplier orders may include a product that gets no forecast at all. Active multi-bead products have unscaled daily observations; stock requirements, predicted runout, preliminary order and additional order quantities are therefore unreliable. Movement labels/context and learned demand use inconsistent units.

**Correction:** Export a single validated eligibility/bead-count contract and use it throughout Python candidate selection and daily demand. Scale once before feature/forecast calculation; preserve physical stack component quantities. Rebuild forecasts after changing the source version. Verify the actual deployed pipeline path before deployment.

### P02 — Quiet imports bypass the hook that maintains procurement eligibility

**Severity: High · Status: FAIL · Confirmed by execution.**

**Files/lines:** `app/Models/Product.php:65–67,141–150`; `app/Services/Normalizer.php:129–138`; `app/Observers/ProductObserver.php:124–139`.

**Expected:** All product/tag imports recalculate eligibility; removing the only quantity tag immediately excludes an ordinary unlisted product, and adding it includes an eligible one.

**Actual/evidence:** Scopes query stored `engraved_bead_configuration` and `engraved_bead_quantity`; the recomputation is a model `saving` event. Normalizer's nonstaged update uses `saveQuietly()`, suppressing that event. The audit quiet-import test removes `4-engraved-beads`, saves quietly and finds the product **still eligible**. The inverse transition can remain excluded. Observer quiet normalization is another reason not to make correctness depend on this hook alone.

**Impact:** Imports, views, exports, supplier orders and scheduled refreshes consistently reuse potentially incorrect cached eligibility. Validation at order time reads tags afresh, so procurement and reservation behavior can disagree.

**Correction:** Explicitly compute derived fields in every ingestion/update path, or replace this cache with a reliable centralized ingestion contract; backfill and validate existing rows. Test tag addition, removal and invalid multiple tags through the actual import mode.

### P03 — Invalid multiple tags are not universally excluded from procurement

**Severity: Medium · Status: FAIL · Confirmed scope behavior.**

**Files/lines:** `app/Models/Product.php:161–180`; `app/Services/EngravedBeads/EngravedBeadConfiguration.php:73–87`; `app/Services/ProductMovementReportService.php:576–579`.

**Expected:** Products with multiple recognized quantity tags are rejected as invalid throughout procurement.

**Actual/evidence:** The **unlisted exception** demands stored valid configuration, but the active catalog branch and active/draft supplier-order branch have no invalid-bead exclusion. An active nonbundle product tagged `2-engraved-beads,4-engraved-beads` matches the standard scope; movement reports merely warn and leave unconverted demand.

**Impact:** Invalid products can still receive supplier orders and inconsistent demand calculations after becoming active/draft.

**Correction:** Make invalid participating configurations a common exclusion/validation rule across every branch, rather than only the unlisted exception. Clarify whether rejecting invalid configuration is intended only for the exception; the requested audit rule states it without that restriction.

## Findings: inventory and order lifecycle

### I01 — Engraved write flags do not guard existing reservation mutations

**Severity: Critical · Status: FAIL · Confirmed by execution.**

**Files/lines:** `app/Services/Shopify/StackOrderReservationService.php:223–239,263–302,310–319,390–401`; `app/Services/Shopify/StackFulfillmentInventoryService.php:77–109`; `app/Services/Shopify/StackInventoryMovementService.php:83–104`; `app/Services/EngravedBeads/EngravedBeadOrderProcessor.php:39,79–85,157–159,320–327`.

**Expected:** Turning off engraved inventory writes prevents additional-bead mutations, including fulfillment, updates and cancellation of existing records.

**Actual/evidence:** Flags gate preparation of a **new** bead reservation and refund reconciliation. Existing reservations bypass preparation and are handled by the generic stack paths. Neither movement execution nor fulfillment consumption checks bead flags. Audit test reserves six additional beads, disables writes, then fulfills one product: `consumed_quantity` becomes **3**, expected **0**. Existing releases/manual cancellation are likewise ungated by code inspection.

**Impact:** The documented rollout/rollback control does not stop inventory writes. Disabling the overall bead flag also fails to stop existing generic reservation processing. The flag is not a global ban on ordinary manual stock/supplier receipt/launch writers either.

**Correction:** Classify reservation origin explicitly and enforce the relevant switch immediately before every engraved mutation. Persist deferred events without falsely marking them reconciled; define safe replay and outstanding-reservation handling.

### O01 — A partial-fulfillment retry can deduct additional beads twice

**Severity: Critical · Status: FAIL · Confirmed by execution.**

**Files/lines:** `app/Services/Shopify/StackFulfillmentInventoryService.php:79–108,121–125`; `app/Services/Shopify/StackInventoryMovementService.php:26–39,110–135`.

**Expected:** Fulfillment identity makes a movement immutable; a local failure after stock consumption cannot change the retry's quantity or idempotency key.

**Actual/evidence:** Bead consumption is clamped to **current remaining stock before deduplication**, and the resulting quantity participates in `event_key`. Order three four-bead products → reserve nine extras. Fulfill two → consume six, leave three. If movement completes but fulfillment completion is not persisted, retry clamps six to three and derives a **different** event key/idempotency UUID. Audit reproduces that durable intermediate state: consumed becomes **9**, expected **6**. Existing duplicate test repeats a fully completed fulfillment, so its early exit hides this case.

**Impact:** Permanent early consumption of the still-unfulfilled product's three beads. Shopify's original commitment remains independent, so this is an additional CMS deduction, not a normal Shopify commitment.

**Correction:** Persist and deduplicate the immutable fulfillment/line operation before recomputing quantity. Resume the same operation after every external/local failure boundary. Use a unique source-event/action constraint independent of mutable amounts.

### O02 — Partial-refund replay can restock extra beads twice

**Severity: Critical · Status: FAIL · Confirmed by execution.**

**Files/lines:** `app/Services/EngravedBeads/EngravedBeadOrderProcessor.php:229–245`; `app/Services/Shopify/StackInventoryMovementService.php:26–39`.

**Expected:** The same refund line restores its additional quantity once, irrespective of later ledger state or webhook ID.

**Actual/evidence:** Restock amount is `min(requested, consumed - restocked)`, and that amount is part of deduplication. Consume nine extra beads, refund two products → restore six. Replay that refund through a distinct order-update event → remaining restockable is three, producing a new movement for three. Audit test gets **9 restocked**, expected **6**. Existing test refunds all consumed units, leaving zero; it cannot expose this defect.

**Impact:** Shopify available/on-hand inventory is inflated by three beads for an unchanged refund.

**Correction:** Give refund-line identity an immutable operation record/unique constraint; check it before remaining-quantity calculations. Test repeated partial refunds, multiple refunds and reordered refund payloads.

### O03 — Historical baseline can create stock that CMS never deducted

**Severity: Critical · Status: FAIL · Confirmed by execution.**

**Files/lines:** `app/Services/EngravedBeads/EngravedBeadOrderProcessor.php:98–109,233–245`; `app/Services/Shopify/StackOrderReservationService.php:350,377–378`.

**Expected:** Previously fulfilled historical units do not cause a new deduction or an unearned later restock. Historical observation must be distinct from CMS-applied consumption.

**Actual/evidence:** A historical partially fulfilled order of three four-bead products with one unfulfilled seeds `reserved_quantity=6, consumed_quantity=6` without a Shopify mutation, then actually reserves three more. A return of one historical product sees six “consumed” extras and calls `restockAvailable(3)`. The audit historical-refund case confirms that forbidden call. The existing historical test explicitly expects the synthetic consumed baseline, but does not test a refund against it.

**Impact:** Permanent stock creation on historical returns. The baseline cannot prove those extras were deducted previously by any system.

**Correction:** Store historical fulfilled baseline separately from applied reserve/consume amounts; restock only proven prior CMS deductions. Any legacy-consumption import requires an explicit reconciled provenance decision.

### O04 — An old historical fulfillment consumes the newly reserved remainder

**Severity: High · Status: FAIL · Confirmed by execution.**

**Files/lines:** `app/Services/Shopify/StackOrderReservationService.php:350,377–378`; `app/Services/Shopify/StackFulfillmentInventoryService.php:79–108`.

**Expected:** Historical fulfilled units cannot consume reservations created solely for the remaining open units.

**Actual/evidence:** With the same historical order (two fulfilled, one open), reserve only three extras. Deliver a previously unseen old fulfillment for two products. The service has no fulfillment-ID baseline/cutover allocation and clamps six to the remaining three, consuming them. Audit result: remaining reserved **0**, expected **3**.

**Impact:** The open product loses its reservation and stock is permanently consumed for an old event. A later real fulfillment may be recorded complete with no additional consumption because remaining is zero.

**Correction:** Record historical fulfillment identities/quantities and a cutover baseline; reconcile fulfillment allocation rather than using remaining quantity as permission to consume arbitrary events.

### O05 — Fulfillment reversal is discarded; recovery can confuse fast live orders with history

**Severity: High · Status: FAIL · Confirmed control flow; timing-dependent missing reservation.**

**Files/lines:** `app/Http/Controllers/ShopifyFulfillmentWebhookController.php:67–80`; `app/Services/Shopify/StackFulfillmentInventoryService.php:20–30`; `app/Services/Shopify/StackOrderReservationService.php:73–77`; `app/Services/EngravedBeads/EngravedBeadOrderProcessor.php:70–76,199–209`.

**Expected:** Fulfillment cancellation/reversal is processed as a transition, and live order/fulfillment arrival order does not silently lose additional consumption.

**Actual/evidence:** Once a fulfillment is locally completed, the controller labels any subsequent update a duplicate before saving its changed status/payload. Service also exits completed records first; non-success records only become ignored, with no compensating movement. Separately, when a queued order-create is first processed after the order has fully fulfilled, live status causes a historical skip. That skip lets later fulfillment processing proceed without a reservation. No order-age/cutover distinction proves this was historical rather than a rapidly fulfilled new order.

**Impact:** Reversals do not reconcile additional physical quantities; fast fulfillment can lose all additional accounting. Missing fulfillment webhooks can leave reservations active: order updates with live fulfilled status skip them instead of finalizing. The leftover audit can report this, but does not automatically repair it.

**Correction:** Implement versioned fulfillment transitions and explicit reversal semantics. Separate historical cutoff from current fulfillment status, and add authoritative order/fulfillment reconciliation for missed/out-of-order events. Confirm whether reversal should re-reserve or restore available stock according to warehouse handling.

### O06 — Some partial cancellations can retain reservations indefinitely

**Severity: High · Status: FAIL · Confirmed code path; exact Shopify edit payload needs fixture verification.**

**Files/lines:** `app/Services/Shopify/StackOrderReservationService.php:79–85,207–219,264–289`.

**Expected:** A removed or reduced order line releases precisely its unfulfilled additional reservation. A later valid quantity increase can reserve again.

**Actual/evidence:** Reconciliation iterates only payload line items. A reservation whose line is absent from the incoming payload is never compared. Even if the payload includes the line, absence from the live line map returns immediately rather than releasing it. After a full release, `STATUS_RELEASED` skips all later reconciliation for that reservation. The pending-consume heuristic also infers fulfillment from `current - unfulfilled` instead of explicit fulfillment allocations.

**Impact:** Some order edits/removals can strand extras or fail to reserve a subsequently restored quantity. Full cancellation does release all remaining reservations, but it does not cover these partial-edit branches.

**Correction:** Reconcile the union of live lines and stored reservations, classify explicit removal/cancellation, and allow legitimate target increases after release. Add recorded Shopify fixtures for removed lines, refund-only quantity changes and fulfillment/cancellation interleaving.

### O07 — Local movement completion is not protected against overlapping workers

**Severity: High · Status: NEEDS REVIEW · Concurrency gap confirmed; concurrent reproduction not executed.**

**Files/lines:** `app/Services/Shopify/StackInventoryMovementService.php:26–39,110–135`; `app/Services/Shopify/StackOrderReservationService.php:27–36`; `app/Services/Shopify/StackFulfillmentInventoryService.php:34–45`; `app/Jobs/LaunchProductsOnShopifyJob.php:18–22,29–31`; `config/queue.php:54,82`.

**Expected:** Remote deduplication and local ledger application are both exactly once even with overlapping workers, expired locks and retries.

**Actual/evidence:** Movement completion is checked before the external call. Later the transaction locks the reservation but does not lock/recheck movement completion before incrementing a counter. Two callers sharing a movement can both increment locally even if Shopify deduplicates the remote request. Order cache locks mitigate this only while valid and shared: lease is 300 seconds, jobs also have a 300-second timeout. Default database/Redis visibility is 90 seconds; `.env.example` sets database retry-after to 15000, so effective deployment must be checked. Launch uniqueness is per run, not per product across runs; no per-product launch mutex exists.

**Impact:** Local/remote quantities can diverge under overlap; distinct concurrent launch runs may race. Unique DB event/idempotency keys are useful but do not make counter application exactly once.

**Correction:** Atomically claim operations; in the final transaction lock/recheck movement completion before applying counters. Use worker/lease/visibility settings that cannot overlap normal jobs and share a lock backend. Test true concurrent workers and lock expiry.

## Mapping, deployment and passes

### D01 — Stock-pool and warehouse identity need an operational decision

**Severity: High · Status: NEEDS REVIEW · Current mapping behavior confirmed; business model unverified.**

**Files/lines:** `app/Services/EngravedBeads/EngravedBeadStockPool.php:6–16,20–38`; `app/Services/Shopify/StackOrderReservationService.php:331–344`; `app/Services/Shopify/ShopifyInventoryAdjustmentService.php:20–44`; `app/Services/Shopify/StackInventoryMovementService.php:42–44,85–102`.

**Expected:** Additional beads map to the correct physical stock pool and location, with ambiguity blocked.

**Actual/evidence:** The design uses the ordered variant's own item; separate SKUs do **not** share a pool. This is an implementation choice, not proof about physical warehouse stock. It requires exactly one tracked/mapped active variant at the initial check. Reservation creation then chooses the sole mapped variant, even if another mapped variant is untracked (safe failure rather than an adjustment in that case). Location is configured, else latest inventory snapshot, else the first Shopify location; the actual fulfillment location is not used to choose consumption. Movement passes the current variant object, so it uses that variant's current inventory-item ID rather than strictly enforcing the reservation's stored item ID.

**Impact:** Shared physical stock across SKUs cannot be accounted for; wrong warehouse selection or remapped variants can target the wrong inventory item/location. Missing/ambiguous initial mapping is safely blocked, but subsequent mapping drift is not.

**Correction:** Obtain a warehouse-approved pool/location model before writes. Persist an immutable inventory-item/location target, verify it before every write, and refuse fallback to an arbitrary location where multiple locations are possible. Clarify whether each SKU really owns independent loose-bead stock.

### D02 — Disabled-event replay and migration rollback are incomplete

**Severity: High · Status: FAIL · Confirmed state handling.**

**Files/lines:** `config/engraved_beads.php:8–9`; `app/Services/EngravedBeads/EngravedBeadOrderProcessor.php:79–85`; `app/Services/Shopify/StackOrderReservationService.php:42–44,95–99`; `app/Console/Commands/ReportEngravedBeadHistoricalOrders.php:11–33`; `database/migrations/2026_10_26_150000_add_product_launch_and_engraved_bead_support.php:89–113,116–127`.

**Expected:** Write-disabled events can later be reconciled deliberately, with no lost or duplicated effects. Rollback preserves enough operation history to reconcile already applied stock.

**Actual/evidence:** A blocked bead line is recorded but its event is marked completed; processing that same event after enabling writes returns immediately. A fresh update may create a reservation, but no dedicated replay mechanism is present. The “historical report” counts recorded lines/products; it does not discover all historical orders or rebuild missing state and refuses execution. Migration backfills via application model/parser and drops launch/line audit tables plus restocked counters on rollback; it does not reverse external Shopify effects.

**Impact:** Events during disabled periods may remain unaccounted for, especially if fully fulfilled before a fresh update. Rolling back schema can destroy the provenance needed to reconcile restocks and launch failures.

**Correction:** Add a dry-run reconciliation queue with explicit cutover and approval of proposed quantities; retain operation history through rollback. Deploy schema before readers/workers, restart long-lived workers after config/code changes, and stop incompatible workers before any rollback. Do not treat this migration's down method as an inventory rollback.

### V01 — Basic tag interpretation and additional-only arithmetic

**Severity: Low · Status: PASS (bounded).**

**Files/lines:** `app/Services/EngravedBeads/EngravedBeadConfiguration.php:18–23,40–60,73–106`; `app/Services/EngravedBeads/EngravedBeadOrderProcessor.php:98–109`; `app/Services/Shopify/StackInventoryMovementService.php:85–102`.

**Expected/actual:** One recognized tag selects 1–4 beads; multiple distinct quantity tags and unsupported numeric bead tags are invalid. Titles do not determine quantity. Initial reservation uses `(beads - 1) × live unfulfilled units`, reserving extras rather than permanently consuming them. A one-bead product makes no extra reservation. Quantity moves retain on-hand until fulfillment; reserved consumption removes physical extras while leaving Shopify's original commitment to Shopify.

**Evidence:** Existing configuration and initial/partial reservation tests pass. Missing item mapping and invalid tags cause blocked/invalid records without a stock mutation. These passes do not cover later replay, mapping drift or historical defects above.

**Business impact/correction:** Correct initial arithmetic is a sound base; retain these tests and add real transport-contract and lifecycle tests rather than changing Shopify's original one-unit behavior.

### V02 — Unlisted exception, ordinary exclusion and publication separation on normal saves

**Severity: Low · Status: PASS (bounded).**

**Files/lines:** `app/Models/Product.php:141–180`; `app/Filament/Resources/InventoryResource.php:106`; `app/Services/Procurement/SupplierOrderService.php:141,177`; `app/Services/Procurement/SupplierOrderCsvService.php:405`; `app/Services/ProductInventoryCsvExporter.php:44`; `app/Jobs/DailyShopifyInventoryRefreshJob.php:56–58`.

**Expected/actual:** On normally saved products with current derived fields, ordinary unlisted products are excluded and valid tagged unlisted nonbundle products enter the catalog/order scopes. Eligibility itself changes neither status nor sales-channel publication. Inventory search/export and supplier-order tests pass, including unchanged unlisted status. Shared scopes are used by selection export, incoming stock and Sheets paths as identified by code inspection.

**Evidence:** Four existing procurement feature tests pass. This is not an end-to-end forecast pass: P01/P02 invalidate a readiness claim across imports and scheduled forecasting. Existing `include_test_products` and nonbundle policies still narrow selection; confirm those intended business exclusions.

**Business impact/correction:** Keep the shared scope approach, fix its stale-field and external-pipeline gaps, and add import/refresh/Sheets round-trip tests.

### V03 — New launch explicitly confirms activation and selected channel publication

**Severity: Low · Status: PASS (bounded).**

**Files/lines:** `app/Services/Shopify/ProductLaunchService.php:176–220,223–251`; `app/Services/Shopify/ProductLaunchShopify.php:46–96`; `app/Jobs/LaunchProductsOnShopifyJob.php:34–41`.

**Expected/actual:** Two approvals and basic validation/mapping are required by the new service. ACTIVE and selected publication confirmation are separate. Untracked stacks skip stock assignment and do not enable tracking. One bad product does not stop other selected products; persisted results distinguish failure from success. Six existing launch tests pass, including stale stock and publication failure.

**Evidence/limits:** Stack “untracked” result is assumed from product kind; service does not verify or disable remote tracking if a stack is already tracked. Channel-state query reads first 50 publications without pagination, although selected-channel confirmation is explicit. Tests mock the inventory service, so they do not validate launch GraphQL inventory contracts or changing reserved stock.

**Business impact/correction:** Preserve separate confirmation and audit records; add tracked-stack mismatch, transport failure and inventory-state cases. Approval passing does not cure L01–L04.

### V04 — Several duplicate/history protections exist, but do not imply exactly-once lifecycle safety

**Severity: Low · Status: PASS (bounded).**

**Files/lines:** `app/Http/Controllers/ShopifyStackOrderWebhookController.php:38–55`; `database/migrations/2026_09_01_000000_create_stack_inventory_reservations.php:13,64–67,75–78`; `app/Services/Shopify/StackOrderReservationService.php:42–59,73–77`; `app/Services/Shopify/ShopifyInventoryAdjustmentService.php:447–466,507–527`.

**Expected/actual:** Webhook ID, reservation identity, movement event key and idempotency UUID have DB uniqueness. Completed identical events are skipped, older order events can be ignored, live cancellation releases outstanding extras and new fully fulfilled orders are not reserved. Inventory mutations carry persisted idempotency keys; sequential retries of the **same** immutable movement have a recovery mechanism after remote success/local failure.

**Evidence:** Existing stack and bead tests pass for covered duplicates, cancellation and fulfilled history. Shopify documents inventory idempotency support from 2026-01 and compare-and-set behavior; see [inventorySetQuantities](https://shopify.dev/docs/api/admin-graphql/2026-01/mutations/inventorysetquantities). No live Shopify schema/retention-window validation was performed.

**Business impact/correction:** Preserve the uniqueness/remote idempotency design, but fix mutable operation identity and local application races. Supplier receipts separately claim a receipt before adjustment and require manual review after ambiguous outcome (`app/Jobs/ProcessSupplierReceiptJob.php:33–41,50–54,84–87`), limiting blind duplicate receiving; that is not automatic reconciliation.

## Stock writers and related paths

Source search found these stock-quantity mutation boundaries and callers; tracking/policy changes are listed separately because they change inventory behavior without directly setting a quantity.

| Boundary | Writes / callers | Engraved write flag coverage |
|---|---|---|
| `ShopifyInventoryAdjustmentService.php:110–147` `decreaseOnHand` | Manual stock transaction impacts (`ManualStockTransactionService.php:126–130`); compare-and-set with operation key | No bead flag; independent manual workflow |
| Same service `151–195` `increaseAvailable` | Supplier receipt `ProcessSupplierReceiptJob.php:56`; positive available delta | No bead flag; ambiguity moves receipt to manual review |
| Same service `203–256` `restockAvailable` | Engraved refund reconciliation through movement service | Flag checked at refund caller only |
| Same service `265–322` `setOnHand` | Product launch; absolute CAS and idempotency | No engraved flag; launch flag only at queue entry |
| Same service `328–403` `decreaseAvailable` | Public service capability; no active caller found in reviewed application search | No bead flag |
| Same service `405–470` `moveQuantity` | Reserve/release through stack movement service | Existing bead reservations bypass bead flag |
| Same service `473–531` `consumeReserved` | Fulfillment through movement service | Existing bead reservations bypass bead flag |
| `ProductInventorySyncService.php:423–486,771–781` | Inventory sync job, approved inventory correction/push paths; absolute on-hand with `ignoreCompareQuantity=true` | Independent writer, no bead flag; can overwrite newer quantities if target is stale |
| `ProductInventorySyncService.php:197–207` and `BulkInventoryTrackingService` | Tracking/policy updates; bulk tracking delegates inventory sync | Independent write paths |

Local stock fields are also written by refresh/import/history processing (`ProductInventorySyncService`, `ProductInventoryCsvImporter`, `ShopifyInventoryUpsertService`, Normalizer/Variant ingestion). Those local writes can influence later absolute pushes even though import/refresh itself is not an extra-bead Shopify deduction. Product sync and stack sellability also alter policy/availability behavior. Therefore “engraved writes disabled” currently means neither a global Shopify stock freeze nor a reliable freeze of preexisting engraved reservations.

## Lifecycle coverage and remaining verification

| Event | Actual state |
|---|---|
| New order, unfulfilled | Correct initial extra-only reserve when flags/mapping allow |
| Order updates | Live quantities used; old event suppression exists, but payload-line iteration, released status and stale derived eligibility have gaps |
| Partial fulfillment | Basic amount correct; replay after partial local completion is unsafe (O01) |
| Full fulfillment | Covered sequential path completes; zero-remaining clamp can silently conceal missing historical/live accounting |
| Full cancellation | Releases remaining reservation; already consumed stock is not automatically restored without a restock signal |
| Partial cancellation | Target heuristic only; removed lines/released-to-readded lines unsafe (O06) |
| Refund without restock | Ignored by additional-bead restock path as intended |
| Return/restock | Only embedded refund line restock types `return`, `cancel`, `legacy_restock`; duplicate/historical restock unsafe (O02/O03) |
| Return without embedded refund | No dedicated return topic/controller found; physical return intake semantics need confirmation |
| Fulfillment reversal | Completed updates discarded; no compensating transition (O05) |
| Fulfillment before order | Retries if no reservation/state; fast fully fulfilled order can be marked historical and skipped (O05) |
| Disabled writes, then enable | Same completed event does not resume; fresh update may help only while open (D02) |
| Historical order bulk import | Analytics upsert only; no automatic stock mutation; webhook replay is not fully safe |

Not established by these tests: real Shopify mutation schema validation/permissions and idempotency retention, actual webhook subscription coverage/delivery settings, external fulfillment-location allocation, deployed lock/cache backend, current worker visibility/timeouts, multi-process races, warehouse physical pool semantics, or production schema migration duration. These are explicit verification gaps, not claims of observed production corruption.

The new migration adds columns/indexes and quietly backfills products in chunks of 200. It has no Shopify mutation in `up()`, but depends on current application classes during migration and lacks resumable backfill state. Code readers reference new columns immediately; schema-first rollout is required. The new feature flags/publication settings are not documented in `.env.example`; publication selection is empty by default, so channels must be deliberately configured. Long-lived queue workers need restart after config/code deployment. API defaults are 2026-01; changing versions globally requires checking legacy inventory sync and receipt mutations as well as new idempotent calls.

## Prioritized remediation plan

1. **Keep engraved inventory writes off and pause affected existing-reservation workers if an operational stop is required.** The flag alone does not stop existing writes. Do not blindly release or consume outstanding records. Inventory, refund and movement history must first be reconciled read-only to identify actual external effects.
2. **Fix immutable event identity and historical provenance first:** O01/O02 duplicate operations, O03 synthetic consumption/restocks and O04 historical fulfillment allocation. Make the seven PHP audit tests pass without weakening their expectations.
3. **Enforce the write boundary and implement replay/reconciliation:** I01/D02; add explicit deferred states, stable operation keys, cutoff rules, reversal transitions, missed-webhook recovery and partial-edit reconciliation (O05/O06). Test every external-success/local-failure boundary.
4. **Correct launch stock semantics and recovery:** approved on-hand target, existing-live skip policy, execution-time flag/authorization checks, resumable failures, per-product concurrency and remote stack tracking validation (L01–L04).
5. **Repair procurement end to end:** quiet-import derived fields, invalid-tag exclusions and Python candidate/demand units (P01–P03). Make both Python audit tests pass and validate runout/order quantities using a known 10 × 4 fixture plus stack physical quantities. Regenerate snapshots/forecasts with a new source version.
6. **Confirm pool and warehouse rules, then harden concurrency:** immutable item/location targets, shared locks, worker timeouts/visibility/leases, transactional operation claims and exactly-once local counters (D01/O07). Run actual multi-worker tests with fake Shopify responses.
7. **Deploy only after a staged, reversible validation:** schema-first migration rehearsal on a copy, representative old/new/open/fulfilled/canceled/refunded orders, verified subscription topics and queue consumers, and a read-only stock reconciliation report. Preserve audit/operation records during rollback. Reassess readiness separately for launch, procurement and inventory writes after the defects are fixed; passing existing tests alone is insufficient.
