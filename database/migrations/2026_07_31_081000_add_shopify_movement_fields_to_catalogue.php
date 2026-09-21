<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('shopify_created_at')->nullable()->after('shopify_id')->index();
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->boolean('shopify_available_for_sale')->nullable()->after('shopify_inventory_item_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('shopify_available_for_sale');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('shopify_created_at');
        });
    }
};
