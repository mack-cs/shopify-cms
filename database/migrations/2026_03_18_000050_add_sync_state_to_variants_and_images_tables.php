<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('variants', 'sync_state')) {
            Schema::table('variants', function (Blueprint $table) {
                $table->string('sync_state')->default('synced')->after('shopify_id');
                $table->boolean('local_dirty')->default(false)->after('sync_state');
                $table->timestamp('last_shopify_seen_at')->nullable()->after('local_dirty');
                $table->timestamp('last_synced_at')->nullable()->after('last_shopify_seen_at');
                $table->index('sync_state');
            });
        }

        if (!Schema::hasColumn('images', 'sync_state')) {
            Schema::table('images', function (Blueprint $table) {
                $table->string('sync_state')->default('synced')->after('shopify_id');
                $table->boolean('local_dirty')->default(false)->after('sync_state');
                $table->timestamp('last_shopify_seen_at')->nullable()->after('local_dirty');
                $table->timestamp('last_synced_at')->nullable()->after('last_shopify_seen_at');
                $table->index('sync_state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('variants', 'sync_state')) {
            Schema::table('variants', function (Blueprint $table) {
                $table->dropIndex(['sync_state']);
                $table->dropColumn([
                    'sync_state',
                    'local_dirty',
                    'last_shopify_seen_at',
                    'last_synced_at',
                ]);
            });
        }

        if (Schema::hasColumn('images', 'sync_state')) {
            Schema::table('images', function (Blueprint $table) {
                $table->dropIndex(['sync_state']);
                $table->dropColumn([
                    'sync_state',
                    'local_dirty',
                    'last_shopify_seen_at',
                    'last_synced_at',
                ]);
            });
        }
    }
};
