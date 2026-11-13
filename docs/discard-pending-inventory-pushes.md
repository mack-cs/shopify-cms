# Discard pending inventory pushes

Super Admins now have **Discard pending pushes** on Everyday Inventory rows and on pending/approved/failed Inventory Approval rows. This is for cases such as LAB0184: Shopify was corrected manually, but an old unresolved local write intent freezes CMS On Hand and blocks later adjustments.

The form previews the affected SKUs and approval IDs, requires a reason, and asks the administrator to confirm that current Shopify stock should replace the local counts. Linked multi-SKU approvals are included in full rather than silently cancelling one item inside an approved bulk request. Selections expanding beyond 10 SKUs are refused for separate operational review.

On confirmation, the action:

1. Authorizes an active `SuperAdmin` again on the server.
2. Acquires the same per-variant locks used by inventory sync jobs. It refuses any affected reserved/running push and rechecks the preview before committing.
3. Queries current Shopify inventory, verifies product/variant/inventory-item identities, complete pagination/quantities, and locations referenced by unresolved intents. If the read fails, nothing is discarded.
4. In one application-database transaction, deletes exclusively affected waiting `InventorySyncJob` rows, cancels all affected pending/approved/failed approval requests, archives unresolved intents as `cancelled`, increments write versions, clears pending-push/errors, and copies freshly observed Shopify values into the CMS.
5. Retains mixed-SKU jobs for their unaffected SKUs. The worker filters cancelled SKUs from those jobs. Retried failed/deleted old batches are blocked by durable cancellation records. A genuinely new adjustment batch remains allowed.

There are **no Shopify inventory mutations** in this action. It does not consume, release, write off or modify stack reservations, fulfillment records or their inventory movements. It does not claim the original uncertain mutation failed or succeeded: the Super Admin explicitly adopts the current Shopify state. Original intent targets/errors, request details, job identifiers, actor, reason and the observed Shopify snapshot remain in `inventory_push_cancellations`. Original approval items and failed-job history are retained. Cancelled requests show `CANCELLED` and a cancellation explanation in their Review modal.

The displayed On Hand is the sum of returned Shopify locations, consistent with the inventory workspace. An unresolved intent whose location is not returned cannot be cleared. The action cannot stop a mutation already executing remotely; it refuses running jobs and is not a substitute for investigating an in-flight or stale reserved job. Future Shopify changes or newly authorized automation can still change inventory after the snapshot.

## Deployment and use

No production settings, jobs or stock were changed during implementation. Through the normal approved deployment:

* Apply migration `2026_11_13_140000_create_inventory_push_cancellations.php` before enabling the new action/workers.
* Ensure no old inventory-sync worker is still executing when releasing the new code. Restart workers so the shared lock and cancelled-batch checks are loaded. Use a shared, lock-capable cache across workers/servers.
* This action currently requires the database-backed `inventory-sync` queue on the application's own database connection, so queue deletions, cancellation barriers and local changes commit together. It refuses unsupported queue setups and unreadable push payloads. The `failed_jobs` table must also be readable.
* Log in as an active Super Admin. For LAB0184, choose **Discard pending pushes** from its Everyday Inventory row or its approval row. Review **all** listed SKUs/requests, enter a reason such as “Stock corrected in Shopify; discard stale adjustments”, acknowledge Shopify as the source of truth, and confirm.
* Verify On Hand and Pending Push in the CMS against Shopify at the correct location. If Shopify returns zero, CMS On Hand will become zero immediately as part of the action; a separate background refresh is unnecessary.

No queue restart, bulk retry, cache flush, or manual deletion of write-intent history is part of the action. Removing a waiting job does not release its old Laravel unique lock; it can expire naturally because that old batch is permanently fenced. New adjustments use new batches and are unaffected.

Run local tests with the repository's guarded SQLite-memory harness:

```sh
php vendor/pestphp/pest/bin/pest tests/Feature/InventoryPendingPushCancellationTest.php tests/Feature/InventoryAdjustmentApprovalWorkflowTest.php tests/Feature/ProductInventoryOnHandSyncTest.php --compact
```

Shopify is mocked in tests; production Shopify data is not used. Stage the migration, confirmation UI and multi-worker locking with the production database/cache drivers before production use.

Validation: **73 tests passed, 588 assertions**, including 14 new cancellation tests and the related approval, On Hand sync, inventory UI, webhook, monitoring and independent safety regressions. New PHP files were formatted with Pint; `git diff --check` passed.
