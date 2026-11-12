<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->unsignedInteger('written_off_quantity')->default(0)->after('restocked_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->dropColumn('written_off_quantity');
        });
    }
};
