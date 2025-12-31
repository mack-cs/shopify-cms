<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ColorSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'pink', 'active' => true],
            ['name' => 'solid', 'active' => true],
            ['name' => 'plain', 'active' => true],
            ['name' => 'tan', 'active' => true],
            ['name' => 'pastels', 'active' => true],
            ['name' => 'multicolour', 'active' => true],
            ['name' => 'cream', 'active' => true],
            ['name' => 'white', 'active' => true],
            ['name' => 'blue', 'active' => true],
            ['name' => 'white-and-gold', 'active' => true],
            ['name' => 'black', 'active' => true],
            ['name' => 'black-and-gold', 'active' => true],
            ['name' => 'geometric', 'active' => true],
            ['name' => 'striped', 'active' => true],
            ['name' => 'silver', 'active' => true],
            ['name' => 'green', 'active' => true],
            ['name' => 'emerald-green', 'active' => true],
            ['name' => 'burnt-orange', 'active' => true],
            ['name' => 'orange', 'active' => true],
            ['name' => 'grey', 'active' => true],
            ['name' => 'black-and-grey', 'active' => true],
            ['name' => 'gold', 'active' => true],
            ['name' => 'purple', 'active' => true],
            ['name' => 'rust-oranges', 'active' => true],
            ['name' => 'black-and-white', 'active' => true],
            ['name' => 'silver-1', 'active' => true],
            ['name' => 'turquoise', 'active' => true],
            ['name' => 'blue-and-white', 'active' => true],
            ['name' => 'red', 'active' => true],
            ['name' => 'red-and-white', 'active' => true],
            ['name' => 'purple-and-white', 'active' => true],
            ['name' => 'emerald', 'active' => true],
            ['name' => 'rosegold', 'active' => true],
            ['name' => 'rainbow', 'active' => true],
            ['name' => 'yellow', 'active' => true],
            ['name' => 'brown', 'active' => true],
            ['name' => 'black-white-and-gold', 'active' => true],
        ];

        DB::table('colors')->upsert(
            $rows,
            ['name'], // unique key
            ['active']
        );
    }
}
