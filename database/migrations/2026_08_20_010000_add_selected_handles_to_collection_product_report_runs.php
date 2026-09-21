<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_collection_product_report_runs', function (Blueprint $table): void {
            $table->json('selected_collection_handles')->nullable()->after('api_version');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_collection_product_report_runs', function (Blueprint $table): void {
            $table->dropColumn('selected_collection_handles');
        });
    }
};
