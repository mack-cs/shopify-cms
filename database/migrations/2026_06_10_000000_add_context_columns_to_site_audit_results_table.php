<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_audit_results', function (Blueprint $table) {
            $table->string('speed_classification')->nullable()->after('response_time_ms');
            $table->text('error_reason')->nullable()->after('speed_classification');
            $table->string('shopify_resource_status')->nullable()->after('error_reason');
            $table->json('shopify_context')->nullable()->after('shopify_resource_status');

            $table->index(['site_audit_run_id', 'speed_classification']);
        });

        DB::table('site_audit_results')
            ->whereNotNull('response_time_ms')
            ->where('response_time_ms', '>=', 5000)
            ->update(['speed_classification' => 'very_slow']);

        DB::table('site_audit_results')
            ->whereNotNull('response_time_ms')
            ->where('response_time_ms', '>=', 3000)
            ->where('response_time_ms', '<', 5000)
            ->update(['speed_classification' => 'slow']);

        DB::table('site_audit_results')
            ->whereNotNull('response_time_ms')
            ->where('response_time_ms', '>=', 1000)
            ->where('response_time_ms', '<', 3000)
            ->update(['speed_classification' => 'acceptable']);

        DB::table('site_audit_results')
            ->whereNotNull('response_time_ms')
            ->where('response_time_ms', '<', 1000)
            ->update(['speed_classification' => 'good']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_audit_results', function (Blueprint $table) {
            $table->dropIndex('site_audit_results_site_audit_run_id_speed_classification_index');
            $table->dropColumn([
                'speed_classification',
                'error_reason',
                'shopify_resource_status',
                'shopify_context',
            ]);
        });
    }
};
