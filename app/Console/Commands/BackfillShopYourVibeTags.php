<?php

namespace App\Console\Commands;

use App\Services\ShopYourVibeAssignmentService;
use Illuminate\Console\Command;

class BackfillShopYourVibeTags extends Command
{
    protected $signature = 'shopify:backfill-shop-your-vibe-tags {--user-id= : User ID recorded in the change log}';

    protected $description = 'Reconcile Shop Your Vibe membership tags from Shopify into CMS products and drafts';

    public function handle(ShopYourVibeAssignmentService $service): int
    {
        $this->info('Refreshing Shop Your Vibe mappings from Shopify...');
        $service->syncAllMappings();
        $result = $service->backfillMembershipTags(
            filled($this->option('user-id')) ? (int) $this->option('user-id') : null
        );
        $this->info("Checked {$result['checked']} products; updated {$result['updated']}; skipped {$result['skipped']} missing local products.");

        return self::SUCCESS;
    }
}
