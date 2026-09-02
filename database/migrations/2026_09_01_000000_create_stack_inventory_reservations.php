<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_stack_order_events', function (Blueprint $table): void {
            $table->id();
            $table->string('webhook_id', 128)->unique();
            $table->string('topic', 64)->index();
            $table->string('shopify_order_id', 128)->index();
            $table->string('shopify_order_name')->nullable()->index();
            $table->timestamp('shopify_updated_at')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_order_id', 128)->index('ssir_order_idx');
            $table->string('shopify_order_name')->nullable()->index('ssir_order_name_idx');
            $table->string('shopify_order_line_item_id', 128);
            $table->foreignId('stack_product_id')->nullable();
            $table->foreign('stack_product_id', 'ssir_stack_product_fk')->references('id')->on('products')->nullOnDelete();
            $table->foreignId('stack_variant_id')->nullable();
            $table->foreign('stack_variant_id', 'ssir_stack_variant_fk')->references('id')->on('variants')->nullOnDelete();
            $table->string('shopify_stack_product_id', 128)->nullable();
            $table->string('shopify_stack_variant_id', 128)->nullable()->index('ssir_stack_variant_idx');
            $table->string('stack_sku')->nullable()->index('ssir_stack_sku_idx');
            $table->string('stack_title')->nullable();
            $table->unsignedInteger('stack_quantity_ordered');
            $table->unsignedBigInteger('configured_component_product_id');
            $table->foreignId('component_product_id')->nullable();
            $table->foreign('component_product_id', 'ssir_component_product_fk')->references('id')->on('products')->nullOnDelete();
            $table->foreignId('component_variant_id')->nullable();
            $table->foreign('component_variant_id', 'ssir_component_variant_fk')->references('id')->on('variants')->nullOnDelete();
            $table->string('shopify_component_product_id', 128)->nullable();
            $table->string('shopify_component_variant_id', 128)->nullable();
            $table->string('shopify_inventory_item_id', 128)->nullable()->index('ssir_inventory_item_idx');
            $table->string('component_sku')->nullable()->index('ssir_component_sku_idx');
            $table->string('component_title')->nullable();
            $table->unsignedInteger('component_quantity_per_stack');
            $table->unsignedInteger('total_component_quantity_required');
            $table->string('shopify_location_id', 128)->nullable()->index('ssir_location_idx');
            $table->string('ledger_document_uri', 512);
            $table->string('status', 32)->default('pending_processing')->index('ssir_status_idx');
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('consumed_quantity')->default(0);
            $table->unsignedInteger('released_quantity')->default(0);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['shopify_order_id', 'shopify_order_line_item_id', 'configured_component_product_id'],
                'ssir_order_line_component_unique'
            );
        });

        Schema::create('shopify_stack_inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id');
            $table->foreign('reservation_id', 'ssim_reservation_fk')
                ->references('id')->on('shopify_stack_inventory_reservations')->cascadeOnDelete();
            $table->string('event_key', 64)->unique();
            $table->string('action', 32)->index();
            $table->unsignedInteger('quantity');
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('shopify_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_stack_inventory_movements');
        Schema::dropIfExists('shopify_stack_inventory_reservations');
        Schema::dropIfExists('shopify_stack_order_events');
    }
};
