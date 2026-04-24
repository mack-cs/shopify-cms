<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->timestamp('shopify_missing_detected_at')->nullable()->after('shopify_sync_warnings');
            $table->string('shopify_missing_status', 64)->nullable()->after('shopify_missing_detected_at');
            $table->boolean('shopify_missing_sync_blocked')->default(false)->after('shopify_missing_status');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_missing_detected_at',
                'shopify_missing_status',
                'shopify_missing_sync_blocked',
            ]);
        });
    }
};
