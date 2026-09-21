<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('dataset', 32)->index();
            $table->string('sync_type', 32)->index();
            $table->string('run_mode', 32)->index();
            $table->date('business_date')->nullable()->index();
            $table->string('business_timezone', 64)->default('Africa/Johannesburg');
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->unsignedInteger('lookback_days')->nullable();
            $table->string('shopify_operation_id')->nullable()->index();
            $table->string('shopify_operation_status', 32)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('raw_s3_bucket')->nullable();
            $table->string('raw_s3_key')->nullable();
            $table->string('metadata_s3_key')->nullable();
            $table->unsignedBigInteger('root_object_count')->nullable();
            $table->unsignedBigInteger('object_count')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('records_processed')->default(0);
            $table->unsignedBigInteger('orders_processed')->default(0);
            $table->unsignedBigInteger('order_items_processed')->default(0);
            $table->unsignedBigInteger('refunds_processed')->default(0);
            $table->unsignedBigInteger('discounts_processed')->default(0);
            $table->unsignedBigInteger('inventory_items_processed')->default(0);
            $table->unsignedBigInteger('inventory_levels_processed')->default(0);
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('shopify_completed_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['dataset', 'status']);
            $table->index(['business_date', 'dataset']);
            $table->index('created_at');
        });

        Schema::create('shopify_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_order_id', 128)->unique();
            $table->string('shopify_order_number')->nullable();
            $table->string('name')->nullable()->index();
            $table->timestamp('created_at_shopify')->nullable()->index();
            $table->timestamp('updated_at_shopify')->nullable()->index();
            $table->timestamp('processed_at_shopify')->nullable();
            $table->timestamp('cancelled_at_shopify')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('financial_status', 64)->nullable()->index();
            $table->string('fulfillment_status', 64)->nullable()->index();
            $table->string('currency_code', 8)->nullable();
            $table->decimal('subtotal_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('shipping_amount', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('refunded_amount', 12, 2)->nullable();
            $table->string('source_name')->nullable();
            $table->boolean('is_test')->default(false);
            $table->boolean('customer_accepts_marketing')->nullable();
            $table->string('billing_country')->nullable();
            $table->string('billing_province')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_province')->nullable();
            $table->string('shipping_city')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('latest_sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('latest_sync_run_id');
        });

        Schema::create('shopify_order_items', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_line_item_id', 128)->unique();
            $table->string('shopify_order_id', 128)->index();
            $table->foreignId('shopify_order_db_id')->nullable()->constrained('shopify_orders')->nullOnDelete();
            $table->string('shopify_product_id', 128)->nullable()->index();
            $table->string('shopify_variant_id', 128)->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->string('title')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('vendor')->nullable();
            $table->boolean('taxable')->nullable();
            $table->boolean('requires_shipping')->nullable();
            $table->decimal('original_unit_price', 12, 2)->nullable();
            $table->decimal('discounted_total', 12, 2)->nullable();
            $table->decimal('total_discount', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable();
            $table->string('product_type')->nullable();
            $table->string('product_vendor')->nullable();
            $table->string('product_status', 64)->nullable();
            $table->string('variant_title')->nullable();
            $table->string('variant_sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('variant_price_at_export', 12, 2)->nullable();
            $table->integer('variant_inventory_quantity_at_export')->nullable();
            $table->timestamp('order_created_at_shopify')->nullable()->index();
            $table->timestamp('order_updated_at_shopify')->nullable()->index();
            $table->foreignId('latest_sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('shopify_order_db_id');
        });

        Schema::create('shopify_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_refund_id', 128)->unique();
            $table->string('shopify_order_id', 128)->index();
            $table->foreignId('shopify_order_db_id')->nullable()->constrained('shopify_orders')->nullOnDelete();
            $table->string('order_name')->nullable();
            $table->timestamp('refund_created_at_shopify')->nullable()->index();
            $table->text('note')->nullable();
            $table->decimal('refunded_amount', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->foreignId('latest_sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_discount_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('discount_key', 64)->unique();
            $table->string('shopify_order_id', 128)->index();
            $table->foreignId('shopify_order_db_id')->nullable()->constrained('shopify_orders')->nullOnDelete();
            $table->string('allocation_method')->nullable();
            $table->string('target_selection')->nullable();
            $table->string('target_type')->nullable();
            $table->string('value_type')->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->decimal('discount_percentage', 8, 4)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->foreignId('latest_sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('shopify_sync_runs')->cascadeOnDelete();
            $table->date('business_date')->nullable()->index();
            $table->timestamp('snapshot_requested_at')->nullable();
            $table->timestamp('snapshot_completed_at')->nullable()->index();
            $table->string('shopify_inventory_item_id', 128)->index();
            $table->string('shopify_inventory_level_id', 128)->nullable();
            $table->string('shopify_product_id', 128)->nullable()->index();
            $table->string('shopify_variant_id', 128)->nullable()->index();
            $table->string('shopify_location_id', 128)->nullable()->index();
            $table->string('location_name')->nullable();
            $table->boolean('location_active')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable();
            $table->string('product_type')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_status', 64)->nullable();
            $table->string('variant_title')->nullable();
            $table->boolean('tracked')->nullable();
            $table->boolean('requires_shipping')->nullable();
            $table->decimal('variant_price', 12, 2)->nullable();
            $table->integer('available')->nullable();
            $table->integer('on_hand')->nullable();
            $table->integer('committed')->nullable();
            $table->integer('incoming')->nullable();
            $table->integer('reserved')->nullable();
            $table->integer('damaged')->nullable();
            $table->integer('quality_control')->nullable();
            $table->integer('safety_stock')->nullable();
            $table->timestamps();

            $table->unique(['sync_run_id', 'shopify_inventory_item_id', 'shopify_location_id'], 'shopify_inventory_snapshot_unique');
        });

        Schema::create('sku_daily_demand', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->index();
            $table->date('demand_date')->index();
            $table->integer('gross_units')->default(0);
            $table->integer('cancelled_units')->default(0);
            $table->integer('refunded_units')->default(0);
            $table->integer('net_units')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('gross_revenue', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('net_revenue', 12, 2)->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'demand_date']);
        });

        Schema::create('shopify_sync_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->string('dataset', 32)->index();
            $table->string('issue_type', 64)->index();
            $table->string('shopify_id', 128)->nullable()->index();
            $table->string('parent_shopify_id', 128)->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['sync_run_id', 'issue_type']);
        });

        Schema::table('variants', function (Blueprint $table): void {
            if (!Schema::hasColumn('variants', 'current_inventory_quantity')) {
                $table->integer('current_inventory_quantity')->nullable()->after('inventory_qty');
            }
            if (!Schema::hasColumn('variants', 'current_available_quantity')) {
                $table->integer('current_available_quantity')->nullable()->after('current_inventory_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_on_hand_quantity')) {
                $table->integer('current_on_hand_quantity')->nullable()->after('current_available_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_committed_quantity')) {
                $table->integer('current_committed_quantity')->nullable()->after('current_on_hand_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_incoming_quantity')) {
                $table->integer('current_incoming_quantity')->nullable()->after('current_committed_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_reserved_quantity')) {
                $table->integer('current_reserved_quantity')->nullable()->after('current_incoming_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_damaged_quantity')) {
                $table->integer('current_damaged_quantity')->nullable()->after('current_reserved_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_quality_control_quantity')) {
                $table->integer('current_quality_control_quantity')->nullable()->after('current_damaged_quantity');
            }
            if (!Schema::hasColumn('variants', 'current_safety_stock_quantity')) {
                $table->integer('current_safety_stock_quantity')->nullable()->after('current_quality_control_quantity');
            }
            if (!Schema::hasColumn('variants', 'inventory_location_count')) {
                $table->unsignedInteger('inventory_location_count')->nullable()->after('current_safety_stock_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            foreach ([
                'inventory_location_count',
                'current_safety_stock_quantity',
                'current_quality_control_quantity',
                'current_damaged_quantity',
                'current_reserved_quantity',
                'current_incoming_quantity',
                'current_committed_quantity',
                'current_on_hand_quantity',
                'current_available_quantity',
                'current_inventory_quantity',
            ] as $column) {
                if (Schema::hasColumn('variants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('shopify_sync_issues');
        Schema::dropIfExists('sku_daily_demand');
        Schema::dropIfExists('shopify_inventory_snapshots');
        Schema::dropIfExists('shopify_discount_applications');
        Schema::dropIfExists('shopify_refunds');
        Schema::dropIfExists('shopify_order_items');
        Schema::dropIfExists('shopify_orders');
        Schema::dropIfExists('shopify_sync_runs');
    }
};
