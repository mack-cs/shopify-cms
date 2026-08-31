# Stack fulfillment inventory deductions

## Behaviour

When Shopify creates or updates a successful fulfillment, the CMS inspects only the line-item quantity in that fulfillment. Normal products are ignored. For a Stack line, every configured component quantity is multiplied by the fulfilled Stack quantity and deducted from Shopify `available` inventory at the fulfillment location.

Example: if a Stack contains X x1 and Y x2, fulfilling two Stacks deducts X x2 and Y x4.

Partial fulfillments are independent because each Shopify fulfillment has its own persisted record. A later fulfillment deducts only its own line-item quantities.

## Stack configuration

Stack associations remain on `NewProductDraft::bundle_product_ids` for compatibility. `bundle_component_quantities` stores rows containing `product_id` and `quantity`; old associations automatically behave as quantity 1.

The New Product draft editor shows **Component quantities per Stack** after associated products are selected. The Stack association CSV importer counts a repeated component SKU as another required unit, and the exporter repeats components according to their configured quantity.

Each component product must resolve to exactly one active local variant with `shopify_inventory_item_id`. Ambiguous or missing variants fail safely without guessing.

## Webhook and queue flow

1. Shopify sends `fulfillments/create` or `fulfillments/update` to `/webhooks/shopify/fulfillments`.
2. `ShopifyFulfillmentWebhookController` verifies the existing Shopify HMAC secret, upserts `shopify_fulfillments`, and dispatches `ProcessShopifyStackFulfillmentJob`.
3. `StackFulfillmentInventoryService` resolves the local Stack variant/draft and creates one `shopify_stack_component_deductions` row per fulfillment line/component.
4. `ShopifyInventoryAdjustmentService::decreaseAvailable()` calls `inventoryAdjustQuantities` with a negative delta, the fulfillment location, a reference URI, and Shopify's `@idempotent` directive.
5. Each successful component row is marked completed. Failures retain the error and are retried by the queued job.

## Duplicate safety

The database unique key covers local fulfillment, fulfillment line item, and configured component product. Each ledger row also owns a persisted UUID used as Shopify's idempotency key. A retry skips completed rows and sends failed/in-flight rows with the same key, including when Shopify succeeded but the worker stopped before saving the response.

The job runs up to five times with increasing backoff. Successful component adjustments are never repeated while another component is retried.

## Audit data

`shopify_fulfillments` records the Shopify order, fulfillment, location, webhook payload/status, attempts, errors, and processing timestamps.

`shopify_stack_component_deductions` records the Stack variant/product, fulfilled Stack quantity, configured component, component variant and inventory item, quantity per Stack, total deducted quantity, Shopify location, idempotency key, response, status, error, attempts, and timestamps.

## Cancellations, returns, and refunds

This workflow does not restore component inventory. Non-success fulfillment updates are ignored, and previously completed deduction rows remain immutable. Any future restoration workflow must use its own reversal ledger and Shopify idempotency keys rather than changing these deduction rows.

## Deployment

1. Deploy the code and keep the queue worker running.
2. Run `php artisan migrate --force`.
3. Confirm the Shopify app has `read_fulfillments` and `write_inventory` access.
4. Set `APP_URL` to the public HTTPS CMS origin and keep `SHOPIFY_WEBHOOK_SECRET` configured.
5. Run `php artisan shopify:register-stack-fulfillment-webhooks` once. The command safely skips matching subscriptions.
6. Configure component quantities on existing Stacks; legacy configurations default to one unit per component until edited.
7. Test with a dedicated Stack and partial fulfillment before fulfilling live Stack orders.

No historical fulfillment backfill is performed automatically. This intentionally avoids deducting inventory for fulfillments that predate deployment.
