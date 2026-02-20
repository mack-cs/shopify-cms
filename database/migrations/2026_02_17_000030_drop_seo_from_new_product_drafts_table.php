<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_description']);
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->string('seo_title', 255)->nullable()->after('status');
            $table->string('seo_description', 512)->nullable()->after('seo_title');
        });
    }
};
