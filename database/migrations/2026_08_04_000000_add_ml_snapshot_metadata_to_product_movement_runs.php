<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_movement_report_runs', function (Blueprint $table): void {
            $table->date('calculation_date')->nullable()->unique()->after('requested_by');
            $table->timestamp('source_data_timestamp')->nullable()->after('completed_at');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('source_data_timestamp');
            $table->string('source_version', 64)->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('product_movement_report_runs', function (Blueprint $table): void {
            $table->dropUnique(['calculation_date']);
            $table->dropColumn([
                'calculation_date',
                'source_data_timestamp',
                'duration_ms',
                'source_version',
            ]);
        });
    }
};
