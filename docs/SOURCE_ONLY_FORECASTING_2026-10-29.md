# Engraved bead forecasting correction — 29 October 2026

## Result and safety boundary

The actual external Python pipeline now forecasts **only each approved tracked 1-bead source**, using its existing ordinary-product workflow. The 2-, 3- and 4-bead products are not independent candidates, daily model inputs, forecast series or procurement recommendations. No Python family sales weighting or separate engraved forecasting model is used.

This report supersedes the forecasting integration requirements in `SHARED_ENGRAVED_BEAD_IMPLEMENTATION_2026-10-28.md`. That earlier report remains historical evidence of the previous, now-retired weighted adapter and test results.

Only code and isolated test fixtures were changed/run. No live Shopify requests, inventory/tracking changes, publications, activation actions, application database migrations, prediction publication, model training or operational pipeline execution were performed. Existing `.env` files, inventory-write and activation flags, raw operational feeds, processed operational datasets, trained models and output reports were not modified. The existing user modification in the external repository's `src/forecast.py` was preserved byte-for-byte, verified against its pre-change SHA-256.

## Candidate selection and unlisted eligibility

Previously, `src/clean_products.py` required active status, tracked inventory, known stock, a valid unique SKU, and a non-stack product. Unlisted sources were rejected. Those ordinary-product rules remain unchanged.

The targeted exception now permits an **approved active family's tracked source** with status `unlisted` or `active`. It preserves the real status and resolves approval from the CMS mapping's Shopify product, variant and inventory-item IDs, SKU, family tag, activation boundary, mapping hash and version. A quantity tag alone, product title, handle or SKU prefix cannot establish a source.

The `shared-beads-v1` JSON contract now explicitly declares `forecast_mode: ordinary_tracked_source`. It exposes only source identities and approval/provenance metadata, not the other three SKUs or sales multipliers. Laravel revalidates the complete four-member family against synchronized data before exporting its source. An inactive, unapproved, incomplete, incorrectly tracked or drifted family is excluded. All four member SKUs must be non-empty and unique; discovery now also checks normalized SKU uniqueness across the synchronized catalog.

The CMS ML products CSV excludes non-source bead members and invalid/unapproved bead groups, while preserving ordinary catalog exports and adding `Inventory Item ID` for identity validation. Python also rejects member candidates when supplied a complete diagnostic catalog, including members temporarily tracked to enforce sold-out status. Seven valid family fixtures produce seven candidates, not 28. No current live-store eligibility count is claimed: the live store was not queried.

Existing reservation provenance and source mappings were not changed. Tag/SKU drift prevents export; it does not redirect old physical movements. When an explicitly approved source SKU changes while its variant identity is retained, Python uses the existing exported `Variant id` to recognize native historical source sales rather than guessing from the former SKU.

## Historical demand: the verified gap and targeted fix

Actual pre-change flow:

`Shopify order lines → clean_orders → ordinary stack expansion → component_demand_order_lines → build_daily_sales → build_features → train_model / forecast`

`inventory_events.csv` contains availability-state events. It supports stockout monitoring; it is not a physical customer-consumption feed. `product_movement.csv` supplies validation/classification context and does not replace Python's demand observations. Thus a Laravel deduction from the source was **not automatically a sale** of the source SKU, and excluding the three member SKUs alone would omit their physical consumption.

The minimum implemented data-flow correction is a new authenticated CMS feed:

`/api/analytics/engraved-bead-consumption.csv?from=YYYY-MM-DD&to=YYYY-MM-DD`

Laravel emits only completed `consume` ledger movements with a positive already-measured physical quantity, immutable event identity, source variant/item/location snapshots, order identity and consumption timestamp. Mapping and cutover provenance must match an approved source. Failed/pending movements, historical synthetic baselines and native direct 1-bead records are excluded. No customer order quantity is multiplied in Python.

The downloader obtains this CSV alongside the source metadata JSON and existing feeds. `src/source_inventory.py` adds these physical source observations to the existing demand input before daily aggregation. Native direct 1-bead Shopify sales remain in their ordinary order-line path once. Source stack-component order expansion is excluded only for the approved source because Laravel's completed consumption already accounts for that physical shipment. Ordinary products and ordinary stack components retain their original expansion and quantities.

Duplicate physical event IDs count once; conflicting duplicates or mismatched item/location/SKU provenance fail validation. A source candidate without its consumption feed fails with an explicit history-gap error rather than producing a misleading native-sales-only forecast. Business-day boundaries use Africa/Johannesburg and the application's actual ledger timestamp timezone.

Receipts, manual corrections, transfers, safety-stock moves, reservations, releases, restocks and reversals are not turned into customer demand. A refund/restock does not create an extra sale. Source consumption enters on the confirmed movement date; native source sales retain their existing ordinary order-date/refund processing. This preserves the established methodology rather than redefining all historical demand semantics.

## Forecast methodology and ingestion

`src/forecast.py`, `src/build_features.py`, `src/train_model.py`, lead-time rules, stock-cover formulas, availability calendar, procurement classification and purchase recommendation formulas were not changed. `build_daily_sales.py` has one input classification change: confirmed source consumption joins the existing direct-unit channel. It is not a multiplier or a different model. Existing external integration tests continue to pass.

