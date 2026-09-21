<?php

namespace Database\Factories;

use App\Models\ShopifyOrder;
use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyOrder>
 */
class ShopifyOrderFactory extends Factory
{
    protected $model = ShopifyOrder::class;

    public function definition(): array
    {
        return [
            'shopify_order_id' => 'gid://shopify/Order/' . $this->faker->unique()->numberBetween(100000, 999999),
            'name' => '#' . $this->faker->unique()->numberBetween(1000, 9999),
            'created_at_shopify' => now()->subDay(),
            'updated_at_shopify' => now(),
            'processed_at_shopify' => now()->subDay(),
            'financial_status' => 'PAID',
            'fulfillment_status' => 'UNFULFILLED',
            'currency_code' => 'ZAR',
            'total_amount' => '100.00',
            'is_test' => false,
            'latest_sync_run_id' => ShopifySyncRun::factory(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
