<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('new_product_drafts')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->textColumns() as $column) {
            if (! Schema::hasColumn('new_product_drafts', $column)) {
                continue;
            }

            DB::statement("ALTER TABLE new_product_drafts MODIFY {$column} TEXT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('new_product_drafts')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $definitions = [
            'seo_description' => 'VARCHAR(512) NULL',
            'color_string' => 'VARCHAR(512) NULL',
            'image_url' => 'VARCHAR(255) NULL',
            'jewelry_material' => 'VARCHAR(255) NULL',
            'product_materials' => 'VARCHAR(255) NULL',
            'materials_and_dimensions' => 'VARCHAR(512) NULL',
            'product_design' => 'VARCHAR(255) NULL',
            'metal' => 'VARCHAR(255) NULL',
            'colour_style' => 'VARCHAR(255) NULL',
            'size' => 'VARCHAR(255) NULL',
            'siblings' => 'VARCHAR(255) NULL',
            'siblings_collection_name' => 'VARCHAR(255) NULL',
            'bead_colour_finish' => 'VARCHAR(255) NULL',
        ];

        foreach ($definitions as $column => $definition) {
            if (! Schema::hasColumn('new_product_drafts', $column)) {
                continue;
            }

            DB::statement("ALTER TABLE new_product_drafts MODIFY {$column} {$definition}");
        }
    }

    /**
     * @return array<int, string>
     */
    private function textColumns(): array
    {
        return [
            'seo_description',
            'color_string',
            'image_url',
            'jewelry_material',
            'product_materials',
            'materials_and_dimensions',
            'product_design',
            'metal',
            'colour_style',
            'size',
            'siblings',
            'siblings_collection_name',
            'bead_colour_finish',
        ];
    }
};
