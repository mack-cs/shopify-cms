<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_order_items', function (Blueprint $table): void {
            $table->integer('current_quantity')->nullable()->after('quantity');
            $table->integer('refundable_quantity')->nullable()->after('current_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_order_items', function (Blueprint $table): void {
            $table->dropColumn(['current_quantity', 'refundable_quantity']);
        });
    }
};
