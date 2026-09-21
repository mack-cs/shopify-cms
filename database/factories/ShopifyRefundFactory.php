<?php

namespace Database\Factories;

use App\Models\ShopifyOrder;
use App\Models\ShopifyRefund;
use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyRefund>
 */
class ShopifyRefundFactory extends Factory
{
    protected $model = ShopifyRefund::class;

    public function definition(): array
    {
        $order = ShopifyOrder::factory()->create();

        return [
            'shopify_refund_id' => 'gid://shopify/Refund/' . $this->faker->unique()->numberBetween(100000, 999999),
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_order_db_id' => $order->id,
            'order_name' => $order->name,
            'refund_created_at_shopify' => now(),
            'refunded_amount' => '0.00',
            'currency_code' => 'ZAR',
            'latest_sync_run_id' => ShopifySyncRun::factory(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
