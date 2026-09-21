<?php

namespace Database\Factories;

use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyInventorySnapshot>
 */
class ShopifyInventorySnapshotFactory extends Factory
{
    protected $model = ShopifyInventorySnapshot::class;

    public function definition(): array
    {
        return [
            'sync_run_id' => ShopifySyncRun::factory()->state(['dataset' => ShopifySyncRun::DATASET_INVENTORY]),
            'business_date' => now('Africa/Johannesburg')->toDateString(),
            'snapshot_requested_at' => now(),
            'snapshot_completed_at' => now(),
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/' . $this->faker->unique()->numberBetween(100000, 999999),
            'shopify_inventory_level_id' => 'gid://shopify/InventoryLevel/' . $this->faker->unique()->numberBetween(100000, 999999),
            'shopify_variant_id' => 'gid://shopify/ProductVariant/' . $this->faker->numberBetween(100000, 999999),
            'shopify_location_id' => 'gid://shopify/Location/1',
            'location_name' => 'Default',
            'sku' => strtoupper($this->faker->bothify('SKU###')),
            'tracked' => true,
            'available' => 10,
            'on_hand' => 10,
        ];
    }
}
