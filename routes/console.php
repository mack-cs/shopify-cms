<?php

use App\Jobs\ReconcileComplementaryProductsJob;
use App\Jobs\ReconcileProductImageBackupsJob;
use App\Jobs\DailyShopifyInventoryRefreshJob;
use App\Jobs\Shopify\RunDailyShopifyPipeline;
use App\Jobs\Shopify\RunHistoricalShopifyOrdersImport;
use App\Jobs\Shopify\RunShopifyOrdersBackfill;
use App\Jobs\Shopify\StartShopifyInventoryBulkExport;
use App\Models\ShopifySyncRun;
use App\Models\NewProductDraftAssignment;
use App\Models\ShopifyAudit;
use App\Models\SiteAuditRun;
use App\Notifications\PendingWorkSlackReminderNotification;
use App\Services\AsyncJobStateService;
use App\Services\ShopifyApiClient;
use App\Services\ComplementaryProductMaintenanceService;
use App\Services\StackBundleSellabilityService;
use App\Services\StackSellabilityShopifyPushService;
use App\Services\StackSellabilitySlackNotifier;
use App\Services\SiteAudit\SitemapDiscoveryService;
use App\Services\SiteAudit\SiteAuditRunnerService;
use App\Services\GoogleSearchConsoleClient;
use App\Services\SearchConsoleCsvImporter;
use App\Services\SearchConsoleMetricImportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'seo:import-search-console-csv
    {file : CSV export path from Google Search Console}
    {--type=query : Import dimension: query, page, or site}
    {--label= : Period label, for example Jan 2026 or Apr-Jun 2026}
    {--start= : Optional YYYY-MM-DD period start}
    {--end= : Optional YYYY-MM-DD period end}',
    function (string $file): int {
        $type = strtolower(trim((string) $this->option('type')));
        if (!in_array($type, ['site', 'query', 'page'], true)) {
            $this->error('--type must be site, query, or page.');

            return self::FAILURE;
        }

        $start = trim((string) ($this->option('start') ?? '')) ?: null;
        $end = trim((string) ($this->option('end') ?? '')) ?: null;
        foreach (['start' => $start, 'end' => $end] as $name => $date) {
            if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("--{$name} must be formatted as YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        $label = trim((string) ($this->option('label') ?? ''));
        if ($label === '') {
            $label = $start && $end
                ? Carbon::parse($start)->format('M Y') . ' - ' . Carbon::parse($end)->format('M Y')
                : pathinfo($file, PATHINFO_FILENAME);
        }

        $result = app(SearchConsoleCsvImporter::class)->import($file, $type, $label, $start, $end);

        $this->info("Imported Search Console CSV into SEO period #{$result['period_id']} ({$label}).");
        $this->line("Rows: {$result['total']}; imported: {$result['imported']}; skipped: {$result['skipped']}.");

        return self::SUCCESS;
    }
)->purpose('Import a Google Search Console CSV export into SEO dashboard metrics.');

Artisan::command(
    'seo:pull-search-console
    {--type=site : Import dimension: site, query, page, or all}
    {--start= : Optional YYYY-MM-DD period start. Defaults to previous full month}
    {--end= : Optional YYYY-MM-DD period end. Defaults to previous full month}
    {--label= : Period label. Defaults to the imported month/date range}
    {--row-limit= : API page size. Defaults to SEARCH_CONSOLE_ROW_LIMIT}
    {--max-rows= : Maximum rows per dimension. Defaults to SEARCH_CONSOLE_MAX_ROWS}',
    function (): int {
        $timezone = (string) config('search_console.timezone', 'Africa/Johannesburg');
        $defaultMonth = now($timezone)->subMonthNoOverflow();
        $start = trim((string) ($this->option('start') ?? ''));
        $end = trim((string) ($this->option('end') ?? ''));

        $start = $start !== '' ? $start : $defaultMonth->copy()->startOfMonth()->toDateString();
        $end = $end !== '' ? $end : $defaultMonth->copy()->endOfMonth()->toDateString();

        foreach (['start' => $start, 'end' => $end] as $name => $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("--{$name} must be formatted as YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        if (Carbon::parse($start)->gt(Carbon::parse($end))) {
            $this->error('--start must be before or equal to --end.');

            return self::FAILURE;
        }

        $type = strtolower(trim((string) $this->option('type')));
        if (!in_array($type, ['site', 'query', 'page', 'all'], true)) {
            $this->error('--type must be site, query, page, or all.');

            return self::FAILURE;
        }

        $label = trim((string) ($this->option('label') ?? ''));
        if ($label === '') {
            $startDate = Carbon::parse($start);
            $endDate = Carbon::parse($end);
            $label = $startDate->isSameMonth($endDate)
                ? $startDate->format('M Y')
                : $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d');
        }

        $rowLimit = (int) ($this->option('row-limit') ?: config('search_console.row_limit', 25000));
        $maxRows = (int) ($this->option('max-rows') ?: config('search_console.max_rows', 100000));
        $dimensions = $type === 'all' ? ['site', 'query', 'page'] : [$type];
        $client = app(GoogleSearchConsoleClient::class);
        $importer = app(SearchConsoleMetricImportService::class);

        foreach ($dimensions as $dimension) {
            $this->info("Pulling {$dimension} Search Console rows for {$start} to {$end}...");

            $rows = $client->searchAnalyticsRows($start, $end, $dimension, $rowLimit, $maxRows);
            $result = $importer->importRows($rows, $dimension, $label, $start, $end);

            $this->line("{$dimension}: {$result['imported']} imported, {$result['skipped']} skipped, period #{$result['period_id']}.");
        }

        return self::SUCCESS;
    }
)->purpose('Pull Google Search Console Search Analytics data into SEO dashboard metrics.');

Artisan::command(
    'seo:backfill-search-console
    {--type=site : Import dimension: site, query, page, or all}
    {--from=2023-12 : First month to import, YYYY-MM or YYYY-MM-DD}
    {--to= : Last month to import, YYYY-MM or YYYY-MM-DD. Defaults to previous full month}
    {--include-current : Include the current partial month when --to is omitted}
    {--row-limit= : API page size. Defaults to SEARCH_CONSOLE_ROW_LIMIT}
    {--max-rows= : Maximum rows per dimension. Defaults to SEARCH_CONSOLE_MAX_ROWS}
    {--stop-on-error : Stop the backfill at the first failed month/dimension}',
    function (): int {
        $timezone = (string) config('search_console.timezone', 'Africa/Johannesburg');
        $now = now($timezone);
        $parseMonth = function (string $value, string $optionName) use ($timezone): ?Carbon {
            $value = trim($value);
            if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
                return Carbon::createFromFormat('Y-m-d', $value . '-01', $timezone)->startOfMonth();
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return Carbon::parse($value, $timezone)->startOfMonth();
            }

            $this->error("--{$optionName} must be formatted as YYYY-MM or YYYY-MM-DD.");

            return null;
        };

        $fromMonth = $parseMonth((string) ($this->option('from') ?: '2023-12'), 'from');
        if (!$fromMonth instanceof Carbon) {
            return self::FAILURE;
        }

        $toOption = trim((string) ($this->option('to') ?? ''));
        $toMonth = $toOption !== ''
            ? $parseMonth($toOption, 'to')
            : ((bool) $this->option('include-current')
                ? $now->copy()->startOfMonth()
                : $now->copy()->subMonthNoOverflow()->startOfMonth());

        if (!$toMonth instanceof Carbon) {
            return self::FAILURE;
        }

        if ($fromMonth->gt($toMonth)) {
            $this->error('--from must be before or equal to --to.');

            return self::FAILURE;
        }

        $type = strtolower(trim((string) $this->option('type')));
        if (!in_array($type, ['site', 'query', 'page', 'all'], true)) {
            $this->error('--type must be site, query, page, or all.');

            return self::FAILURE;
        }

        $rowLimit = (int) ($this->option('row-limit') ?: config('search_console.row_limit', 25000));
        $maxRows = (int) ($this->option('max-rows') ?: config('search_console.max_rows', 100000));
        $dimensions = $type === 'all' ? ['site', 'query', 'page'] : [$type];
        $client = app(GoogleSearchConsoleClient::class);
        $importer = app(SearchConsoleMetricImportService::class);
        $stopOnError = (bool) $this->option('stop-on-error');

        $summary = [
            'months' => 0,
            'dimensions' => 0,
            'imported' => 0,
            'skipped_empty' => 0,
            'failed' => 0,
        ];

        for ($month = $fromMonth->copy(); $month->lte($toMonth); $month->addMonthNoOverflow()) {
            $startDate = $month->copy()->startOfMonth();
            $endDate = $month->copy()->endOfMonth();
            if ($month->isSameMonth($now)) {
                $endDate = $now->copy()->subDay()->endOfDay();
            }

            if ($endDate->lt($startDate)) {
                $this->warn("Skipping {$month->format('M Y')}: no finalized days are available yet.");
                continue;
            }

            $label = $month->format('M Y');
            $start = $startDate->toDateString();
            $end = $endDate->toDateString();
            $summary['months']++;

            foreach ($dimensions as $dimension) {
                $summary['dimensions']++;
                $this->info("Pulling {$dimension} Search Console rows for {$label} ({$start} to {$end})...");

                try {
                    $rows = $client->searchAnalyticsRows($start, $end, $dimension, $rowLimit, $maxRows);
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $this->error("{$label} {$dimension} failed: {$exception->getMessage()}");

                    if ($stopOnError) {
                        return self::FAILURE;
                    }

                    continue;
                }

                if ($rows === []) {
                    $summary['skipped_empty']++;
                    $this->warn("{$label} {$dimension}: no rows returned; skipped.");
                    continue;
                }

                $result = $importer->importRows($rows, $dimension, $label, $start, $end);
                $summary['imported'] += $result['imported'];

                $this->line("{$label} {$dimension}: {$result['imported']} imported, {$result['skipped']} skipped, period #{$result['period_id']}.");
            }
        }

        $this->info(
            "Search Console backfill complete. Months: {$summary['months']}; dimension pulls: {$summary['dimensions']}; " .
            "rows imported: {$summary['imported']}; empty skipped: {$summary['skipped_empty']}; failed: {$summary['failed']}."
        );

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
)->purpose('Backfill Google Search Console Search Analytics into monthly SEO dashboard periods.');

if (config('search_console.auto_import_enabled')) {
    Schedule::command('seo:pull-search-console --type=site')
        ->monthlyOn(2, '03:00')
        ->timezone((string) config('search_console.timezone', 'Africa/Johannesburg'))
        ->withoutOverlapping()
        ->name('monthly-search-console-seo-import');
}

Artisan::command('slack:pending-work-reminder', function (): int {
    $channel = trim((string) config('services.slack.channels.reminders'));

    if ($channel === '') {
        $this->error('SLACK_REMINDER_CHANNEL or SLACK_BOT_USER_DEFAULT_CHANNEL is not configured.');

        return self::FAILURE;
    }

    NotificationFacade::route('slack', $channel)
        ->notify(new PendingWorkSlackReminderNotification());

    NewProductDraftAssignment::query()
        ->where('work_status', 'open')
        ->update(['last_slack_notified_at' => now()]);

    ShopifyAudit::query()
        ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
        ->where('status', ShopifyAudit::STATUS_FLAGGED)
        ->update(['last_notified_at' => now()]);

    $this->info("Slack pending-work reminder sent to {$channel}.");

    return self::SUCCESS;
})->purpose('Send the Slack report for open assignments, audit issues, and errors.');

Artisan::command('slack:assignment-complete {assignment_id}', function (string $assignment_id): int {
    $assignment = NewProductDraftAssignment::query()->find((int) $assignment_id);

    if (!$assignment instanceof NewProductDraftAssignment) {
        $this->error("Assignment #{$assignment_id} was not found.");

        return self::FAILURE;
    }

    app(\App\Services\NewProductDraftAssignmentService::class)->markCompleted($assignment);

    $this->info("Assignment #{$assignment->id} marked completed.");

    return self::SUCCESS;
})->purpose('Mark a Slack assignment completed so reminders stop including it.');

foreach (config('services.slack.reminder_times', []) as $time) {
    Schedule::command('slack:pending-work-reminder')
        ->dailyAt($time)
        ->timezone(config('services.slack.reminder_timezone', 'Africa/Johannesburg'))
        ->withoutOverlapping()
        ->name('slack-pending-work-reminder-' . str_replace(':', '', (string) $time));
}

Schedule::job(new ReconcileProductImageBackupsJob())
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::call(function (): void {
    app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::INVENTORY_CHECK);
    DailyShopifyInventoryRefreshJob::dispatch();
})
    ->name('daily-shopify-inventory-refresh')
    ->dailyAt('04:00')
    ->withoutOverlapping();

Artisan::command(
    'shopify:run-daily-pipeline
    {--date= : Optional YYYY-MM-DD business date. Defaults to yesterday in Africa/Johannesburg}
    {--scheduled : Mark the run as scheduler-created instead of manual}',
    function (): int {
        $date = trim((string) ($this->option('date') ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be formatted as YYYY-MM-DD.');

            return self::FAILURE;
        }

        $mode = (bool) $this->option('scheduled')
            ? ShopifySyncRun::RUN_MODE_SCHEDULED
            : ShopifySyncRun::RUN_MODE_MANUAL;

        RunDailyShopifyPipeline::dispatch($date !== '' ? $date : null, $mode);

        $this->info('Shopify daily pipeline queued.');

        return self::SUCCESS;
    }
)->purpose('Queue the daily Shopify orders and inventory bulk-sync pipeline.');

Schedule::command('shopify:run-daily-pipeline --scheduled')
    ->dailyAt('02:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping()
    ->name('shopify-daily-orders-inventory-pipeline');

Artisan::command(
    'shopify:orders-import-history
    {--force : Skip confirmation for the one-time full historical import}',
    function (): int {
        if (!$this->option('force') && !$this->confirm('Queue a full unfiltered Shopify orders historical import?')) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        RunHistoricalShopifyOrdersImport::dispatch();
        $this->info('Historical Shopify orders import queued.');

        return self::SUCCESS;
    }
)->purpose('Queue a full unfiltered Shopify orders bulk import.');

Artisan::command(
    'shopify:orders-backfill
    {business_date : Business date as YYYY-MM-DD}
    {--lookback= : Complete business-day lookback. Defaults to config/shopify_sync.php}
    {--force : Allow queueing even when another run already exists for the same date}
    {--capture-current-inventory : Also capture a late current inventory snapshot}',
    function (string $business_date): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $business_date)) {
            $this->error('business_date must be formatted as YYYY-MM-DD.');

            return self::FAILURE;
        }

        $lookback = $this->option('lookback');
        $lookbackDays = $lookback === null || $lookback === '' ? null : max(1, (int) $lookback);
        $exists = ShopifySyncRun::query()
            ->where('dataset', ShopifySyncRun::DATASET_ORDERS)
            ->whereDate('business_date', $business_date)
            ->whereIn('status', [
                ShopifySyncRun::STATUS_PENDING,
                ShopifySyncRun::STATUS_STARTING,
                ShopifySyncRun::STATUS_RUNNING,
                ShopifySyncRun::STATUS_DOWNLOADING,
                ShopifySyncRun::STATUS_PROCESSING,
            ])
            ->exists();

        if ($exists && !$this->option('force')) {
            $this->error("An orders sync is already active for {$business_date}. Use --force to queue another run intentionally.");

            return self::FAILURE;
        }

        RunShopifyOrdersBackfill::dispatch(
            $business_date,
            $lookbackDays,
            (bool) $this->option('capture-current-inventory'),
        );

        $this->info("Shopify orders backfill queued for {$business_date}.");

        return self::SUCCESS;
    }
)->purpose('Queue a deterministic Shopify orders backfill for a business date.');

