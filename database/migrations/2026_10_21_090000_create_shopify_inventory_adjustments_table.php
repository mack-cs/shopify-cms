<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('adjustment_id', 64)->unique();
            $table->timestamp('occurred_at')->index();
            $table->string('sku')->nullable()->index();
            $table->string('product_title')->nullable();
            $table->string('inventory_item_id')->nullable();
            $table->string('product_variant_id')->nullable();
            $table->string('location_id')->nullable();
            $table->string('location_name')->nullable();
            $table->string('inventory_state', 32)->nullable()->index();
            $table->integer('delta')->default(0);
            $table->string('reason')->nullable();
            $table->string('app_name')->nullable()->index();
            $table->string('staff_name')->nullable();
            $table->string('reference_document_uri', 512)->nullable()->index();
            $table->string('reference_document_type')->nullable();
            $table->string('reference_document_id')->nullable();
            $table->string('adjustment_group_id')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('shopify_inventory_history_syncs', function (Blueprint $table): void {
            $table->id();
            $table->date('history_date')->unique();
            $table->string('status', 32);
            $table->unsignedInteger('row_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_inventory_history_syncs');
        Schema::dropIfExists('shopify_inventory_adjustments');
    }
};
