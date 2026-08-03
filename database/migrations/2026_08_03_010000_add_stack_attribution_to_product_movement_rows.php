<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_movement_report_rows', function (Blueprint $table): void {
            $table->string('movement_product_kind', 24)->default('standard')->after('variant_status')->index();
            $table->integer('direct_gross_units_sold')->default(0)->after('months_analysed');
            $table->integer('direct_refunded_units')->default(0)->after('direct_gross_units_sold');
            $table->integer('direct_net_units_sold')->default(0)->after('direct_refunded_units');
            $table->integer('stack_attributed_gross_units')->default(0)->after('direct_net_units_sold');
            $table->integer('stack_attributed_refunded_units')->default(0)->after('stack_attributed_gross_units');
            $table->integer('stack_attributed_net_units')->default(0)->after('stack_attributed_refunded_units');
            $table->json('contributing_stack_skus')->nullable()->after('stack_attributed_net_units');
        });
    }

    public function down(): void
    {
        Schema::table('product_movement_report_rows', function (Blueprint $table): void {
            $table->dropColumn([
                'movement_product_kind',
                'direct_gross_units_sold',
                'direct_refunded_units',
                'direct_net_units_sold',
                'stack_attributed_gross_units',
                'stack_attributed_refunded_units',
                'stack_attributed_net_units',
                'contributing_stack_skus',
            ]);
        });
    }
};
