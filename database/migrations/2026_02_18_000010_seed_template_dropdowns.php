<?php

use App\Services\HeaderStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $seed = [
            HeaderStore::PRODUCT_MATERIALS => [
                'japanese-miyuki-beads-and-wax-coated-cord',
                'japanese-miyuki-beads',
                'freshwater-pearl-and-wax-coated-cord',
                'freshwater-pearl',
                'natural-stones-and-wax-coated-cord',
                'glass-beads',
                'freshwater-pearls-and-wax-coated-cord',
                '18k-gold-plated-chain-and-enamel-bead',
                'freshwater-pearl-and-18k-plated-trinket',
                'agate-beads',
                '18k-plated-trinket-and-wax-cord',
                '18k-plated',
                '18k-plated-enamel',
                'japanese-miyuki-beads-and-18k-plated-clasp',
                'uv-plated-enamel',
            ],
            HeaderStore::PRODUCT_METALS => [
                'Gold',
                'Silver',
            ],
            HeaderStore::PATTERN_CATEGORY => [
                'Solid',
                'Multicolour',
            ],
            HeaderStore::SIZE => [
                'Small',
                'Medium',
                'Large',
            ],
        ];

        foreach ($seed as $header => $values) {
            foreach ($values as $value) {
                $exists = DB::table('dropdown_options')
                    ->where('header', $header)
                    ->where('value', $value)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('dropdown_options')->insert([
                    'header' => $header,
                    'value' => $value,
                    'vendor' => null,
                    'product_type' => null,
                    'collection_style' => null,
                    'collection_tag_primary' => null,
                    'collection_tag_secondary' => null,
                    'active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $headers = [
            HeaderStore::PRODUCT_MATERIALS,
            HeaderStore::PRODUCT_METALS,
            HeaderStore::PATTERN_CATEGORY,
            HeaderStore::SIZE,
        ];

        DB::table('dropdown_options')
            ->whereIn('header', $headers)
            ->whereIn('value', [
                'japanese-miyuki-beads-and-wax-coated-cord',
                'japanese-miyuki-beads',
                'freshwater-pearl-and-wax-coated-cord',
                'freshwater-pearl',
                'natural-stones-and-wax-coated-cord',
                'glass-beads',
                'freshwater-pearls-and-wax-coated-cord',
                '18k-gold-plated-chain-and-enamel-bead',
                'freshwater-pearl-and-18k-plated-trinket',
                'agate-beads',
                '18k-plated-trinket-and-wax-cord',
                '18k-plated',
                '18k-plated-enamel',
                'japanese-miyuki-beads-and-18k-plated-clasp',
                'uv-plated-enamel',
                'Gold',
                'Silver',
                'Not Applicable',
                'Solid',
                'Multicolour',
                'Small',
                'Medium',
                'Large',
            ])
            ->delete();
    }
};
