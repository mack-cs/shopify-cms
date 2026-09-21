<?php

namespace Database\Factories;

use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyOrderItem>
 */
class ShopifyOrderItemFactory extends Factory
{
    protected $model = ShopifyOrderItem::class;

    public function definition(): array
    {
        $order = ShopifyOrder::factory()->create();

        return [
            'shopify_line_item_id' => 'gid://shopify/LineItem/' . $this->faker->unique()->numberBetween(100000, 999999),
            'shopify_order_id' => $order->shopify_order_id,
            'shopify_order_db_id' => $order->id,
            'shopify_product_id' => 'gid://shopify/Product/' . $this->faker->numberBetween(100000, 999999),
            'shopify_variant_id' => 'gid://shopify/ProductVariant/' . $this->faker->numberBetween(100000, 999999),
            'sku' => strtoupper($this->faker->bothify('SKU###')),
            'title' => $this->faker->words(3, true),
            'quantity' => 1,
            'original_unit_price' => '100.00',
            'discounted_total' => '100.00',
            'total_discount' => '0.00',
            'currency_code' => 'ZAR',
            'order_created_at_shopify' => $order->created_at_shopify,
            'order_updated_at_shopify' => $order->updated_at_shopify,
            'latest_sync_run_id' => ShopifySyncRun::factory(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
