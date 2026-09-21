# Phase 1 Procurement Prediction Integration

The CMS persists the detailed Product Movement snapshot and invokes the existing Python pipeline located at
`D:\python_projects\leigh_ml_procurement_v1`. Forecasting and stack expansion remain in Python.

## Daily sequence

At the configured South African time, the CMS:

1. Creates or reuses the day's idempotent Product Movement snapshot.
2. Generates the detailed movement rows and marks the snapshot completed.
3. Launches the configured Python executable with a fixed argument array.
4. Python downloads the completed movement snapshot and existing CMS inputs.
5. Python validates matches, expands stack sales, selects ML or weighted demand and produces predictions.
6. Python posts the complete validated payload to the authenticated CMS endpoint.
7. The CMS saves every row in one transaction and only then marks the run completed.

A failed run remains visible as failed and never replaces the latest successful report.

## CMS environment

```text
SHOPIFY_ANALYTICS_EXPORT_TOKEN=a-long-random-secret
PROCUREMENT_DEFAULT_LEAD_TIME_DAYS=56
PROCUREMENT_ATTENTION_HORIZON_DAYS=21
PROCUREMENT_ORDER_NOW_THRESHOLD=30
PROCUREMENT_STOCK_GAP_GRACE_DAYS=0
PROCUREMENT_PRODUCT_MOVEMENT_DAILY=true
PROCUREMENT_PIPELINE_DAILY=true
PROCUREMENT_DAILY_TIME=06:30
PROCUREMENT_TIMEZONE=Africa/Johannesburg
PROCUREMENT_MOVEMENT_MONTHS=6
PROCUREMENT_PYTHON_EXECUTABLE=D:\python_projects\leigh_ml_procurement_v1\venv\Scripts\python.exe
PROCUREMENT_PIPELINE_PATH=D:\python_projects\leigh_ml_procurement_v1
PROCUREMENT_PROCESS_TIMEOUT_SECONDS=7200
PROCUREMENT_QUEUE=procurement
PROCUREMENT_MOVEMENT_SOURCE_VERSION=product-movement-v2
```

The analytics token belongs to this CMS, not Shopify. Keep it out of source control. Python receives it through the
child-process environment rather than command arguments.

## Operations

Manual run:

```powershell
php artisan procurement:run
```

Optional calculation date:

```powershell
php artisan procurement:run --date=2026-08-04
```

Managers with `Is Manager` permission can also use **Run Now** on **Procurement Predictions**. The page defaults to
the latest successful run and shows generation time, movement snapshot time, model, lead time, attention horizon,
row count, warnings, filters and CSV/XLSX export.

Run a worker that includes the dedicated `procurement` queue. Its worker timeout must be at least 14,400 seconds, and
`DB_QUEUE_RETRY_AFTER` must be higher than the worker timeout so a long forecast cannot be claimed twice.

```powershell
php artisan queue:work --queue=procurement,default --timeout=14400
```

## Data contracts

The authenticated input is `GET /api/analytics/product-movement.csv`. It exposes only active, inventory-tracked,
non-stack variants with usable Shopify identifiers and SKU. Exclusion counts and reasons are saved in movement-run
settings and pipeline metadata.

The authenticated result endpoint is `POST /api/analytics/procurement-predictions`. A run UUID must already have
been created by the CMS. Rows are unique by run and Shopify variant ID and are persisted atomically.

Movement classifications are standardized to `FAST_MOVING`, `MEDIUM_MOVING`, `SLOW_MOVING`, `NO_SALES`, and
`NEW_PRODUCT`.

## Stack handling

The Product Movement report supplies movement, availability and validation context. Python's existing order-line
stack expansion supplies final component demand. CMS movement totals are not added to selected Python demand, so a
stack sale cannot be counted twice.

## Phase 1 limitations

- One global 56-day lead time
- No minimum order quantities
- No order multiples
- No stock already on order
- No expected delivery dates
- No partial-delivery tracking
- No product-specific lead times
- No lead-time groups
- Sale status is a warning only
- Preliminary Order Quantity is not a final supplier order
