<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Services\CategoryTypeMap;

class TypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = CategoryTypeMap::typeRows();

        DB::table('types')->upsert(
            $rows,
            ['name'],
            ['google_product_category']
        );
    }
}
