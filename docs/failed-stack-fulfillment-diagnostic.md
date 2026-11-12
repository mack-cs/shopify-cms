# Read-only missing-component fulfillment diagnostic

The command is `shopify:diagnose-failed-stack-fulfillments`. It does not run recovery, create reservations, update statuses, dispatch jobs, send mail, call Shopify, or persist reports. No additional tables or migrations are required. It uses the existing fulfillment/reservation/movement/order-event/legacy-deduction/adjustment-audit structures and the current shared stack matcher.

Production was not queried and no deployment was performed as part of implementing this command. Review and install the command and its dependencies through your approved release process before running it on production. Include the reviewed shared matcher/component-quantity methods; the report records SHA-256 fingerprints of the actual runtime inventory service files to identify which code produced it. Do not deploy unrelated working-tree changes implicitly.

## Run and export

From the production application directory, using the normal application runtime and database configuration:

```sh
php artisan shopify:diagnose-failed-stack-fulfillments
```

Export the full evidence as JSON (preferred for review):

```sh
php artisan shopify:diagnose-failed-stack-fulfillments --format=json --no-ansi > /tmp/failed-stack-fulfillments.json
```

Check the command exit status before using the file. A diagnostic failure returns nonzero and writes its error to stderr. The report file is created only by your shell redirection; the command never creates an export file itself. Treat exports as operational data containing order IDs, SKUs, error text and inventory references; use a private destination and appropriate permissions.

Narrow the report to an order number (quote `#`) or Shopify order ID:

```sh
php artisan shopify:diagnose-failed-stack-fulfillments --order='#5855' --format=json --no-ansi > /tmp/order-5855-fulfillment-diagnostic.json
php artisan shopify:diagnose-failed-stack-fulfillments --order=gid://shopify/Order/ACTUAL_ORDER_ID
```

Filter by a numeric or GID Shopify fulfillment ID:

```sh
php artisan shopify:diagnose-failed-stack-fulfillments --fulfillment=ACTUAL_FULFILLMENT_ID --format=json --no-ansi > /tmp/one-fulfillment-diagnostic.json
```

Replace uppercase placeholders with real IDs. There is no `--retry`, `--repair`, `--force`, or write mode. The command is deliberately not scheduled. A successful diagnostic exits zero even when it finds unsafe records; read the classifications and reasons.

## Discovery and grouping

Candidates are CMS fulfillment records currently marked failed with missing-component/reservation/snapshot errors, plus fulfillment records referenced by matching failed queue jobs even if their current error changed. Queue payloads are never unserialized or executed: only the scalar `fulfillmentId` is read from recognized job serialization. Encrypted/malformed/missing targets are reported as unlinked evidence requiring investigation.

Numeric and GID fulfillment aliases form one canonical business fulfillment. All stored alias records and previous attempts are reported, including an already-completed alias. The report includes all reservations and movements for the associated order, related fulfillment summaries, failed-job UUIDs, queued attempts, order-event states, legacy deductions and linked/correlated inventory audit records. It also looks for attempted unconfirmed movements in affected inventory-item/location pools on other orders.

Unlinked jobs cannot reliably be assigned to an order or fulfillment. They remain a **global** section even with filters; `matching_failed_jobs_global` is also global. Filtered fulfillment counts are separate. A non-database queue backend, unavailable queue history, or an unparseable queued fulfillment job prevents a potential-safe classification.

## Classification

