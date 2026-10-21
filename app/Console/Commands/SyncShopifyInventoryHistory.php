<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopifyInventoryHistoryJob;
use App\Models\ShopifyInventoryAdjustment;
use App\Services\Inventory\ShopifyInventoryHistorySync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncShopifyInventoryHistory extends Command
{
    protected $signature = 'inventory:sync-shopify-history
        {--from= : First day to load, YYYY-MM-DD. Defaults to the day after the newest stored adjustment, or the last 7 days}
        {--until= : Last day to load, YYYY-MM-DD. Defaults to today}
        {--app= : Optional Shopify app name, for example la-cms-access}
        {--queue : Load one day at a time on the queue}';

    protected $description = 'Store Shopify inventory adjustment history locally so the audit page can filter it without calling Shopify';

    public function handle(ShopifyInventoryHistorySync $sync): int
    {
        try {
            $until = $this->dateOption('until') ?? now()->startOfDay();
            $from = $this->dateOption('from') ?? $this->defaultFrom();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($until->lt($from)) {
            $this->error('The end date is before the start date.');

            return self::FAILURE;
        }

        $app = trim((string) ($this->option('app') ?? ''));
        $app = $app !== '' ? $app : null;
        if ((bool) $this->option('queue')) {
            SyncShopifyInventoryHistoryJob::dispatch($from->toDateString(), $until->toDateString(), $app);
            $this->info('Queued Shopify inventory history from '.$from->toDateString().' through '.$until->toDateString().'.');

            return self::SUCCESS;
        }

        $days = [];
        for ($day = $from->copy(); $day->lte($until); $day->addDay()) {
            $days[] = $sync->syncDay($day->copy(), $app);
            if ($day->copy()->addDay()->lte($until)) {
                sleep(2);
            }
        }
        $stored = array_sum(array_column($days, 'stored'));
        $incomplete = count(array_filter($days, fn (array $day): bool => $day['status'] === 'incomplete'));
        $this->info('Stored '.$stored.' Shopify adjustment rows across '.count($days).' day(s).');
        if ($incomplete > 0) {
            $this->warn($incomplete.' day(s) hit the page limit and are incomplete. Run the same dates again after narrowing the range.');
        }
        $this->line('The audit page reads these stored rows. This command does not change stock.');

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?Carbon
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException("--{$name} must be YYYY-MM-DD.");
        }

        return Carbon::parse($value)->startOfDay();
    }

    private function defaultFrom(): Carbon
    {
        $latest = ShopifyInventoryAdjustment::query()->max('occurred_at');
        if ($latest) {
            return Carbon::parse($latest)->subDay()->startOfDay();
        }

        return now()->subDays(6)->startOfDay();
    }
}
