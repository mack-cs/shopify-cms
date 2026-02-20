<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('new_product_drafts', 'image_url')) {
                $table->string('image_url', 2048)->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('new_product_drafts', 'image_url')) {
                $table->dropColumn('image_url');
            }
        });
    }
};
