<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustment_request_items', function (Blueprint $table): void {
            // Preserve negative stock snapshots and the full previous unsigned integer range.
            $table->bigInteger('original_on_hand_quantity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustment_request_items', function (Blueprint $table): void {
            $table->unsignedInteger('original_on_hand_quantity')->nullable()->change();
        });
    }
};
