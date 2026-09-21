<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_collection_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_collection_id', 128)->nullable()->index();
            $table->string('collection_handle')->unique();
            $table->string('collection_title')->nullable();
            $table->string('google_sheet_tab_name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('procurement_incoming_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->unique();
            $table->foreign('variant_id', 'proc_in_stock_variant_fk')
                ->references('id')->on('variants')->cascadeOnDelete();
            $table->string('sku')->index();
            $table->unsignedInteger('quantity_on_order_phase_1')->default(0);
            $table->unsignedInteger('quantity_on_order_phase_2')->default(0);
            $table->unsignedInteger('quantity_on_order_phase_3')->default(0);
            $table->unsignedInteger('total_quantity_on_order')->default(0);
            $table->string('source_sheet')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('input_changed_at')->nullable();
            $table->foreignId('last_prediction_run_id')->nullable();
            $table->foreign('last_prediction_run_id', 'proc_in_stock_last_run_fk')
                ->references('id')->on('procurement_prediction_runs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('procurement_incoming_stock_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('procurement_incoming_stock_id');
            $table->foreign('procurement_incoming_stock_id', 'proc_in_stock_changes_stock_fk')
                ->references('id')->on('procurement_incoming_stocks')->cascadeOnDelete();
            $table->string('sku')->index();
            $table->string('source_sheet');
            $table->unsignedInteger('previous_phase_1')->default(0);
            $table->unsignedInteger('previous_phase_2')->default(0);
            $table->unsignedInteger('previous_phase_3')->default(0);
            $table->unsignedInteger('new_phase_1')->default(0);
            $table->unsignedInteger('new_phase_2')->default(0);
            $table->unsignedInteger('new_phase_3')->default(0);
            $table->timestamp('detected_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_prediction_inputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('procurement_prediction_run_id');
            $table->foreign('procurement_prediction_run_id', 'proc_pred_inputs_run_fk')
                ->references('id')->on('procurement_prediction_runs')->cascadeOnDelete();
            $table->foreignId('variant_id');
            $table->foreign('variant_id', 'proc_pred_inputs_variant_fk')
                ->references('id')->on('variants')->cascadeOnDelete();
            $table->string('shopify_product_id', 128)->nullable();
            $table->string('shopify_variant_id', 128)->nullable();
            $table->string('sku')->index();
            $table->unsignedInteger('quantity_on_order_phase_1')->default(0);
            $table->unsignedInteger('quantity_on_order_phase_2')->default(0);
            $table->unsignedInteger('quantity_on_order_phase_3')->default(0);
            $table->unsignedInteger('total_quantity_on_order')->default(0);
            $table->string('source_sheet')->nullable();
            $table->timestamp('source_changed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['procurement_prediction_run_id', 'variant_id'],
                'proc_pred_inputs_run_variant_unique'
            );
        });

        Schema::table('procurement_prediction_runs', function (Blueprint $table): void {
            $table->timestamp('incoming_stock_snapshot_at')->nullable();
            $table->string('incoming_stock_input_hash', 64)->nullable();
            $table->timestamp('sheets_published_at')->nullable();
            $table->string('sheet_publish_status', 32)->nullable()->index();
            $table->text('sheet_publish_error')->nullable();
        });

        Schema::table('procurement_predictions', function (Blueprint $table): void {
            $table->unsignedInteger('quantity_on_order_phase_1')->default(0);
            $table->unsignedInteger('quantity_on_order_phase_2')->default(0);
            $table->unsignedInteger('quantity_on_order_phase_3')->default(0);
            $table->unsignedInteger('total_quantity_on_order')->default(0);
            $table->integer('projected_inventory_position')->nullable();
            $table->integer('recommended_order_before_incoming_stock')->nullable();
            $table->integer('additional_order_required')->nullable();
            $table->boolean('incoming_stock_covers_requirement')->default(false);
            $table->boolean('stockout_before_incoming_arrival')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('procurement_predictions', function (Blueprint $table): void {
            $table->dropColumn([
                'quantity_on_order_phase_1', 'quantity_on_order_phase_2',
                'quantity_on_order_phase_3', 'total_quantity_on_order',
                'projected_inventory_position', 'recommended_order_before_incoming_stock',
                'additional_order_required', 'incoming_stock_covers_requirement',
                'stockout_before_incoming_arrival',
            ]);
        });

        Schema::table('procurement_prediction_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'incoming_stock_snapshot_at', 'incoming_stock_input_hash',
                'sheets_published_at', 'sheet_publish_status', 'sheet_publish_error',
            ]);
        });

        Schema::dropIfExists('procurement_prediction_inputs');
        Schema::dropIfExists('procurement_incoming_stock_changes');
        Schema::dropIfExists('procurement_incoming_stocks');

        Schema::dropIfExists('procurement_collection_configs');
    }
};
