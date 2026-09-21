<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_movement_report_rows', function (Blueprint $table): void {
            $table->string('recommended_action', 32)
                ->nullable()
                ->after('movement_classification')
                ->index();
            $table->text('manager_reason')->nullable()->after('recommended_action');
        });
    }

    public function down(): void
    {
        Schema::table('product_movement_report_rows', function (Blueprint $table): void {
            $table->dropColumn(['recommended_action', 'manager_reason']);
        });
    }
};
