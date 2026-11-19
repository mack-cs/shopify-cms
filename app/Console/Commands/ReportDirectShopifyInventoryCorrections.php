<?php

namespace App\Console\Commands;

use App\Jobs\ReportDirectShopifyInventoryCorrectionsJob;
use App\Services\Inventory\DirectShopifyInventoryCorrectionReport;
use Illuminate\Console\Command;

class ReportDirectShopifyInventoryCorrections extends Command
{
    protected $signature = 'inventory:report-direct-shopify-corrections {--now : Run in this process. The default queues the report so this command returns immediately}';

    protected $description = 'Email unreported direct Shopify corrections once on Monday for the previous complete week';

    public function handle(DirectShopifyInventoryCorrectionReport $report): int
    {
        if (! now('Africa/Johannesburg')->isMonday()) {
            $this->info('This report sends on Mondays only, for the previous Monday-Sunday. No email was sent or queued.');
            return self::SUCCESS;
        }
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
            $this->info('No unreported corrections to send, or this week was already processed. No email was sent.');

            return self::SUCCESS;
        }

        $this->info('Sent '.$result['corrections'].' direct correction(s) to '.config('services.shopify.direct_inventory_correction_email').'.');
        $this->line('Copied '.$result['copied_users'].' active user(s). Stock was not changed.');

        return self::SUCCESS;
    }
}
