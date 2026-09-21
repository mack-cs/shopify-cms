<?php

namespace Database\Factories;

use App\Models\ShopifyDiscountApplication;
use App\Models\ShopifyOrder;
use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyDiscountApplication>
 */
class ShopifyDiscountApplicationFactory extends Factory
{
    protected $model = ShopifyDiscountApplication::class;

    public function definition(): array
    {
        $order = ShopifyOrder::factory()->create();

        return [
            'discount_key' => hash('sha256', $order->shopify_order_id . '|' . $this->faker->unique()->uuid()),
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_order_db_id' => $order->id,
            'allocation_method' => 'ACROSS',
            'target_selection' => 'ALL',
            'target_type' => 'LINE_ITEM',
            'value_type' => 'amount',
            'discount_amount' => '10.00',
            'currency_code' => 'ZAR',
            'latest_sync_run_id' => ShopifySyncRun::factory(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
