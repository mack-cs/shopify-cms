<?php

namespace App\Console\Commands;

use App\Models\ProcurementCollectionConfig;
use App\Models\ShopifyCollection;
use Illuminate\Console\Command;

final class ConfigureProcurementCollection extends Command
{
    protected $signature = 'procurement:configure-collection
        {handle : Shopify collection handle}
        {--tab= : Google Sheet tab; defaults to the handle}
        {--disable : Disable this operational procurement collection}';

    protected $description = 'Configure a persistent Shopify brand collection to Google Sheet tab mapping';

    public function handle(): int
    {
        $handle = strtolower(trim((string) $this->argument('handle')));
        if ($handle === '') {
            $this->error('Collection handle cannot be blank.');

            return self::FAILURE;
        }
        $collection = ShopifyCollection::query()
            ->whereRaw('LOWER(TRIM(handle)) = ?', [$handle])
            ->latest('id')->first();
        if (! $collection instanceof ShopifyCollection) {
            $this->error("No imported Shopify collection with handle [{$handle}] exists.");

            return self::FAILURE;
        }
        $config = ProcurementCollectionConfig::query()->updateOrCreate(
            ['collection_handle' => $handle],
            [
                'shopify_collection_id' => $collection->shopify_id,
                'collection_title' => $collection->title,
                'google_sheet_tab_name' => trim((string) $this->option('tab')) ?: $handle,
                'is_active' => ! (bool) $this->option('disable'),
            ],
        );
        $this->info("Configured [{$config->collection_handle}] → [{$config->google_sheet_tab_name}] ({$config->shopify_collection_id}).");

        return self::SUCCESS;
    }
}
