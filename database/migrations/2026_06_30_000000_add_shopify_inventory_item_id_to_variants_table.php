<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('variants', 'shopify_inventory_item_id')) {
            return;
        }

        Schema::table('variants', function (Blueprint $table): void {
            $table->string('shopify_inventory_item_id')
                ->nullable()
                ->index()
                ->after('shopify_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('variants', 'shopify_inventory_item_id')) {
            return;
        }

        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('shopify_inventory_item_id');
        });
    }
};
