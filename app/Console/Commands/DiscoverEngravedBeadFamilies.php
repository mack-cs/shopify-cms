<?php

namespace App\Console\Commands;

use App\Services\EngravedBeads\EngravedBeadFamilyDiscovery;
use Illuminate\Console\Command;

final class DiscoverEngravedBeadFamilies extends Command
{
    protected $signature = 'engraved-beads:discover {--persist : Save pending mappings and discrepancies locally}';

    protected $description = 'Validate synchronized family tags without any Shopify writes';

    public function handle(EngravedBeadFamilyDiscovery $discovery): int
    {
        $groups = $this->option('persist') ? $discovery->synchronize() : $discovery->inspect();
        $this->table(['Family', 'Products', 'Validation'], array_map(fn ($g) => [
            $g['family_tag'], count($g['members']), $g['errors'] === [] ? 'Pending approval' : implode("\n", $g['errors']),
        ], array_values($groups)));

        return collect($groups)->contains(fn ($g) => $g['errors'] !== []) ? self::FAILURE : self::SUCCESS;
    }
}
