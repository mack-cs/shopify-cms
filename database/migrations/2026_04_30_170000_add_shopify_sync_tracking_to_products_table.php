<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'sync_batch_id')) {
                $table->string('sync_batch_id', 36)->nullable()->after('batch');
                $table->index('sync_batch_id');
            }

            if (!Schema::hasColumn('products', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('sync_batch_id');
                $table->index('last_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'last_synced_at')) {
                $table->dropIndex(['last_synced_at']);
                $table->dropColumn('last_synced_at');
            }

            if (Schema::hasColumn('products', 'sync_batch_id')) {
                $table->dropIndex(['sync_batch_id']);
                $table->dropColumn('sync_batch_id');
            }
        });
    }
};
