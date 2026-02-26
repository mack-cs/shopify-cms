<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Services\CategoryTypeMap;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = CategoryTypeMap::categoryRows();

        DB::table('categories')->upsert(
            $rows,
            ['name'], // unique key
            ['google_product_category', 'shopify_taxonomy_gid', 'active']
        );
    }
}
