<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->json('bundle_component_quantities')->nullable()->after('bundle_product_ids');
        });

        Schema::create('shopify_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_fulfillment_id', 128)->unique();
            $table->string('shopify_order_id', 128)->index();
            $table->foreignId('shopify_order_db_id')->nullable()->constrained('shopify_orders')->nullOnDelete();
            $table->string('shopify_location_id', 128)->nullable()->index();
            $table->string('shopify_status', 32)->nullable()->index();
            $table->string('webhook_id', 128)->nullable()->index();
            $table->string('processing_status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestamp('fulfilled_at_shopify')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_stack_component_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shopify_fulfillment_id')->constrained('shopify_fulfillments')->cascadeOnDelete();
            $table->string('shopify_fulfillment_line_item_id', 128);
            $table->string('shopify_order_id', 128)->index();
            $table->string('shopify_stack_variant_id', 128)->index();
            $table->foreignId('stack_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('stack_variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->unsignedInteger('stack_quantity_fulfilled');
            $table->unsignedBigInteger('configured_component_product_id');
            $table->foreignId('component_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('component_variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('shopify_component_variant_id', 128)->nullable();
            $table->string('shopify_inventory_item_id', 128)->nullable()->index();
            $table->string('shopify_location_id', 128)->nullable()->index();
            $table->unsignedInteger('component_quantity_per_stack');
            $table->unsignedInteger('quantity_deducted');
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('shopify_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shopify_fulfillment_id', 'shopify_fulfillment_line_item_id', 'configured_component_product_id'],
                'stack_component_deduction_once'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_stack_component_deductions');
        Schema::dropIfExists('shopify_fulfillments');

        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->dropColumn('bundle_component_quantities');
        });
    }
};
