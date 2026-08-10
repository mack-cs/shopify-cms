<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_prediction_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->date('calculation_date')->unique();
            $table->string('status', 32)->index();
            $table->foreignId('product_movement_report_run_id')->nullable();
            $table->foreign('product_movement_report_run_id', 'proc_pred_run_movement_fk')
                ->references('id')->on('product_movement_report_runs')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('product_movement_generated_at')->nullable();
            $table->string('product_movement_source_version', 64)->nullable();
            $table->string('model_version', 128)->nullable();
            $table->json('selected_model_information')->nullable();
            $table->unsignedInteger('default_lead_time_days');
            $table->unsignedInteger('attention_horizon_days');
            $table->unsignedInteger('total_input_rows')->default(0);
            $table->unsignedInteger('total_excluded_rows')->default(0);
            $table->unsignedInteger('total_prediction_rows')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_predictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('procurement_prediction_run_id');
            $table->foreign('procurement_prediction_run_id', 'proc_pred_rows_run_fk')
                ->references('id')->on('procurement_prediction_runs')->cascadeOnDelete();
            $table->string('shopify_product_id', 128)->nullable();
            $table->string('shopify_variant_id', 128)->nullable()->index();
            $table->string('sku')->index();
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('vendor')->nullable()->index();
            $table->string('main_collection')->nullable();
            $table->string('product_type')->nullable()->index();
            $table->string('cms_movement_classification', 32)->nullable()->index();
            $table->string('ml_movement_classification', 32)->nullable();
            $table->boolean('movement_classification_matches')->nullable();
            $table->decimal('movement_score', 8, 2)->nullable();
            $table->decimal('sales_consistency', 8, 2)->nullable();
            $table->integer('net_units_sold')->default(0);
            $table->decimal('average_weekly_demand', 14, 4)->nullable();
            $table->decimal('units_sold_per_30_in_stock_days', 14, 4)->nullable();
            $table->decimal('ml_predicted_weekly_demand', 14, 4)->nullable();
            $table->decimal('weighted_predicted_weekly_demand', 14, 4)->nullable();
            $table->decimal('predicted_weekly_demand', 14, 4)->nullable();
            $table->string('selected_prediction_method', 64)->nullable();
            $table->integer('current_inventory')->nullable();
            $table->unsignedInteger('in_stock_days')->nullable();
            $table->unsignedInteger('out_of_stock_days')->nullable();
            $table->unsignedInteger('attention_horizon_days');
            $table->unsignedInteger('lead_time_days_used');
            $table->string('lead_time_source', 64);
            $table->decimal('estimated_days_of_stock_remaining', 14, 2)->nullable();
            $table->date('predicted_runout_date')->nullable()->index();
            $table->integer('stock_required_for_attention_horizon')->nullable();
            $table->integer('stock_required_for_lead_time')->nullable();
            $table->integer('preliminary_order_quantity')->nullable();
            $table->boolean('currently_on_sale')->default(false)->index();
            $table->string('action_status', 40)->index();
            $table->text('action_reason')->nullable();
            $table->string('data_quality_status', 32)->nullable()->index();
            $table->text('data_quality_warning')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['procurement_prediction_run_id', 'shopify_variant_id'],
                'proc_pred_run_variant_unique'
            );
            $table->index(
                ['procurement_prediction_run_id', 'action_status'],
                'proc_pred_run_action_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_predictions');
        Schema::dropIfExists('procurement_prediction_runs');
    }
};
