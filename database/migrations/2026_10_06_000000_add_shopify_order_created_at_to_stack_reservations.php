<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->timestamp('shopify_order_created_at')->nullable()->after('shopify_order_name')->index('ssir_order_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('ssir_order_created_idx');
            $table->dropColumn('shopify_order_created_at');
        });
    }
};
