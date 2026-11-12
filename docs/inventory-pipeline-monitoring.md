# Inventory pipeline failure monitoring

Monitoring is disabled by default. No production settings are changed by this implementation.

## Architecture and scope

`shopify:inventory-health` scans local order events, fulfillment records, component reservations, movements, inventory-operation audits, engraved-bead configuration/family/sellability-operation records, failed jobs, and database queue latency. It checks expected component IDs against current draft configuration after a grace period, including empty configuration for identifiable stacks. Shopify event timestamps take precedence over arrival order. Configuration changes require investigation: this is not a historical bill-of-materials reconstruction.

Committed model transitions and Laravel `Queue::failing` capture failures between scheduled scans. Successful job callbacks and verified target states capture recovery. The scheduler remains the fallback when callbacks cannot run. Unknown/deleted job targets are not assumed repaired, and removing a failed-job row is not recovery evidence.

The existing read-only `StackReservationFulfillmentAuditService` supplies the daily live Shopify check. Its old scheduled email is disabled while this monitor is enabled; the legacy manual command remains available. No second inventory repair or retry system is introduced.

The monitor never invokes inventory mutations or processes, retries, deletes, releases, cancels, or repairs pipeline jobs. It writes only `inventory_pipeline_incidents` and `inventory_pipeline_alerts`.

## Delivery and deduplication

Email uses the existing Laravel mailer, sent synchronously by the scheduler command from a durable database outbox. No `inventory-ledger` worker or separate alert worker is required. Scheduled commands run in background processes so SMTP does not block the rest of the scheduler. SMTP has a configurable 30-second timeout; each delivery pass stops accepting new messages after 45 seconds and leaves its backlog for the next pass.

Incident identity is failure type + source table/type + record ID, not exception text or attempt number. Repeated observations update one open incident. Separate conditions (for example, a failed component movement, its failed adjustment audit, and an exhausted job) are separately actionable incidents. Reminder interval defaults to 24 hours; zero disables reminders. Recurrence after recovery creates a new generation. Failure/recovery deliveries retain chronological order even through a mail outage.

Database unique keys and consistent row-lock ordering serialize observations and delivery across workers. Use a transactional database with row locking in production. Delivery failures retain attempt count, error and next retry time. They are logged and cause a nonzero health-command exit. There is no retry limit that silently discards alerts.

Delivery is **at least once**, not exactly once: if a process dies after the mail server accepts a message but before the database commits, that email may be sent again. Ordinary retries and concurrent healthy workers are deduplicated. SMTP acceptance does not prove inbox delivery; monitor bounces/provider delivery status separately.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `INVENTORY_MONITOR_ENABLED` | `false` | Opt in after schema/mail verification |
| `INVENTORY_MONITOR_EMAIL` | `shonayimack@mackscs.com` | Recipient |
| `INVENTORY_MONITOR_PENDING_MINUTES` | `60` | Pending reservation/event/fulfillment/movement age |
| `INVENTORY_MONITOR_PROCESSING_MINUTES` | `15` | Processing/crash age |
| `INVENTORY_MONITOR_QUEUE_MINUTES` | `15` | Overdue available or reserved database jobs |
| `INVENTORY_MONITOR_SNAPSHOT_GRACE_MINUTES` | `10` | Allow normal reservation creation before snapshot checks |
| `INVENTORY_MONITOR_REMINDER_MINUTES` | `1440` | Unresolved reminder interval, `0` disables |
| `INVENTORY_MONITOR_DELIVERY_RETRY_MINUTES` | `5` | Mail failure retry interval |
| `MAIL_TIMEOUT` | `30` | SMTP timeout in seconds |

Pending age uses original creation time rather than retry-updated timestamps. Processing uses its processing-start timestamp where available. An open unfulfilled order legitimately holds pending reservations: choose a pending threshold matching warehouse operations. On first enable, existing failures and stale rows are intentionally reported, so inspect volume beforehand.

