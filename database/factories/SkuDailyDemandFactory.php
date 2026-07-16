<?php

namespace Database\Factories;

use App\Models\SkuDailyDemand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuDailyDemand>
 */
class SkuDailyDemandFactory extends Factory
{
    protected $model = SkuDailyDemand::class;

    public function definition(): array
    {
        return [
            'sku' => strtoupper($this->faker->bothify('SKU###')),
            'demand_date' => now('Africa/Johannesburg')->toDateString(),
            'gross_units' => 1,
            'cancelled_units' => 0,
            'refunded_units' => 0,
            'net_units' => 1,
            'order_count' => 1,
            'gross_revenue' => '100.00',
            'discount_amount' => '0.00',
            'net_revenue' => '100.00',
            'calculated_at' => now(),
        ];
    }
}
