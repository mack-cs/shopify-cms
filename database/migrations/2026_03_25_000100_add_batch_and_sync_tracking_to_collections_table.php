<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            if (!Schema::hasColumn('collections', 'batch')) {
                $table->string('batch', 64)->nullable()->after('published_channel_names');
                $table->index('batch');
            }

            if (!Schema::hasColumn('collections', 'sync_status')) {
                $table->string('sync_status', 32)->default('pending')->after('batch');
                $table->index('sync_status');
            }

            if (!Schema::hasColumn('collections', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('sync_status');
                $table->index('last_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            if (Schema::hasColumn('collections', 'last_synced_at')) {
                $table->dropIndex(['last_synced_at']);
                $table->dropColumn('last_synced_at');
            }

            if (Schema::hasColumn('collections', 'sync_status')) {
                $table->dropIndex(['sync_status']);
                $table->dropColumn('sync_status');
            }

            if (Schema::hasColumn('collections', 'batch')) {
                $table->dropIndex(['batch']);
                $table->dropColumn('batch');
            }
        });
    }
};
