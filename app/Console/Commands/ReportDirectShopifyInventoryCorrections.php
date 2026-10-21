<?php

namespace App\Console\Commands;

use App\Jobs\ReportDirectShopifyInventoryCorrectionsJob;
use App\Services\Inventory\DirectShopifyInventoryCorrectionReport;
use Illuminate\Console\Command;

class ReportDirectShopifyInventoryCorrections extends Command
{
    protected $signature = 'inventory:report-direct-shopify-corrections {--now : Run in this process. The default queues the report so this command returns immediately}';

    protected $description = 'Email a 7-day snapshot of inventory corrections made directly in Shopify';

    public function handle(DirectShopifyInventoryCorrectionReport $report): int
    {
        if (! (bool) $this->option('now')) {
            ReportDirectShopifyInventoryCorrectionsJob::dispatch();
            $this->info('Queued the 7-day direct Shopify inventory correction report.');
            $this->line('The audit page does not call Shopify. This report runs on the queue.');

            return self::SUCCESS;
        }

        $result = $report->send();
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
        if ($result['errors'] !== []) {
            return self::FAILURE;
        }
        if ($result['corrections'] === 0) {
            $this->info('No direct Shopify inventory corrections in the last 7 days. No email was sent.');

            return self::SUCCESS;
        }

        $this->info('Sent '.$result['corrections'].' direct correction(s) to '.config('services.shopify.direct_inventory_correction_email').'.');
        $this->line('Copied '.$result['copied_users'].' active user(s). Stock was not changed.');

        return self::SUCCESS;
    }
}
