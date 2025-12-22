<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->string('seo_sync_status', 20)->default('draft')->after('draft_seo_description');
            $table->timestamp('seo_synced_at')->nullable()->after('seo_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->dropColumn(['seo_synced_at', 'seo_sync_status']);
        });
    }
};
