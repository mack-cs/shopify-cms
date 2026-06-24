<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_inventory_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_inventory_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('previous_product_inventory_snapshot_id')->nullable()->constrained('product_inventory_snapshots')->nullOnDelete();
            $table->foreignId('observed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable()->index();
            $table->string('product_shopify_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->timestamp('occurred_at')->index();
            $table->string('source', 64)->index();
            $table->boolean('from_is_sellable')->nullable();
            $table->boolean('to_is_sellable')->nullable();
            $table->boolean('from_is_out_of_stock')->nullable();
            $table->boolean('to_is_out_of_stock')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('from_reason', 255)->nullable();
            $table->string('to_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'event_type', 'occurred_at'], 'product_inventory_events_lookup');
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_inventory_events');
    }
};