| Classification | Meaning |
| --- | --- |
| `potential_safe_retry` | Current expected component snapshots are present; compatible inventory identities and sufficient local reservation quantities exist (or this fulfillment's consume event is already confirmed); counters agree with completed movements; no conflicting legacy deductions, attempted unconfirmed movements, alias conflicts or queued retries were found. **Live Shopify balances and adjustment history still need verification.** |
| `requires_inventory_reconciliation` | A movement may already have reached Shopify, quantities/counters disagree, numeric/GID consume keys may have deducted twice, historical attribution is unclear, legacy deductions may overlap, or adjustment evidence is inconsistent. Reconcile remote effects before retrying. |
| `requires_manual_investigation` | Evidence/configuration is missing or ambiguous, reservations are missing/incomplete, identity mapping is unusable, a related job is already active, or the engraved-bead boundary needs review. This does not mean manual database correction is necessary; first establish the supported code path and missing evidence. |

Reconciliation takes precedence when a group also has manual-investigation findings. Each group includes reasons, limitations, line-level expected/missing components, and movement details. Unattempted intents are distinguished from unconfirmed attempted operations, but neither is silently treated as proof of safety. A completed movement without stored Shopify confirmation is flagged. A reserved/consumed counter without matching completed movement history is not accepted as idempotency evidence; historical snapshot baselines are deliberately conservative.

The comparison uses **current** CMS draft configuration against the reservation snapshot. An edited draft can explain a mismatch; the command cannot reconstruct the exact historical configuration from fields that were never stored. Known ordinary products and packaging are skipped as non-stack lines; unidentified lines are investigated rather than silently assumed ordinary. Engraved-bead cutover, family mapping and write controls remain explicit review blockers in this narrowly scoped ordinary-stack retry assessment.

## Order event first, or fulfillment directly?

For a potential candidate, `recommended_sequence` is `fulfillment_directly_after_live_inventory_verification`. Existing confirmed consume movements are identified by both numeric and GID event hashes so the review can distinguish remaining work from an expected no-op completion.

For missing/incomplete reservations, `order_event_required_to_create_missing_snapshots` is true. The report lists `replayable_order_event_ids` currently pending/failed and explains that fulfillment processing does not create missing snapshots. **Replaying a completed order event is a no-op** under current code; a supported webhook/order-ingestion or explicit reprocessing path must be established before claiming automatic recovery. These rows are not classified potential-safe based on a guessed repair. Even a pending/failed order event may be ignored if a newer completed event exists; inspect its timestamp/history and live order state before planning that path.

For any reconciliation/manual finding, `recommended_sequence` is `do_not_retry_until_findings_are_resolved`. The report does not execute any proposed sequence.

## Read-only guarantees and limits

The Artisan entry point wraps data collection in database-enforced read-only transactions for MySQL/MariaDB and PostgreSQL, or SQLite `query_only` protection. MySQL/PostgreSQL use repeatable-read isolation. Connections are rolled back and SQLite settings restored on completion/error. Unsupported drivers and pre-existing non-SQLite transactions are refused. No inventory processors, mutation gateways, mailers, cache locks or queue dispatchers are invoked.

Only the default application and configured failed-job/inventory-queue database connections are opened. Separate database connections have independent snapshots, not one globally atomic snapshot. Use a read-only database account/replica as an additional operational safeguard if one exists; this command does not provision one. Inspect against an up-to-date source, and recheck immediately before any approved controlled retry because live workers may change state after the report.

All groups explicitly report `shopify_inventory_verified=false`. No Shopify requests of any kind are made. Therefore the command cannot prove physical inventory correctness, current remote reserved balances, current cancellation/fulfillment state, remote idempotency retention, or the absence of manual Shopify adjustments. Pending/failed movements with attempts greater than zero are treated as possibly applied even when their stored response is absent. The report also identifies the current 23-hour retry-age guard for those movements.

After producing the report, review it before selecting **one** conditional candidate. Establish the expected remaining deductions, verify live Shopify reservation/adjustment evidence, confirm no concurrent queued retry, and obtain approval for that controlled test. Do not bulk-retry aliases or reset counters/statuses to manufacture a candidate.

## Automated validation

```sh
php vendor/pestphp/pest/bin/pest tests/Feature/FailedStackFulfillmentDiagnosticTest.php --compact
```

Tests run under the repository's enforced in-memory SQLite harness with mail/jobs faked and outbound HTTP blocked. Coverage includes complete/missing/incomplete snapshots, two stacks with six components plus packaging, numeric/GID identities and consume keys, old unconfirmed movements, duplicate/legacy deductions, counter discrepancies, failed adjustment audits, cross-order pool risks, queued/orphan jobs, classification filters, JSON export, unchanged database rows and database-enforced rejection of accidental writes. Production MySQL/MariaDB/PostgreSQL behavior still requires the normal staging/runtime verification; local SQLite results do not establish production inventory safety.

Local results: **31 diagnostic tests passed (80 assertions)**. The combined diagnostic/monitoring/stack/engraved-bead/independent-audit run also passed **129 tests (674 assertions)** before the final table-rendering test was added; the final diagnostic rerun passed. New PHP files were formatted with Pint and `git diff --check` passed.
