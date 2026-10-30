<?php

namespace App\Console\Commands;

use App\Models\EngravedBeadOrderLine;
use App\Models\Product;
use Illuminate\Console\Command;

final class ReportEngravedBeadHistoricalOrders extends Command
{
    protected $signature = 'engraved-beads:historical-report {--execute : Refused. Historical inventory is never backfilled by this command.}';

    protected $description = 'Dry-run report of engraved bead order lines. It does not adjust inventory.';

    public function handle(): int
    {
        if ($this->option('execute')) {
            $this->error('Historical engraved bead inventory is not backfilled automatically. No stock was changed.');

            return self::FAILURE;
        }

        $valid = Product::query()->where('engraved_bead_configuration', 'valid')->count();
        $invalid = Product::query()->where('engraved_bead_configuration', 'invalid')->count();
        $historical = EngravedBeadOrderLine::query()->where('configuration', EngravedBeadOrderLine::CONFIGURATION_HISTORICAL)->count();
        $active = EngravedBeadOrderLine::query()->where('configuration', EngravedBeadOrderLine::CONFIGURATION_VALID)->count();

        $this->info('Dry run only. No Shopify inventory was read or written.');
        $this->line("Valid engraved bead products: {$valid}");
        $this->line("Invalid engraved bead products: {$invalid}");
        $this->line("Historical lines recorded without a reservation: {$historical}");
        $this->line("Lines linked to the additional-bead reservation workflow: {$active}");

        return self::SUCCESS;
    }
}
