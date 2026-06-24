<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('observed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable()->index();
            $table->string('product_shopify_id')->nullable()->index();
            $table->timestamp('checked_at')->index();
            $table->date('checked_date')->index();
            $table->string('source', 64)->index();
            $table->string('product_status', 32)->nullable()->index();
            $table->boolean('is_sellable')->index();
            $table->boolean('is_out_of_stock')->index();
            $table->string('sellability_reason', 255)->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->unsignedInteger('tracked_variant_count')->default(0);
            $table->unsignedInteger('untracked_variant_count')->default(0);
            $table->unsignedInteger('unknown_inventory_variant_count')->default(0);
            $table->unsignedInteger('sellable_variant_count')->default(0);
            $table->unsignedInteger('out_of_stock_variant_count')->default(0);
            $table->integer('total_inventory_qty')->nullable();
            $table->integer('primary_variant_qty')->nullable();
            $table->json('variant_summary')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'checked_at']);
            $table->index(['checked_date', 'is_sellable']);
            $table->index(['checked_date', 'is_out_of_stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_inventory_snapshots');
    }
};
