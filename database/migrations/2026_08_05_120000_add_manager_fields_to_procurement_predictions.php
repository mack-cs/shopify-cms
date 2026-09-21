<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_prediction_runs', function (Blueprint $table): void {
            $table->unsignedInteger('total_active_products')->default(0)->after('attention_horizon_days');
            $table->unsignedInteger('total_active_variants')->default(0)->after('total_active_products');
            $table->unsignedInteger('total_rows_received_from_product_movement')->default(0)->after('total_active_variants');
            $table->unsignedInteger('total_unmatched_rows')->default(0)->after('total_excluded_rows');
        });

        Schema::table('procurement_predictions', function (Blueprint $table): void {
            $table->date('recommended_order_by_date')->nullable()->after('predicted_runout_date')->index();
            $table->text('manager_note')->nullable()->after('action_reason');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_predictions', function (Blueprint $table): void {
            $table->dropIndex(['recommended_order_by_date']);
            $table->dropColumn(['recommended_order_by_date', 'manager_note']);
        });

        Schema::table('procurement_prediction_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'total_active_products',
                'total_active_variants',
                'total_rows_received_from_product_movement',
                'total_unmatched_rows',
            ]);
        });
    }
};
