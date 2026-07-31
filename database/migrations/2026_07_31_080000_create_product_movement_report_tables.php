<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_movement_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('analysis_start_date');
            $table->date('analysis_end_date');
            $table->decimal('months_analysed', 8, 2);
            $table->string('status', 32)->default('queued')->index();
            $table->json('settings')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
        });

        Schema::create('product_movement_report_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_movement_report_run_id')
                ->constrained('product_movement_report_runs')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('shopify_product_id', 128)->nullable();
            $table->string('shopify_variant_id', 128)->nullable();
            $table->string('product_title')->nullable();
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('vendor')->nullable()->index();
            $table->string('product_type')->nullable()->index();
            $table->string('product_status', 32)->nullable()->index();
            $table->string('variant_status', 32)->nullable();
            $table->timestamp('product_created_at')->nullable();
            $table->date('analysis_start_date');
            $table->date('analysis_end_date');
            $table->decimal('months_analysed', 8, 2);
            $table->integer('gross_units_sold')->default(0);
            $table->integer('refunded_units')->default(0);
            $table->integer('net_units_sold')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('average_units_per_month', 12, 4)->default(0);
            $table->decimal('average_units_per_30_days', 12, 4)->default(0);
            $table->unsignedInteger('months_with_sales')->default(0);
            $table->decimal('sales_consistency_percentage', 8, 2)->default(0);
            $table->date('first_sale_date')->nullable();
            $table->date('last_sale_date')->nullable();
            $table->unsignedInteger('days_since_last_sale')->nullable();
            $table->date('first_inventory_snapshot_date')->nullable();
            $table->unsignedInteger('snapshot_days_available')->nullable();
            $table->unsignedInteger('in_stock_days')->nullable();
            $table->unsignedInteger('out_of_stock_days')->nullable();
            $table->decimal('units_sold_per_30_in_stock_days', 12, 4)->nullable();
            $table->integer('opening_snapshot_inventory')->nullable();
            $table->decimal('average_snapshot_inventory', 12, 4)->nullable();
            $table->integer('closing_snapshot_inventory')->nullable();
            $table->integer('current_inventory')->nullable();
            $table->boolean('inventory_tracked')->nullable()->index();
            $table->string('current_inventory_status', 32)->nullable()->index();
            $table->decimal('movement_score', 6, 2)->default(0)->index();
            $table->string('movement_classification', 32)->index();
            $table->boolean('currently_on_sale')->default(false)->index();
            $table->decimal('current_price', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('discount_percentage', 8, 2)->nullable();
            $table->boolean('has_snapshot_history')->default(false)->index();
            $table->text('data_quality_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['product_movement_report_run_id', 'variant_id'],
                'product_movement_run_variant_unique'
            );
            $table->index(
                ['product_movement_report_run_id', 'movement_classification'],
                'product_movement_run_classification'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_movement_report_rows');
        Schema::dropIfExists('product_movement_report_runs');
    }
};
