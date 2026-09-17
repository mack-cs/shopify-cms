<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('manual_stock_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_type')->default('direct_order')->index();
            $table->string('processing_mode')->default('process_inventory')->index();
            $table->string('recipient_name');
            $table->string('reference_number')->nullable()->index();
            $table->date('transaction_date')->index();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('inventory_update_status')->default('not_started');
            $table->boolean('is_historical')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('manual_stock_transaction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_stock_transaction_id');
            $table->foreign('manual_stock_transaction_id', 'mst_items_tx_fk')->references('id')->on('manual_stock_transactions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_title');
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity');
            $table->boolean('is_stack')->default(false);
            $table->string('processing_result')->nullable();
            $table->timestamps();
        });
        Schema::create('manual_stock_transaction_impacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_stock_transaction_id');
            $table->foreign('manual_stock_transaction_id', 'mst_impacts_tx_fk')->references('id')->on('manual_stock_transactions')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->string('sku');
            $table->string('product_title');
            $table->unsignedInteger('quantity_required');
            $table->json('sources');
            $table->integer('available_before')->nullable();
            $table->integer('on_hand_before')->nullable();
            $table->integer('available_after')->nullable();
            $table->integer('on_hand_after')->nullable();
            $table->string('shopify_location_id')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('shopify_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['manual_stock_transaction_id', 'variant_id'], 'manual_stock_impact_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_stock_transaction_impacts');
        Schema::dropIfExists('manual_stock_transaction_items');
        Schema::dropIfExists('manual_stock_transactions');
    }
};
