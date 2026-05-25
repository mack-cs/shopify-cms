<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->boolean('inventory_tracked')->nullable()->after('inventory_policy');
            $table->timestamp('inventory_last_synced_at')->nullable()->after('last_synced_at');
            $table->string('inventory_sync_batch_id')->nullable()->after('inventory_last_synced_at');
            $table->boolean('inventory_local_dirty')->default(false)->after('inventory_sync_batch_id');
            $table->text('inventory_sync_error')->nullable()->after('inventory_local_dirty');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn([
                'inventory_tracked',
                'inventory_last_synced_at',
                'inventory_sync_batch_id',
                'inventory_local_dirty',
                'inventory_sync_error',
            ]);
        });
    }
};
