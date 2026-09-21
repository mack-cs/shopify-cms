# Stack component inventory reservations

Configured Stack products are not inventory tracked. Their tracked component variants are reserved automatically:

- order create/update: Shopify `inventoryMoveQuantities` moves `available` to `reserved`;
- fulfilment: an idempotent `inventoryAdjustQuantities` decreases `reserved`, which decreases `on_hand` while leaving `available` unchanged;
- cancellation or quantity decrease: `inventoryMoveQuantities` moves the remaining `reserved` quantity back to `available`.

Every order/component reservation and every Shopify movement is retained in the database. The CMS **Stack Reservations** page under **Shopify Sync** exposes pending, completed, released, and failed rows. Normal products are ignored.

## Production rollout

The Shopify Admin API token needs `read_orders`, `read_inventory`, and `write_inventory`, and the app user needs permission to move/apply inventory changes. Set `SHOPIFY_API_VERSION=2026-01`, a correct `APP_URL`, `SHOPIFY_WEBHOOK_SECRET`, and preferably `SHOPIFY_INVENTORY_LOCATION_ID`.

Run after deploying:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan shopify:register-stack-fulfillment-webhooks
php artisan shopify:reconcile-stack-reservations
php artisan queue:restart
```

The reconciliation command is the one-time cutover step: it queries open Shopify orders, snapshots already fulfilled quantities, and reserves only their unfulfilled Stack components. It is safe to rerun because each order has a deterministic backfill event.

Keep the normal Laravel queue worker running. Failed Shopify calls are retried with the same persisted Shopify idempotency key. After retries are exhausted, inspect **Stack Reservations** and `failed_jobs`, correct the underlying inventory/configuration issue, then retry the failed job.

Run `php artisan procurement:sheets-operational` after a fresh Shopify inventory refresh to add/update the `Reserved` column in all configured procurement tabs.
