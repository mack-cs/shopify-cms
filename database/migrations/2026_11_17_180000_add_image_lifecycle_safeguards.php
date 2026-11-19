<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 'shopify_identity_unresolved')) {
                $table->boolean('shopify_identity_unresolved')->default(false);
            }
            if (! Schema::hasColumn('images', 'alt_confirmation_media_id')) {
                $table->string('alt_confirmation_media_id')->nullable();
            }
            if (! Schema::hasColumn('images', 'alt_confirmation_text')) {
                $table->text('alt_confirmation_text')->nullable();
            }
        });
        if (! Schema::hasTable('image_revisions')) {
            Schema::create('image_revisions', function (Blueprint $table): void {
                $table->id();
                // Deliberately retain history independently of the current image/asset.
                $table->unsignedBigInteger('image_id')->index();
                $table->json('snapshot');
                $table->string('reason');
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('image_upload_operations')) {
            Schema::create('image_upload_operations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('image_id')->index();
                // One unresolved operation per image, enforced by the database.
                $table->unsignedBigInteger('active_image_id')->nullable()->unique();
                $table->string('asset_key', 64)->nullable()->unique();
                $table->unsignedBigInteger('image_asset_id');
                $table->string('asset_sha256', 64);
                $table->string('product_gid');
                $table->text('source_url');
                $table->json('before_media_ids');
                $table->string('status', 32)->index();
                $table->string('media_id')->nullable();
                $table->text('error')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->text('resolution_reason')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