Artisan::command(
    'shopify:inventory-snapshot',
    function (): int {
        $run = ShopifySyncRun::query()->create([
            'dataset' => ShopifySyncRun::DATASET_INVENTORY,
            'sync_type' => ShopifySyncRun::SYNC_TYPE_SNAPSHOT,
            'run_mode' => ShopifySyncRun::RUN_MODE_MANUAL,
            'business_date' => now((string) config('shopify_sync.timezone', 'Africa/Johannesburg'))->toDateString(),
            'business_timezone' => (string) config('shopify_sync.timezone', 'Africa/Johannesburg'),
            'status' => ShopifySyncRun::STATUS_PENDING,
        ]);

        StartShopifyInventoryBulkExport::dispatch($run->id);

        $this->info("Shopify inventory snapshot queued as sync run #{$run->id}.");

        return self::SUCCESS;
    }
)->purpose('Queue a current Shopify inventory bulk snapshot.');

Schedule::call(function (): void {
    app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::COMPLEMENTARY_RECONCILIATION);
    ReconcileComplementaryProductsJob::dispatch();
})
    ->name('daily-complementary-reconciliation')
    ->dailyAt('05:00')
    ->withoutOverlapping();

Artisan::command(
    'site-audit:run {--type=scheduled : Audit type: scheduled or manual}',
    function (): int {
        $type = (string) $this->option('type');
        if (! in_array($type, [SiteAuditRun::TYPE_SCHEDULED, SiteAuditRun::TYPE_MANUAL], true)) {
            $this->error("Invalid --type='{$type}'. Use scheduled or manual.");

            return self::FAILURE;
        }

        try {
            $this->info('Syncing sitemap URLs...');
            $count = app(SitemapDiscoveryService::class)->sync((string) config('site-audit.sitemap_url'));
            $this->info("Synced {$count} public URL(s).");

            $run = app(SiteAuditRunnerService::class)->run($type);
            $this->info("Site audit run #{$run->id} queued {$run->total_urls} URL check(s).");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            SiteAuditRun::query()->create([
                'type' => $type,
                'status' => SiteAuditRun::STATUS_FAILED,
                'started_at' => now(),
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $this->error('Site audit failed to start: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }
)->purpose('Fetch Shopify sitemap URLs and run the public URL audit.');

Artisan::command('site-audit:finalize', function (): int {
    $finalized = app(SiteAuditRunnerService::class)->finalizeRunningRuns();

    $this->info("Finalized {$finalized} site audit run(s).");

    return self::SUCCESS;
})->purpose('Finalize completed site audit runs.');

Schedule::command('site-audit:run --type=scheduled')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::command('site-audit:finalize')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command(
    'queue:flush-local-pending
    {--queue= : Only delete pending jobs for one queue name}
    {--include-failed : Also delete rows from failed_jobs}
    {--include-batches : Also delete rows from job_batches}
    {--force : Skip the safety confirmation in local}',
    function (): int {
        if (!app()->environment('local')) {
            $this->error('This command is restricted to the local environment.');

            return self::FAILURE;
        }

        $queueName = trim((string) ($this->option('queue') ?? ''));
        $includeFailed = (bool) $this->option('include-failed');
        $includeBatches = (bool) $this->option('include-batches');
        $force = (bool) $this->option('force');

        $jobsQuery = DB::table('jobs');
        if ($queueName !== '') {
            $jobsQuery->where('queue', $queueName);
        }

        $pendingCount = (clone $jobsQuery)->count();
        $failedCount = $includeFailed && DB::getSchemaBuilder()->hasTable('failed_jobs')
            ? DB::table('failed_jobs')->count()
            : 0;
        $batchCount = $includeBatches && DB::getSchemaBuilder()->hasTable('job_batches')
            ? DB::table('job_batches')->count()
            : 0;

        if ($pendingCount === 0 && $failedCount === 0 && $batchCount === 0) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        $parts = ["pending jobs: {$pendingCount}"];
        if ($includeFailed) {
            $parts[] = "failed jobs: {$failedCount}";
        }
        if ($includeBatches) {
            $parts[] = "job batches: {$batchCount}";
        }

        if (!$force) {
            $confirmed = $this->confirm(
                'Delete ' . implode(', ', $parts) . ($queueName !== '' ? " for queue '{$queueName}'" : '') . '?'
            );

            if (!$confirmed) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        $deletedPending = $jobsQuery->delete();
        $deletedFailed = 0;
        $deletedBatches = 0;

        if ($includeFailed && DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $deletedFailed = DB::table('failed_jobs')->delete();
        }

        if ($includeBatches && DB::getSchemaBuilder()->hasTable('job_batches')) {
            $deletedBatches = DB::table('job_batches')->delete();
        }

        $summary = ["Deleted {$deletedPending} pending job(s)."];
        if ($includeFailed) {
            $summary[] = "Deleted {$deletedFailed} failed job(s).";
        }
        if ($includeBatches) {
            $summary[] = "Deleted {$deletedBatches} job batch row(s).";
        }

        $this->info(implode(' ', $summary));

        return self::SUCCESS;
    }
)->purpose('Local only: delete old queued jobs so they cannot run later when the worker starts.');

Artisan::command(
    'shopify:refresh-inventory-readonly
    {--user-id= : Optional user ID to receive the completion notification}',
    function (): int {
        $userId = (int) ($this->option('user-id') ?? 0);

        app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::INVENTORY_CHECK);
        DailyShopifyInventoryRefreshJob::dispatch($userId > 0 ? $userId : null);

        $this->info('Daily Shopify inventory refresh job queued.');
        if ($userId > 0) {
            $this->line("Completion notification will be sent to user {$userId}.");
        }

        return self::SUCCESS;
    }
)->purpose('Read current inventory and product status from Shopify into local variants/products without pushing local changes to Shopify.');

Artisan::command(
    'inventory:enforce-stack-sellability
    {--dry-run : Show what would be changed without updating drafts or variants}
    {--test-only : Only allow stacks containing the test token to be changed}
    {--test-token=test : Token used by --test-only when matching title, handle, or SKU}
    {--require-test-components : With --test-only, also skip stacks unless all associated products contain the test token}
    {--refresh-components : Read associated component inventory from Shopify before enforcing}
    {--user-id= : Optional user ID recorded on inventory history snapshots}',
    function (): int {
        $dryRun = (bool) $this->option('dry-run');
        $refreshComponents = (bool) $this->option('refresh-components');
        $userId = (int) ($this->option('user-id') ?? 0);

        if ($dryRun && $refreshComponents) {
            $this->error('--refresh-components writes current Shopify inventory into local component records. Use either --dry-run or --refresh-components, not both.');

            return self::FAILURE;
        }

        $effectiveUserId = $userId > 0 ? $userId : null;
        $summary = app(StackBundleSellabilityService::class)->enforce(
            $effectiveUserId,
            $dryRun,
            [
                'test_only' => (bool) $this->option('test-only'),
                'test_token' => (string) ($this->option('test-token') ?? 'test'),
                'require_test_components' => (bool) $this->option('require-test-components'),
                'refresh_components' => $refreshComponents,
            ],
        );

        if (!$dryRun) {
            $summary['source'] = 'Manual stack sellability enforcement';
            $summary = app(StackSellabilityShopifyPushService::class)->queuePushForChangedStacks($summary, $effectiveUserId);
            app(StackSellabilitySlackNotifier::class)->notifyIfChanged($summary);
        }

        $this->info($dryRun ? 'Stack sellability dry run complete.' : 'Stack sellability enforcement complete.');
        $this->line("Checked drafts: {$summary['checked']}");
        $this->line("With associations: {$summary['with_associations']}");
        $this->line("All components sellable: {$summary['all_components_sellable']}");
        $this->line("Missing components: {$summary['missing_components']}");
        $this->line("Forced unsellable: {$summary['forced_unsellable']}");
        $this->line("Already unsellable: {$summary['already_unsellable']}");
        $this->line("Restored sellable/untracked: {$summary['restored_sellable']}");
        $this->line("Already sellable/untracked: {$summary['already_sellable']}");
        $this->line("Missing stack product: {$summary['missing_stack_product']}");
        $this->line("Skipped non-test stacks: {$summary['skipped_non_test_stack']}");
        $this->line("Skipped non-test/missing components: {$summary['skipped_non_test_components']}");
        $this->line("Skipped locked stacks: {$summary['skipped_locked_stacks']}");
        $this->line("Shopify component variants refreshed: {$summary['shopify_component_refreshes']}");
        $this->line("Shopify component refresh failures: {$summary['shopify_component_refresh_failures']}");
        $this->line("Stacks skipped after refresh failure: {$summary['shopify_refresh_failed_stacks']}");
        $this->line('Shopify push queued products: ' . (int) ($summary['shopify_push_queued_products'] ?? 0));
        $this->line('Shopify push queued variants: ' . (int) ($summary['shopify_push_queued_variants'] ?? 0));

        return self::SUCCESS;
    }
)->purpose('Force stack/bundle draft and variant stock from associated component sellability, then queue Shopify pushes for changed stacks.');

Artisan::command(
    'shopify:reconcile-complementary-products
    {--user-id= : Optional user ID to receive the completion notification}',
    function (): int {
        $userId = (int) ($this->option('user-id') ?? 0);

        app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::COMPLEMENTARY_RECONCILIATION);
        ReconcileComplementaryProductsJob::dispatch($userId > 0 ? $userId : null);

        $this->info('Complementary reconciliation job queued.');
        if ($userId > 0) {
            $this->line("Completion notification will be sent to user {$userId}.");
        }

        return self::SUCCESS;
    }
)->purpose('Refresh local Shopify inventory/status, repair complementary products, and record shortages.');

Artisan::command(
    'shopify:audit-complementary-products',
    function (): int {
        app(AsyncJobStateService::class)->markQueued(AsyncJobStateService::COMPLEMENTARY_AUDIT);

        try {
            $maintenance = app(ComplementaryProductMaintenanceService::class);
            $summary = $maintenance->runDailyCheck();

            $this->info('Complementary audit complete.');
            $this->line("Checked: {$summary['checked']}");
            $this->line("Recorded: {$summary['recorded']}");
            $this->line("Healthy: {$summary['healthy']}");
            $this->line("Flagged: {$summary['flagged']}");
            $this->line("Alerted: {$summary['notified']}");

            $flagged = \App\Models\ShopifyAudit::query()
                ->with('product')
                ->where('audit_type', \App\Models\ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
                ->where('status', \App\Models\ShopifyAudit::STATUS_FLAGGED)
                ->orderByDesc('last_checked_at')
                ->get();

            if ($flagged->isEmpty()) {
                $this->info('No products currently need complementary-product audit attention.');

                return self::SUCCESS;
            }

            $this->warn('Products still needing complementary-product audit attention:');
            foreach ($flagged as $audit) {
                $product = $audit->product;
                $title = trim((string) ($product?->title ?? '')) ?: ('Product #' . $audit->product_id);
                $this->line(sprintf(
                    '- %s | %s | local=%d | shopify=%d | valid=%d | checked=%s',
                    $title,
                    trim((string) ($product?->handle ?? '')),
                    (int) ($audit->local_saved_count ?? 0),
                    (int) ($audit->shopify_current_count ?? 0),
                    (int) ($audit->shopify_valid_count ?? 0),
                    optional($audit->last_checked_at)?->format('Y-m-d H:i:s') ?? 'never',
                ));
            }

            return self::SUCCESS;
        } finally {
            app(AsyncJobStateService::class)->markFinished(AsyncJobStateService::COMPLEMENTARY_AUDIT);
        }
    }
)->purpose('Run the complementary-product Shopify audit now and print the current audit state.');

Artisan::command(
    'shopify:list-metaobjects-for-definition
    {definitionId : MetaobjectDefinition GID (gid://shopify/MetaobjectDefinition/...)}
    {--first=50 : Max number of entries}',
    function (string $definitionId): int {
        $first = (int) $this->option('first');
        if ($first < 1) {
            $first = 50;
        }
        if ($first > 250) {
            $first = 250;
        }

        if (!str_starts_with($definitionId, 'gid://shopify/MetaobjectDefinition/')) {
            $this->error('definitionId must be gid://shopify/MetaobjectDefinition/...');
            return self::FAILURE;
        }

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $nodeData = $client->graphql(<<<'GQL'
                query MetaobjectDefinitionNode($id: ID!) {
                  node(id: $id) {
                    ... on MetaobjectDefinition {
                      id
                      name
                      type
                    }
                  }
                }
            GQL, [
                'id' => $definitionId,
            ]);

            $type = trim((string) data_get($nodeData, 'node.type', ''));
            $name = trim((string) data_get($nodeData, 'node.name', ''));
            if ($type === '') {
                $this->error('Could not resolve metaobject definition type from this definition ID.');
                $this->line(json_encode($nodeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return self::FAILURE;
            }

            $this->info("Definition: {$definitionId}");
            $this->line("Name: {$name}");
            $this->line("Type: {$type}");

            $metaobjectsData = $client->graphql(<<<'GQL'
                query MetaobjectsByType($type: String!, $first: Int!) {
                  metaobjects(type: $type, first: $first) {
                    nodes {
                      id
                      handle
                      displayName
                    }
                  }
                }
            GQL, [
                'type' => $type,
                'first' => $first,
            ]);

            $nodes = data_get($metaobjectsData, 'metaobjects.nodes', []);
            if (!is_array($nodes) || empty($nodes)) {
                $this->warn("No metaobjects found for resolved type '{$type}'.");
                return self::SUCCESS;
            }

            $this->info('Allowed metaobject entries:');
            foreach ($nodes as $node) {
                $this->line(implode(' | ', [
                    (string) data_get($node, 'id', ''),
                    (string) data_get($node, 'handle', ''),
                    (string) data_get($node, 'displayName', ''),
                ]));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Resolve a MetaobjectDefinition ID to its type, then list valid metaobject entry IDs.');

Artisan::command(
    'shopify:list-metaobjects-for-metafield
    {namespace : Metafield namespace (e.g. custom)}
    {key : Metafield key (e.g. pattern_category)}
    {--first=50 : Max number of entries per resolved type}',
    function (string $namespace, string $key): int {
        $first = (int) $this->option('first');
        if ($first < 1) {
            $first = 50;
        }
        if ($first > 250) {
            $first = 250;
        }

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $definitionData = $client->graphql(<<<'GQL'
                query MetafieldDefinition($namespace: String!, $key: String!) {
                  metafieldDefinition(ownerType: PRODUCT, namespace: $namespace, key: $key) {
                    validations {
                      name
                      value
                    }
                  }
                }
            GQL, [
                'namespace' => $namespace,
                'key' => $key,
            ]);

            $validations = data_get($definitionData, 'metafieldDefinition.validations', []);
            if (!is_array($validations) || empty($validations)) {
                $this->warn("No validations found for {$namespace}.{$key}.");
                return self::SUCCESS;
            }

            $definitionIds = [];
            foreach ($validations as $validation) {
                if (!is_array($validation)) {
                    continue;
                }
                $name = strtolower(trim((string) ($validation['name'] ?? '')));
                if (!str_contains($name, 'metaobject')) {
                    continue;
                }
                $value = trim((string) ($validation['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_string($item) && str_starts_with($item, 'gid://shopify/MetaobjectDefinition/')) {
                            $definitionIds[] = $item;
                        }
                        if (is_array($item)) {
                            foreach ($item as $nested) {
                                if (is_string($nested) && str_starts_with($nested, 'gid://shopify/MetaobjectDefinition/')) {
                                    $definitionIds[] = $nested;
                                }
                            }
                        }
                    }
                }

                if (str_starts_with($value, 'gid://shopify/MetaobjectDefinition/')) {
                    $definitionIds[] = $value;
                }

                if (preg_match_all('#gid://shopify/MetaobjectDefinition/[0-9]+#', $value, $matches)) {
                    foreach (($matches[0] ?? []) as $matched) {
                        $definitionIds[] = $matched;
                    }
                }
            }

            $definitionIds = array_values(array_unique($definitionIds));
            if (empty($definitionIds)) {
                $this->warn("No MetaobjectDefinition IDs found in validations for {$namespace}.{$key}.");
                $this->line('Validations:');
                $this->line(json_encode($validations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return self::SUCCESS;
            }

            $nodeData = $client->graphql(<<<'GQL'
                query MetaobjectDefinitionTypes($ids: [ID!]!) {
                  nodes(ids: $ids) {
                    ... on MetaobjectDefinition {
                      id
                      type
                      name
                    }
                  }
                }
            GQL, [
                'ids' => $definitionIds,
            ]);

            $nodes = data_get($nodeData, 'nodes', []);
            if (!is_array($nodes) || empty($nodes)) {
                $this->warn('Could not resolve metaobject definition nodes.');
                return self::SUCCESS;
            }

            foreach ($nodes as $node) {
                $defId = (string) data_get($node, 'id', '');
                $type = (string) data_get($node, 'type', '');
                $name = (string) data_get($node, 'name', '');
                $this->info("Definition: {$defId} | {$name} | type={$type}");

                if ($type === '') {
                    continue;
                }

                $metaobjectsData = $client->graphql(<<<'GQL'
                    query MetaobjectsByType($type: String!, $first: Int!) {
                      metaobjects(type: $type, first: $first) {
                        nodes {
                          id
                          handle
                          displayName
                        }
                      }
                    }
                GQL, [
                    'type' => $type,
                    'first' => $first,
                ]);

                $metaobjectNodes = data_get($metaobjectsData, 'metaobjects.nodes', []);
                if (!is_array($metaobjectNodes) || empty($metaobjectNodes)) {
                    $this->line("  No entries found for type '{$type}'.");
                    continue;
                }

                foreach ($metaobjectNodes as $metaobjectNode) {
                    $this->line('  - ' . implode(' | ', [
                        (string) data_get($metaobjectNode, 'id', ''),
                        (string) data_get($metaobjectNode, 'handle', ''),
                        (string) data_get($metaobjectNode, 'displayName', ''),
                    ]));
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Resolve metaobject type(s) from a product metafield definition and list matching entries.');

Artisan::command(
    'shopify:list-metaobjects
    {type : Metaobject type (e.g. pattern_category)}
    {--first=50 : Max number of entries}',
    function (string $type): int {
        $first = (int) $this->option('first');
        if ($first < 1) {
            $first = 50;
        }
        if ($first > 250) {
            $first = 250;
        }

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $result = $client->graphql(<<<'GQL'
                query MetaobjectsByType($type: String!, $first: Int!) {
                  metaobjects(type: $type, first: $first) {
                    nodes {
                      id
                      handle
                      displayName
                    }
                  }
                }
            GQL, [
                'type' => $type,
                'first' => $first,
            ]);

            $nodes = data_get($result, 'metaobjects.nodes', []);
            if (!is_array($nodes) || empty($nodes)) {
                $this->warn("No metaobjects found for type '{$type}'.");
                return self::SUCCESS;
            }

            foreach ($nodes as $node) {
                $this->line(implode(' | ', [
                    (string) data_get($node, 'id', ''),
                    (string) data_get($node, 'handle', ''),
                    (string) data_get($node, 'displayName', ''),
                ]));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('List Shopify metaobjects by type (id, handle, displayName).');

Artisan::command(
    'shopify:set-product-category {productId : Shopify Product GID} {categoryId : Shopify TaxonomyCategory/ProductTaxonomyNode GID}',
    function (string $productId, string $categoryId): int {
        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $result = $client->graphql(<<<'GQL'
                mutation SetProductCategory($productId: ID!, $categoryId: ID!) {
                productUpdate(input: {
                    id: $productId
                    category: $categoryId
                }) {
                    product {
                    id
                    category {
                        id
                        name
                    }
                    }
                    userErrors {
                    field
                    message
                    }
                }
                }
            GQL, [
                            'productId' => $productId,
                            'categoryId' => $categoryId,
                        ]);

            $userErrors = data_get($result, 'productUpdate.userErrors', []);
            if (!empty($userErrors)) {
                $this->error('Shopify returned userErrors:');
                foreach ($userErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $this->line("- [{$field}] " . ($error['message'] ?? 'Unknown error'));
                }

                return self::FAILURE;
            }

            $product = data_get($result, 'productUpdate.product');
            if (!$product) {
                $this->error('No product returned from Shopify.');
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            $this->info('Product category updated successfully.');
            $this->line('Product: ' . data_get($product, 'id', 'n/a'));
            $this->line('Category: ' . data_get($product, 'category.id', 'n/a'));
            $this->line('Category Name: ' . data_get($product, 'category.name', 'n/a'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
)->purpose('Set a Shopify product category using credentials from .env');

Artisan::command(
    'shopify:find-cat {search : Search text like bracelet, ring, necklace}',
    function (string $search): int {
        $query = <<<'GQL'
            query FindTaxonomyCategories($search: String!) {
            taxonomy {
                categories(first: 20, search: $search) {
                nodes {
                    id
                    name
                    fullName
                    isLeaf
                }
                }
            }
            }
            GQL;

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);
            $result = $client->graphql($query, ['search' => $search]);
            $nodes = data_get($result, 'taxonomy.categories.nodes', []) ?: [];

            if (empty($nodes)) {
                $this->warn("No categories found for: {$search}");
                return self::SUCCESS;
            }

            foreach ($nodes as $node) {
                $this->line(implode(' | ', [
                    (string) data_get($node, 'id', ''),
                    (string) data_get($node, 'name', ''),
                    (string) data_get($node, 'fullName', ''),
                    data_get($node, 'isLeaf', false) ? 'leaf' : 'branch',
                ]));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Find Shopify taxonomy categories by search text');

Artisan::command(
    'shopify:test-uvp
    {ownerId : Shopify Product GID (e.g. gid://shopify/Product/1234567890)}
    {--text= : UVP text for the rich text paragraph}
    {--namespace=custom : Metafield namespace}
    {--key=uvp_short_paragraph : Metafield key}
    {--verify : Query this exact metafield after write}',
    function (string $ownerId): int {
        $text = trim((string) ($this->option('text') ?? ''));
        $namespace = trim((string) ($this->option('namespace') ?? 'custom'));
        $key = trim((string) ($this->option('key') ?? 'uvp_short_paragraph'));
        $verify = (bool) $this->option('verify');

        if ($text === '') {
            $text = 'A compact, compelling UVP explaining why this product is unique and why customers should choose it.';
        }

        if ($namespace === '' || $key === '') {
            $this->error('Namespace and key are required.');
            return self::FAILURE;
        }
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            $this->error("Invalid key '{$key}'. Use only lowercase letters, numbers, and underscores.");
            return self::FAILURE;
        }

        $richTextValue = json_encode([
            'type' => 'root',
            'children' => [[
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'text',
                    'value' => $text,
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $result = $client->graphql(<<<'GQL'
                mutation UpdateProductUVP($metafields: [MetafieldsSetInput!]!) {
                  metafieldsSet(metafields: $metafields) {
                    metafields {
                      namespace
                      key
                      type
                      value
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
            GQL, [
                'metafields' => [[
                    'ownerId' => $ownerId,
                    'namespace' => $namespace,
                    'key' => $key,
                    'type' => 'rich_text_field',
                    'value' => $richTextValue,
                ]],
            ]);

            $userErrors = data_get($result, 'metafieldsSet.userErrors', []);
            if (is_array($userErrors) && !empty($userErrors)) {
                $this->error('Shopify returned userErrors:');
                foreach ($userErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $this->line("- [{$field}] " . ($error['message'] ?? 'Unknown error'));
                }

                return self::FAILURE;
            }

            $metafields = data_get($result, 'metafieldsSet.metafields', []);
            $this->info('UVP metafield set successfully.');
            $this->line('Owner: ' . $ownerId);
            $this->line('Namespace/Key: ' . $namespace . '.' . $key);
            $this->line('Text: ' . $text);
            $this->line('Returned metafields:');
            $this->line(json_encode($metafields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if ($verify) {
                $verifyResult = $client->graphql(<<<'GQL'
                    query CheckUVPMetafield($id: ID!, $namespace: String!, $key: String!) {
                      product(id: $id) {
                        id
                        uvpField: metafield(namespace: $namespace, key: $key) {
                          namespace
                          key
                          type
                          value
                          jsonValue
                        }
                      }
                    }
                GQL, [
                    'id' => $ownerId,
                    'namespace' => $namespace,
                    'key' => $key,
                ]);

                $this->line('Verified uvpField:');
                $this->line(json_encode(
                    data_get($verifyResult, 'product.uvpField'),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Test setting a product UVP rich text metafield via Shopify GraphQL');

Artisan::command(
    'shopify:test-product-metafields
    {ownerId : Shopify Product GID (gid://shopify/Product/...)}
    {--pattern=solid : Pattern category value (solid|multicolor)}
    {--pattern-type=single_line_text_field : Pattern metafield type (single_line_text_field|metaobject_reference)}
    {--complementary= : Comma-separated product GIDs for complementary products}
    {--pattern-namespace=$app : Namespace for pattern category metafield}
    {--pattern-key=pattern_category : Key for pattern category metafield}
    {--pattern-metaobject-id= : Metaobject GID for pattern category when --pattern-type=metaobject_reference}
    {--app-comp-namespace=$app : Namespace for app complementary metafield}
    {--app-comp-key=complementary_products : Key for app complementary metafield}
    {--std-comp-namespace=shopify--discovery--product_recommendation : Namespace for standard complementary metafield}
    {--std-comp-key=complementary_products : Key for standard complementary metafield}
    {--skip-app-complementary : Skip writing app-owned complementary metafield}
    {--skip-standard : Skip writing standard complementary metafield}',
    function (string $ownerId): int {
        $patternValue = strtolower(trim((string) ($this->option('pattern') ?? 'solid')));
        $patternType = strtolower(trim((string) ($this->option('pattern-type') ?? 'single_line_text_field')));
        $patternMetaobjectId = trim((string) ($this->option('pattern-metaobject-id') ?? ''));
        $patternNamespace = trim((string) ($this->option('pattern-namespace') ?? '$app'));
        $patternKey = trim((string) ($this->option('pattern-key') ?? 'pattern_category'));
        $appCompNamespace = trim((string) ($this->option('app-comp-namespace') ?? '$app'));
        $appCompKey = trim((string) ($this->option('app-comp-key') ?? 'complementary_products'));
        $stdCompNamespace = trim((string) ($this->option('std-comp-namespace') ?? 'shopify--discovery--product_recommendation'));
        $stdCompKey = trim((string) ($this->option('std-comp-key') ?? 'complementary_products'));
        $skipAppComplementary = (bool) $this->option('skip-app-complementary');
        $skipStandard = (bool) $this->option('skip-standard');

        $rawComplementary = trim((string) ($this->option('complementary') ?? ''));
        if ($rawComplementary === '') {
            $this->error('Provide at least one complementary product GID via --complementary.');
            $this->line('Example: --complementary="gid://shopify/Product/222222222,gid://shopify/Product/333333333"');
            return self::FAILURE;
        }

        $complementaryIds = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $rawComplementary)
        ), static fn (string $value): bool => $value !== ''));
        $complementaryIds = array_values(array_unique($complementaryIds));

        if (empty($complementaryIds)) {
            $this->error('No valid complementary product IDs were provided.');
            return self::FAILURE;
        }

        foreach ($complementaryIds as $id) {
            if (!str_starts_with($id, 'gid://shopify/Product/')) {
                $this->error("Invalid complementary product GID: {$id}");
                return self::FAILURE;
            }
        }

        if (
            $patternNamespace === '' ||
            $patternKey === '' ||
            $appCompNamespace === '' ||
            $appCompKey === '' ||
            $stdCompNamespace === '' ||
            $stdCompKey === ''
        ) {
            $this->error('Namespace and key options cannot be empty.');
            return self::FAILURE;
        }

        if (!in_array($patternType, ['single_line_text_field', 'metaobject_reference'], true)) {
            $this->error("Invalid --pattern-type '{$patternType}'. Allowed: single_line_text_field, metaobject_reference.");
            return self::FAILURE;
        }

        if ($patternType === 'single_line_text_field' && !in_array($patternValue, ['solid', 'multicolor'], true)) {
            $this->error("Invalid --pattern value '{$patternValue}'. Allowed for single_line_text_field: solid, multicolor.");
            return self::FAILURE;
        }

        if ($patternType === 'metaobject_reference') {
            if ($patternMetaobjectId === '' && str_starts_with($patternValue, 'gid://shopify/Metaobject/')) {
                $patternMetaobjectId = $patternValue;
            }
            if (!str_starts_with($patternMetaobjectId, 'gid://shopify/Metaobject/')) {
                $this->error('When --pattern-type=metaobject_reference, provide --pattern-metaobject-id=gid://shopify/Metaobject/...');
                return self::FAILURE;
            }
        }

        $complementaryJson = json_encode($complementaryIds, JSON_UNESCAPED_SLASHES);
        if ($complementaryJson === false) {
            $this->error('Failed to encode complementary product references.');
            return self::FAILURE;
        }

        $metafields = [[
            'ownerId' => $ownerId,
            'namespace' => $patternNamespace,
            'key' => $patternKey,
            'type' => $patternType,
            'value' => $patternType === 'metaobject_reference'
                ? $patternMetaobjectId
                : $patternValue,
        ]];

        if (!$skipAppComplementary) {
            $metafields[] = [
                'ownerId' => $ownerId,
                'namespace' => $appCompNamespace,
                'key' => $appCompKey,
                'type' => 'list.product_reference',
                'value' => $complementaryJson,
            ];
        }

        if (!$skipStandard) {
            $metafields[] = [
                'ownerId' => $ownerId,
                'namespace' => $stdCompNamespace,
                'key' => $stdCompKey,
                'type' => 'list.product_reference',
                'value' => $complementaryJson,
            ];
        }

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $setResult = $client->graphql(<<<'GQL'
                mutation SetProductMetafields($metafields: [MetafieldsSetInput!]!) {
                  metafieldsSet(metafields: $metafields) {
                    metafields {
                      namespace
                      key
                      type
                      value
                      createdAt
                      updatedAt
                    }
                    userErrors {
                      field
                      message
                      code
                    }
                  }
                }
            GQL, [
                'metafields' => $metafields,
            ]);

            $setErrors = data_get($setResult, 'metafieldsSet.userErrors', []);
            if (is_array($setErrors) && !empty($setErrors)) {
                $this->error('Shopify returned userErrors during metafieldsSet:');
                foreach ($setErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $message = (string) ($error['message'] ?? 'Unknown error');
                    $code = (string) ($error['code'] ?? '');
                    $suffix = $code !== '' ? " ({$code})" : '';
                    $this->line("- [{$field}] {$message}{$suffix}");
                }

                return self::FAILURE;
            }

            $this->info('metafieldsSet succeeded.');
            $this->line('Written metafields:');
            $this->line(json_encode(
                data_get($setResult, 'metafieldsSet.metafields', []),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            $readResult = $client->graphql(<<<'GQL'
                query ReadProductMetafields(
                  $id: ID!,
                  $patternNamespace: String!,
                  $patternKey: String!,
                  $appCompNamespace: String!,
                  $appCompKey: String!,
                  $stdCompNamespace: String!,
                  $stdCompKey: String!
                ) {
                  product(id: $id) {
                    id
                    handle
                    patternCategory: metafield(namespace: $patternNamespace, key: $patternKey) {
                      namespace
                      key
                      type
                      value
                      jsonValue
                    }
                    appComplementary: metafield(namespace: $appCompNamespace, key: $appCompKey) {
                      namespace
                      key
                      type
                      value
                      jsonValue
                    }
                    standardComplementary: metafield(namespace: $stdCompNamespace, key: $stdCompKey) {
                      namespace
                      key
                      type
                      value
                      jsonValue
                    }
                  }
                }
            GQL, [
                'id' => $ownerId,
                'patternNamespace' => $patternNamespace,
                'patternKey' => $patternKey,
                'appCompNamespace' => $appCompNamespace,
                'appCompKey' => $appCompKey,
                'stdCompNamespace' => $stdCompNamespace,
                'stdCompKey' => $stdCompKey,
            ]);

            $this->info('Read-back result:');
            $this->line(json_encode(
                data_get($readResult, 'product'),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Test Shopify pattern category + complementary products metafields via metafieldsSet and read-back query.');

Artisan::command(
    'shopify:update-variant-price
    {product : Product handle or Product GID (gid://shopify/Product/...)}
    {price? : New variant price, e.g. 24.99}
    {--sku= : Target SKU (recommended when product has multiple variants)}
    {--variant-id= : Target variant GID (gid://shopify/ProductVariant/...)}
    {--all : Update all variants on the product}
    {--sku-barcode= : Set Variant SKU and Variant Barcode to this exact same value}
    {--compare-at= : Set Variant Compare At Price}
    {--weight-unit= : Set Variant Weight Unit (g, kg, oz, lb)}
    {--weight= : Optional weight value used with --weight-unit}
    {--cost-per-item= : Set inventory item cost}',
    function (string $product, ?string $price = null): int {
        $priceInput = trim((string) ($price ?? ''));
        $hasPrice = $priceInput !== '';
        if ($hasPrice && !preg_match('/^\d+(?:\.\d{1,2})?$/', $priceInput)) {
            $this->error("Invalid price '{$priceInput}'. Use a numeric value with up to 2 decimals.");
            return self::FAILURE;
        }

        $targetSku = trim((string) ($this->option('sku') ?? ''));
        $targetVariantId = trim((string) ($this->option('variant-id') ?? ''));
        $updateAll = (bool) $this->option('all');
        $skuBarcode = trim((string) ($this->option('sku-barcode') ?? ''));
        $compareAtInput = trim((string) ($this->option('compare-at') ?? ''));
        $weightUnitInput = strtolower(trim((string) ($this->option('weight-unit') ?? '')));
        $weightInput = trim((string) ($this->option('weight') ?? ''));
        $costPerItemInput = trim((string) ($this->option('cost-per-item') ?? ''));

        if ($compareAtInput !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $compareAtInput)) {
            $this->error("Invalid compare-at '{$compareAtInput}'. Use a numeric value with up to 2 decimals.");
            return self::FAILURE;
        }
        if ($costPerItemInput !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $costPerItemInput)) {
            $this->error("Invalid cost-per-item '{$costPerItemInput}'. Use a numeric value with up to 2 decimals.");
            return self::FAILURE;
        }
        if ($weightInput !== '' && !preg_match('/^\d+(?:\.\d{1,6})?$/', $weightInput)) {
            $this->error("Invalid weight '{$weightInput}'. Use a numeric value.");
            return self::FAILURE;
        }

        $weightEnum = null;
        if ($weightUnitInput !== '') {
            $weightEnum = match ($weightUnitInput) {
                'g', 'gram', 'grams' => 'GRAMS',
                'kg', 'kilogram', 'kilograms' => 'KILOGRAMS',
                'oz', 'ounce', 'ounces' => 'OUNCES',
                'lb', 'lbs', 'pound', 'pounds' => 'POUNDS',
                default => null,
            };
            if ($weightEnum === null) {
                $this->error("Invalid --weight-unit '{$weightUnitInput}'. Allowed: g, kg, oz, lb.");
                return self::FAILURE;
            }
        }

        $hasAnyFieldUpdate = $hasPrice
            || $skuBarcode !== ''
            || $compareAtInput !== ''
            || $weightEnum !== null
            || $weightInput !== ''
            || $costPerItemInput !== '';
        if (!$hasAnyFieldUpdate) {
            $this->error('Nothing to update. Provide price and/or any of --sku-barcode, --compare-at, --weight-unit, --weight, --cost-per-item.');
            return self::FAILURE;
        }
        if ($weightInput !== '' && $weightEnum === null) {
            $this->error('When using --weight, also provide --weight-unit.');
            return self::FAILURE;
        }

        if ($targetSku !== '' && $targetVariantId !== '') {
            $this->error('Use either --sku or --variant-id, not both.');
            return self::FAILURE;
        }

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            if (str_starts_with($product, 'gid://shopify/Product/')) {
                $query = <<<'GQL'
query ProductByIdForPriceUpdate($id: ID!) {
  product(id: $id) {
    ... on Product {
      id
      handle
      title
      variants(first: 250) {
        nodes {
          id
          sku
          price
          compareAtPrice
          barcode
          inventoryItem {
            id
          }
        }
      }
    }
  }
}
GQL;
                $result = $client->graphql($query, ['id' => $product]);
                $productNode = data_get($result, 'product');
            } else {
                $query = <<<'GQL'
query ProductByHandleForPriceUpdate($handle: String!) {
  productByHandle(handle: $handle) {
    id
    handle
    title
    variants(first: 250) {
      nodes {
        id
        sku
        price
        compareAtPrice
        barcode
        inventoryItem {
          id
        }
      }
    }
  }
}
GQL;
                $result = $client->graphql($query, ['handle' => $product]);
                $productNode = data_get($result, 'productByHandle');
            }

            if (!is_array($productNode) || empty($productNode['id'])) {
                $this->error("Product not found for '{$product}'.");
                return self::FAILURE;
            }

            $productId = (string) ($productNode['id'] ?? '');
            $productHandle = (string) ($productNode['handle'] ?? '');
            $variantNodes = data_get($productNode, 'variants.nodes', []);
            if (!is_array($variantNodes) || empty($variantNodes)) {
                $this->error('No variants found for this product.');
                return self::FAILURE;
            }

            $variants = collect($variantNodes)
                ->filter(fn ($node) => is_array($node) && !empty($node['id']))
                ->values();

            if ($variants->isEmpty()) {
                $this->error('No valid variants found for this product.');
                return self::FAILURE;
            }

            $selected = collect();
            if ($updateAll) {
                $selected = $variants;
            } elseif ($targetVariantId !== '') {
                $selected = $variants->filter(
                    fn ($v) => (string) ($v['id'] ?? '') === $targetVariantId
                )->values();
            } elseif ($targetSku !== '') {
                $selected = $variants->filter(
                    fn ($v) => trim((string) ($v['sku'] ?? '')) === $targetSku
                )->values();
            } elseif ($variants->count() === 1) {
                $selected = $variants->take(1);
            } else {
                $this->error('Product has multiple variants. Pass --sku=<sku>, --variant-id=<gid>, or --all.');
                $this->line('Available variants:');
                foreach ($variants as $v) {
                    $this->line('- ' . (string) ($v['id'] ?? '') . ' | sku=' . (string) ($v['sku'] ?? '') . ' | current=' . (string) ($v['price'] ?? ''));
                }
                return self::FAILURE;
            }

            if ($selected->isEmpty()) {
                $this->error('No matching variant found for provided selector.');
                return self::FAILURE;
            }

            $inputs = $selected
                ->map(function ($v) use ($hasPrice, $priceInput, $skuBarcode, $compareAtInput, $weightEnum, $weightInput, $costPerItemInput): array {
                    $input = [
                        'id' => (string) ($v['id'] ?? ''),
                    ];

                    if ($hasPrice) {
                        $input['price'] = number_format((float) $priceInput, 2, '.', '');
                    }
                    if ($skuBarcode !== '') {
                        $input['barcode'] = $skuBarcode;
                    }
                    if ($compareAtInput !== '') {
                        $input['compareAtPrice'] = number_format((float) $compareAtInput, 2, '.', '');
                    }
                    if ($skuBarcode !== '' || $weightEnum !== null || $costPerItemInput !== '') {
                        $inventoryItem = [];
                        if ($skuBarcode !== '') {
                            $inventoryItem['sku'] = $skuBarcode;
                        }
                        if ($costPerItemInput !== '') {
                            $inventoryItem['cost'] = (float) number_format((float) $costPerItemInput, 2, '.', '');
                        }
                        if ($weightEnum !== null) {
                            $inventoryItem['measurement'] = [
                                'weight' => [
                                    'unit' => $weightEnum,
                                    'value' => (float) ($weightInput !== '' ? $weightInput : '0'),
                                ],
                            ];
                        }
                        if (!empty($inventoryItem)) {
                            $input['inventoryItem'] = $inventoryItem;
                        }
                    }

                    return $input;
                })
                ->values()
                ->all();

            $mutation = <<<'GQL'
mutation ProductVariantsBulkPriceUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants {
      id
      sku
      barcode
      price
      compareAtPrice
      inventoryItem {
        id
        sku
        unitCost {
          amount
          currencyCode
        }
        measurement {
          weight {
            value
            unit
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

            $mutationResult = null;
            if (!empty($inputs)) {
                $mutationResult = $client->graphql($mutation, [
                    'productId' => $productId,
                    'variants' => $inputs,
                ]);
            }

            $userErrors = data_get($mutationResult, 'productVariantsBulkUpdate.userErrors', []);
            if (is_array($userErrors) && !empty($userErrors)) {
                $this->error('Shopify returned userErrors:');
                foreach ($userErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $this->line("- [{$field}] " . ($error['message'] ?? 'Unknown error'));
                }
                return self::FAILURE;
            }

            $updated = data_get($mutationResult, 'productVariantsBulkUpdate.productVariants', []);

            $this->info('Variant update succeeded.');
            $this->line('Product: ' . $productId . ($productHandle !== '' ? " ({$productHandle})" : ''));
            if ($hasPrice) {
                $this->line('Price: ' . number_format((float) $priceInput, 2, '.', ''));
            }
            if ($skuBarcode !== '') {
                $this->line('SKU/Barcode: ' . $skuBarcode);
            }
            if ($compareAtInput !== '') {
                $this->line('Compare-at: ' . number_format((float) $compareAtInput, 2, '.', ''));
            }
            if ($weightEnum !== null) {
                $this->line('Weight unit: ' . $weightEnum . ($weightInput !== '' ? (' | weight=' . $weightInput) : ''));
            }
            if ($costPerItemInput !== '') {
                $this->line('Cost per item: ' . number_format((float) $costPerItemInput, 2, '.', ''));
            }
            $this->line('Updated variants:');
            foreach ((array) $updated as $row) {
                $this->line('- ' . (string) data_get($row, 'id', '') . ' | sku=' . (string) data_get($row, 'sku', '') . ' | price=' . (string) data_get($row, 'price', ''));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Update Shopify variant fields (price, compare-at, sku/barcode, weight unit/weight, cost per item) for a specific product.');