Laravel accepts an ordinary approved source prediction without weighted-family run metadata. It still rejects member predictions, invalid/unapproved sources, SKU drift and incorrect source identities. The obsolete weighted-forecast support flag requirement was retired; inventory safety flags were retained unchanged. Pipeline publication metadata identifies the source-only mode for diagnosis, without imposing a weighted-family model.

## Remaining demand-history limit

Confirmed post-cutover CMS consumption is now represented correctly in fixture-tested model inputs. **No missing pre-cutover consumption history was invented or backfilled.** If earlier multi-bead physical consumption was never recorded in the ledger, that history remains unavailable to source forecasting. Likewise, a missed fulfillment still awaiting CMS ledger reconciliation is not falsely counted as confirmed demand. The minimum next step for a historical gap is an explicitly approved, provenance-preserving physical-consumption backfill in Laravel, followed by rebuilding source inputs and retraining through the ordinary pipeline. This is not permission to replay inventory deductions or reconstruct demand through Python member weights.

## Exact files changed for this correction

Actual Python repository `D:\python_projects\leigh_ml_procurement_v1_with_incoming_stock`:

- `src/clean_products.py` — approved-source eligibility; no status falsification.
- `src/clean_orders.py` — retain existing exported variant identity for source SKU history.
- `src/source_inventory.py` — new source validation and already-physical consumption input.
- `src/expand_stack_components.py` — invoke that input correction, preserving ordinary expansion.
- `src/build_daily_sales.py` — classify confirmed consumption in the existing direct-unit path.
- `src/fetch_cms_inputs.py` — request the two additional feeds, accepting JSON for the mapping.
- `src/publish_cms_predictions.py` — source-only provenance metadata.
- `tests/test_source_inventory.py` — isolated tests of the actual pipeline functions.

`src/forecast.py` was already modified on entry and was not changed by this work.

Laravel/CMS repository:

- `app/Services/EngravedBeads/EngravedBeadProcurementContract.php`
- `app/Services/EngravedBeads/EngravedBeadFamilyDiscovery.php`
- `app/Http/Controllers/EngravedBeadProcurementContractController.php`
- `app/Services/Shopify/ShopifyAnalyticsExportService.php`
- `app/Services/ProcurementPredictionIngestService.php`
- `app/Services/ProcurementPythonRunner.php`
- `routes/api.php`, `config/procurement.php`, `.env.example`
- `integrations/procurement/shared_beads.py` — retired weighted adapter.
- `integrations/procurement/source_inventory.py` — source input module delivered to the actual pipeline.
- `scripts/update_source_forecasting.py` — reviewed, preimage-checked source update procedure; never invokes the pipeline.
- `tests/Feature/EngravedBeadForecastingContractTest.php`
- `tests/Feature/EngravedBeadAdministrationTest.php`
- `tests/Support/EngravedBeadFamilyFixture.php`
- `tests/test_source_forecasting_integration.py`
- `tests/independent_procurement_audit.py`
- `tests/test_shared_bead_procurement.py`
- This report and the supersession note/correction in `docs/SHARED_ENGRAVED_BEAD_IMPLEMENTATION_2026-10-28.md`.

## Verification and readiness

| Verification | Result |
| --- | --- |
| Actual external repository `python -B -m unittest discover -s tests -p 'test_*.py'` | **30 passed**: 16 existing integration tests and 14 source-input tests |
| Original two independent Python audit reproductions, revised for the confirmed architecture | **2 passed** (`storage/logs/source-forecast-audit-tests.log`) |
| Retired-adapter regression checks against the actual source-only replacement | **5 passed** (`storage/logs/source-forecast-adapter-tests.log`) |
| Laravel contract, procurement, incoming stock, supplier workflow, catalog synchronization, shared inventory, concurrency and stack regression suites | **224 passed, 1,197 assertions** (`storage/logs/source-forecast-regressions.log`) |
| Existing external `src/forecast.py` | SHA-256 matches the pre-change snapshot byte-for-byte |
| External source-input module | Matches the reviewed CMS-side delivery artifact byte-for-byte |
| Formatting/whitespace | Pint completed on changed PHP files; `git diff --check` passed in both repositories |

The two original audit cases were retained as meaningful reproductions, not deleted: a verified unlisted tracked **1-bead** source must enter candidates; and 40 confirmed CMS-consumed beads plus three native source units must produce 43 source demand units exactly once, irrespective of a deliberately unrelated member order quantity. Tests also prove seven candidates from 28 valid members, preserve ordinary products/stacks, reject incomplete/unapproved/drifted mappings, block missing/duplicate SKUs, reject conflicting event duplicates and missing consumption feeds, preserve SKU history by variant identity, exclude adjustments from demand, enforce local ledger day boundaries and accept ordinary source forecasts without weighted metadata.

Tests use temporary directories, mocked feed downloads, blocked network connections and isolated SQLite databases. Console messages such as “Refreshed raw/…” in the downloader test describe mocked destinations, not live downloads. No operational pipeline or production inventory test was used to obtain these results. Pandas emitted existing empty/all-NA concatenation FutureWarnings; they did not fail tests. The full unrelated Laravel suite was not rerun for this targeted correction; its previously reported 35 failures are not claimed resolved.

Release still requires schema/code coordination between repositories, current valid source/member SKUs, approved active mappings, real store sellability and inventory-safety verification, reviewed consumption-history coverage, and an explicitly authorized offline dataset rebuild/model run. None of those production actions was activated by this correction. The existing checkout/overselling and unrelated full-regression limitations in the earlier implementation report remain separate release gates.
