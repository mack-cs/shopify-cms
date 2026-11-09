<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->foreignId('source_image_id')->nullable()->after('product_id')->constrained('images')->nullOnDelete();
            $table->boolean('shopify_alt_pending')->default(false)->after('alt_text');
            $table->text('shopify_alt_sync_error')->nullable()->after('shopify_alt_pending');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_image_id');
            $table->dropColumn(['shopify_alt_pending', 'shopify_alt_sync_error']);
        });
    }
};
