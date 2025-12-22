<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Bracelets', 'google_product_category' => '191'],
            ['name' => 'Necklaces', 'google_product_category' => '189'],
            ['name' => 'Charms', 'google_product_category' => '192'],
            ['name' => 'Earrings', 'google_product_category' => '190'],
        ];

        DB::table('types')->upsert(
            $rows,
            ['name'],
            ['google_product_category']
        );
    }
}
