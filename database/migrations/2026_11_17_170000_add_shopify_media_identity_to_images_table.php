<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 'shopify_cdn_url')) {
                $table->text('shopify_cdn_url')->nullable()->after('src');
            }
            if (! Schema::hasColumn('images', 'shopify_media_create_uncertain')) {
                $table->boolean('shopify_media_create_uncertain')->default(false)->after('shopify_image_sync_error');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
