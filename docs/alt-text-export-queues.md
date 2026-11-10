Alt text export now runs through the existing Laravel database queue. The Products Alt Text menu persists the applied `collection.values` filter and returns after inserting the export and job. Shopify reads, immutable image snapshots, photograph downloads, CSV generation, and ZIP finalization all happen in the worker. There are no Shopify mutations.

The default connection is the existing `database` connection and the queue is the existing `DB_QUEUE` / `default` queue. `ALT_TEXT_EXPORT_QUEUE_CONNECTION` and `ALT_TEXT_EXPORT_QUEUE` may select an existing database connection/queue; there is no additional broker or queue backend. The queue database must be the application database so the export and job insert commit atomically. Inline drivers are rejected even if `QUEUE_CONNECTION=sync`.

The job has a 480-second timeout, 540-second lock lease, three exception retries, and no attempt limit for normal batch releases. The existing database connection's `retry_after` is at least 14520 seconds, which safely exceeds the job timeout and lock lease. Each worker invocation performs snapshot resolution, at most ten sequential downloads, or archive finalization. HTTP requests use a five-second connection timeout and at most fifteen seconds for headers plus fifteen seconds for streamed reading, with a five-second read timeout and 50 MiB per-photo cap. Files are read/written as streams or temporary files. Already-compressed photographs are stored in the ZIP without redundant recompression.

Supervisor is the production worker manager reported by the operator. **The deployed Supervisor configuration and process list were not available in this workspace, so deployment readiness is not verified.** No Supervisor changes, production migration, queue restart, or deployment was performed.

Before rollout, inspect the existing Supervisor program (do not add a second competing queue system):

```text
sudo supervisorctl status
sudo supervisorctl pid <existing-laravel-worker-program>
ps -p <pid> -o args=
```

Read that program's actual `.conf` and confirm:

- Its command consumes the `database` connection and `default` queue (or the existing configured alternatives above). Laravel uses this job's timeout rather than the generic worker default.
- `stopwaitsecs` exceeds 480 seconds (at least 540), retaining any higher existing value required by other jobs, with `stopasgroup=true`, `killasgroup=true`, `autostart=true`, and `autorestart=true`.
- It runs the current release, under the application user, with write access to private storage and temporary files, PHP ZIP support, and adequate disk capacity.
- Its configured cache supports shared atomic locks. The application default is the existing database cache/lock table. Use shared storage/cache when workers run on different hosts; the existing S3 disk is supported via `ALT_TEXT_EXPORT_DISK=s3` with private objects.
- The production queue configuration's `retry_after` exceeds 540 seconds. The application refuses export requests if this is unsafe.
- The scheduler runs as usual; `alt-text-exports:cleanup` is scheduled hourly. This removes expired metadata, snapshots, photographs, artifacts, and temporary files under the same export lock. Default retention is 24 hours.

The only schema change required for this feature is `2026_11_09_180000_create_image_alt_text_exports.php`. Apply it through the normal deployment process, and reload existing queue workers after deploying the job code. No unrelated migration is required by this change.

Status appears in the existing Products header-widget area and polls every five seconds. Downloads require authentication, the requesting user, Product Edit permission, an unexpired Completed record, and an existing artifact. The download route streams that file; it does not invoke any generation service. Failed photographs remain explicit rows in `manifest.csv` and a visible failure count. A completed archive with missing photographs can retry only those downloads; other successful photos and the exact snapshot are retained. A failed job can be retried from the same panel. Completed job replays are no-ops, concurrent jobs are locked, and equivalent active requests share one record/job.

Targeted verification uses Laravel's actual database queue and Worker via `queue:work database --once`, an isolated SQLite database, fake private storage, mocked Shopify membership reads, and mocked CDN responses. It does not use the sync driver or merely call the handler to stand in for queue execution. A production Supervisor smoke check is still required before calling the feature deployed and production-ready.
