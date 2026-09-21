<?php

namespace Database\Factories;

use App\Models\ShopifySyncIssue;
use App\Models\ShopifySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifySyncIssue>
 */
class ShopifySyncIssueFactory extends Factory
{
    protected $model = ShopifySyncIssue::class;

    public function definition(): array
    {
        return [
            'sync_run_id' => ShopifySyncRun::factory(),
            'dataset' => ShopifySyncRun::DATASET_ORDERS,
            'issue_type' => ShopifySyncIssue::TYPE_UNCLASSIFIED_RECORD,
            'shopify_id' => 'gid://shopify/Unknown/' . $this->faker->unique()->numberBetween(100000, 999999),
            'message' => 'Unclassified test record.',
            'payload' => ['factory' => true],
        ];
    }
}
