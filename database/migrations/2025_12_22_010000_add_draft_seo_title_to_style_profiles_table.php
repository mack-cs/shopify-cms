<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->string('draft_seo_title', 255)->nullable()->after('draft_description');
        });
    }

    public function down(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->dropColumn('draft_seo_title');
        });
    }
};
