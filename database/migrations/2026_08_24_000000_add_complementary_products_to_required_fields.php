<?php

use App\Services\HeaderStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('required_fields') || DB::table('required_fields')->doesntExist()) {
            return;
        }

        DB::table('required_fields')->updateOrInsert(
            [
                'source' => 'row',
                'attribute' => HeaderStore::COMPLEMENTARY_PRODUCTS,
            ],
            [
                'scope' => 'extra',
                'label' => 'Complementary products',
                'required' => true,
                'bulk_editable' => false,
                'quick_edit' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('required_fields')) {
            return;
        }

        DB::table('required_fields')
            ->where('source', 'row')
            ->where('attribute', HeaderStore::COMPLEMENTARY_PRODUCTS)
            ->delete();
    }
};
