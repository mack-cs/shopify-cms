<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_partial_approval_requests', function (Blueprint $table): void {
            $table->json('image_alt_snapshot')->nullable()->after('request_note');
        });

        Schema::table('images', function (Blueprint $table): void {
            $table->text('shopify_alt_text')->nullable()->after('alt_text_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_partial_approval_requests', function (Blueprint $table): void {
            $table->dropColumn('image_alt_snapshot');
        });

        Schema::table('images', function (Blueprint $table): void {
            $table->dropColumn('shopify_alt_text');
        });
    }
};
