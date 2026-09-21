<?php

namespace Database\Factories;

use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifySyncRun>
 */
class ShopifySyncRunFactory extends Factory
{
    protected $model = ShopifySyncRun::class;

    public function definition(): array
    {
        return [
            'dataset' => ShopifySyncRun::DATASET_ORDERS,
            'sync_type' => ShopifySyncRun::SYNC_TYPE_DAILY,
            'run_mode' => ShopifySyncRun::RUN_MODE_MANUAL,
            'business_date' => now('Africa/Johannesburg')->toDateString(),
            'business_timezone' => 'Africa/Johannesburg',
            'window_start' => now('Africa/Johannesburg')->subDays(2)->startOfDay(),
            'window_end' => now('Africa/Johannesburg')->addDay()->startOfDay(),
            'lookback_days' => 3,
            'status' => ShopifySyncRun::STATUS_PENDING,
        ];
    }
}