Environment comes from `APP_ENV`; use `production` / `staging` appropriately. `APP_URL` must be correct for CMS links. Times include ISO timezone offsets. Missing identifiers are explicitly unavailable. Emails contain operational metadata and error messages, not complete webhook/customer payloads.

## Schedules and commands

* Every minute: `php artisan shopify:inventory-health` (local reads and outbox delivery).
* Daily at **08:00 Africa/Johannesburg**: `php artisan shopify:inventory-health --live-audit` (also reads live Shopify fulfillment status through the existing audit).
* Both use single-server scheduler locks and overlap protection.

These commands are observation/delivery commands, not repair commands. The monitor scans all retained pipeline records; measure execution time against production volume and adopt retention/archiving or incremental scanning if needed. Queue inspection reflects the current database-backed inventory connections and their shared jobs table. If changing to Redis/SQS or splitting queue connections/tables, update queue inspection before relying on it.

## Deployment checklist — approval required before production changes

1. Review the diff, including unrelated working-tree changes separately. Run the automated tests below in staging and verify row-lock behavior with the production database engine.
2. Apply the additive migration through the normal approved deployment: `php artisan migrate --force`. This creates monitoring tables only; do not rollback or migrate-refresh a live inventory database.
3. Keep monitoring disabled until a real delivery mailer is configured. `log`/`array`, or a failover transport that falls back to `log`, do not establish email delivery. Verify recipient, sender, SMTP/SES credentials, provider timeout, `APP_ENV` and `APP_URL`. Verify no credentials appear in error messages.
4. Set the approved environment variables, including `INVENTORY_MONITOR_EMAIL=shonayimack@mackscs.com`, and enable monitoring. Set a warehouse-appropriate pending threshold. Rebuild config/route caches and restart existing workers through the normal release process so callbacks load.
5. Ensure the scheduler runs independently of inventory workers: `* * * * * cd /path/to/app && php artisan schedule:run >> /var/log/inventory-scheduler.log 2>&1`. Use a shared cache store for `onOneServer` / overlap locks. Background health commands append output to `storage/logs/inventory-pipeline-monitor.log`; configure log rotation and external monitoring for scan/delivery errors. A successful scheduler launcher exit does not establish that a background command succeeded.
6. In staging, stop the inventory worker, enqueue only a harmless synthetic test job, and verify queue-delay email delivery. Exercise a failure/recovery pair and a temporary SMTP outage. Do not manufacture production inventory failures.
7. Inspect `inventory_pipeline_incidents` and unsent `inventory_pipeline_alerts` (`attempts`, `delivery_error`, `available_at`) after enabling. Read the initial backlog before any manual recovery action. Monitoring itself never authorizes inventory repair.

Use an **external heartbeat/uptime monitor** for scheduler/database/mail infrastructure. A stopped scheduler or unavailable database cannot reliably email about its own outage. Webhooks that never reach the application require Shopify delivery-log/ingress monitoring. Encrypted job payloads or unknown job classes still generate exhausted-job alerts but may lack target metadata; recovery for these requires a successful callback with the same UUID. Manual successful work without such evidence is deliberately not inferred.

## Tests

Run with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` using the repository test harness (which enforces these safeguards):

```sh
php vendor/pestphp/pest/bin/pest tests/Feature/InventoryPipelineMonitoringTest.php tests/Feature/InventoryPipelineMonitoringCallbacksTest.php tests/Feature/ShopifyStackInventoryReservationTest.php tests/Feature/EngravedBeadReservationTest.php tests/Feature/IndependentCodebaseAuditTest.php --compact
```

Mail is faked; outbound HTTP is blocked except mocked read-only audit responses. Production database/mail behavior still requires the staging checks above.

Local validation: **99 tests passed, 597 assertions**, including 23 monitoring tests and the existing stack reservation, engraved-bead reservation, and independent audit regressions. Newly added PHP files were formatted with Pint. `git diff --check` passed. Callback tests use a freshly migrated in-memory database with real commits, avoiding unrelated legacy SQLite rollback migrations.
