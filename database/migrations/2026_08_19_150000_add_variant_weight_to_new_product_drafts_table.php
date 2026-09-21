<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->decimal('variant_weight', 10, 3)->nullable()->after('variant_inventory_qty');
            $table->string('variant_weight_unit', 16)->nullable()->after('variant_weight');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->dropColumn(['variant_weight', 'variant_weight_unit']);
        });
    }
};
