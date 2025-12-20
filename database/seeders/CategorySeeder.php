<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Apparel & Accessories > Jewelry > Bracelets', 'google_product_category' => '191', 'active' => true],
            ['name' => 'Apparel & Accessories > Jewelry > Charms & Pendants', 'google_product_category' => '192', 'active' => true],
            ['name' => 'Apparel & Accessories > Jewelry > Earrings', 'google_product_category' => '194', 'active' => true],
            ['name' => 'Apparel & Accessories > Jewelry > Necklaces', 'google_product_category' => '196', 'active' => true],
            ['name' => 'Gift Cards', 'google_product_category' => '53', 'active' => true],
            ['name' => 'Health & Beauty > Personal Care > Cosmetics > Perfumes & Colognes > Eaux de Parfum', 'google_product_category' => '479', 'active' => true],
        ];

        DB::table('categories')->upsert(
            $rows,
            ['name'], // unique key
            ['google_product_category', 'active']
        );
    }
}
