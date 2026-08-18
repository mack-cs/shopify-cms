# Procurement supplier orders and receiving

The CMS is the source of truth for incoming supplier orders. Shopify remains the source of truth for physical inventory, and Google Sheets is an operational projection/report.

## CMS workflow

On **Catalog → Inventory**, each SKU has:

- Current Shopify inventory
- Quantity on order and next ETA
- **Add Stock On Order**, **Receive Stock**, and **Order Details** actions

Adding an order requires Order ID, Quantity Ordered, and ETA. A SKU can have at most three outstanding orders because the existing Sheet contract exposes three phases. Receiving supports partial quantities and retains every receipt in the order history.

Receiving never reads inventory and writes an absolute replacement. The procurement queue sends Shopify's `inventoryAdjustQuantities` mutation with a positive delta and a unique receipt reference URI. After Shopify confirms it, the job refreshes local inventory, completes/project orders, and updates the operational Sheet cells. If post-processing fails, retrying the job skips the already-successful Shopify adjustment.

An adjustment whose result is ambiguous is moved to `manual_review`; it is not automatically retried, preventing accidental double receiving.

## CSV preview and confirmation

The Inventory page has **Upload Supplier Orders**, **Upload Receipts**, and **Confirm Supplier Import**.

Order CSV headers:

```csv
SKU,Order ID,Quantity Ordered,ETA
LRB0004,PO-1001,60,25/09/2026
```

Receipt CSV headers:

```csv
Order ID,SKU,Quantity Received
PO-1001,LRB0004,20
```

Upload only validates and stores a preview. Copy the preview ID from the notification into **Confirm Supplier Import**. Exact duplicate files resolve to the same batch, and a completed batch cannot process twice. Receipt confirmation queues work on the `procurement` queue.

An Order ID may contain several SKU lines in its first order CSV. After that new order is confirmed, any later pending-order upload containing the same Order ID is rejected during preview and checked again during confirmation. Use the receipt template—not the order template—to partially or fully fulfil an existing order.

Run the worker with:

```powershell
php artisan queue:work --queue=procurement --tries=1 --timeout=600
```

## Sheets ownership

Only `Ignore` is read from brand Sheets. Quantity on order, Order ID, ETA, totals, current inventory, projected inventory, and Last Updated are written from the CMS/Shopify data. Existing phase values are adopted into the supplier order ledger by the migration before this ownership change takes effect.

The immediate operational publish does not run ML and does not require a fresh prediction. Prediction fields remain untouched.

If an order was saved but Google Sheets was unavailable, retry only the operational projection (without ML and without touching Shopify) with:

```powershell
php artisan procurement:sheets-operational
```

## Deployment

1. Back up the database.
2. Run `php artisan migrate` (this adopts existing incoming phases).
3. If the store has more than one Shopify location, set `SHOPIFY_INVENTORY_LOCATION_ID` to the receiving location GID. Otherwise the first active location is used.
4. Restart the queue worker.
5. Add a small test order, confirm its Sheet projection, then receive a partial quantity and verify Shopify/local/Sheet inventory.
