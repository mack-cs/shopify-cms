# Inventory CSV approval

The Everyday Inventory **Import Stock CSV** action now follows the same approval and Shopify push flow as a single-item inventory correction.

Previously, this screen called `ProductInventoryCsvImporter::importFromPath()`: it staged local On Hand values, recorded snapshots and returned import counters. It did not create an approval request or dispatch a Shopify push. A separate `createApprovalFromPath()` method existed but was not connected to this screen and required a reason in each CSV row.

## User flow

1. Upload the physical inventory count file under **Everyday Inventory → Import Stock CSV**.
2. Enter **one reason for the file** and select an active approver with inventory-update permission. The requester cannot approve their own file.
3. Select **Request approval**. This creates one `file`/`csv` inventory adjustment request containing every validated variant and preserves the original filename. Local confirmed counts, pending-push flags and Shopify are unchanged at submission.
4. The selected approver opens **Inventory Approvals**, reviews the original/requested counts and the single file reason, and approves or rejects the entire request.
5. Approval uses `InventoryAdjustmentApprovalService::approve()` and the existing `InventorySyncJob` on `inventory-sync`, with one approval batch containing all variant IDs. Shopify receives each requested absolute On Hand count. The request becomes `APPLIED` only after all item states are confirmed by the existing completion checks.

Minimal CSV:

```csv
SKU,On Hand
LAB0184,0
LA0503,12
```

A CSV Reason column is unnecessary; the reason entered in the form applies to every row. A supplied row Reason column does not override the file-wide reason. Existing service callers that do not supply a file-wide reason retain per-row reason compatibility for historical/internal use.

Existing match columns (local/Shopify variant or product ID, handle, SKU) and stock-count aliases remain supported. Multi-variant products require an unambiguous variant/SKU. Inventory export files retain their matching IDs and On Hand column. Counts must be non-negative whole numbers, including zero. An optional valid `inventory_tracked=false` can request untracking without an On Hand value.

The entire file is validated before creating the request: unknown/ambiguous variants, duplicate variants (even through different identifiers), conflicting supplied SKU, missing/invalid/negative counts, invalid tracked values, and empty files are rejected without staging local stock or creating a partial approval. Errors identify the CSV row and appear in the upload form.

## Existing safety behavior

This changes the upload route, not the inventory mutation safety rules. The worker must be running. Uncertain previous writes, superseded approvals, changed Shopify baselines, location restrictions and missing confirmation can still block a SKU. The file flow does not clear those blocks or automatically retry/reconcile old inventory. The Super Admin discard action remains a separate explicit operation.

Shopify changes are not a cross-product atomic transaction: a later SKU can fail after earlier SKUs were confirmed. The request is not marked `APPLIED` unless all items pass the existing confirmation checks. Investigate partial/blocked outcomes rather than resubmitting files or bulk-retrying blindly. This task does not change the existing failed-job/request status handling.

No production jobs were executed and no deployment was performed. No new migration or queue configuration is needed for this change. Release through the normal approved process and restart long-running workers when deploying PHP changes. Existing staged imports are not automatically converted into approvals; a newly reviewed file request is needed for that workflow.

If the old import already replaced local counts, first complete a Shopify read to restore the confirmed baseline (resolve any uncertain-write block separately), then upload the count file through the new approval form. Do not assume an old staged value is a confirmed Shopify baseline.

The legacy staging helper remains for standalone/internal callers; the Everyday Inventory UI no longer uses it.

## Validation

```sh
php vendor/pestphp/pest/bin/pest tests/Feature/InventoryFileApprovalTest.php tests/Feature/InventoryAdjustmentApprovalWorkflowTest.php tests/Feature/ProductInventoryCsvImporterTest.php tests/Feature/ProductInventoryOnHandSyncTest.php tests/Feature/InventoryWorkspaceTabsTest.php tests/Feature/InventoryPendingPushCancellationTest.php --compact
```

Tests use guarded in-memory SQLite, fake uploads/mail/queues, and mocked Shopify responses. The end-to-end test explicitly runs the captured job against fake Shopify, verifies every SKU (including zero), confirms `APPLIED`, and checks that duplicate job execution does not send another adjustment.

Validation: **51 tests passed, 593 assertions**, including 15 new file-approval tests and existing single-item approval, CSV importer, On Hand sync, inventory UI and cancellation regressions. CSV/parser tests were formatted with Pint; `git diff --check` passed.
