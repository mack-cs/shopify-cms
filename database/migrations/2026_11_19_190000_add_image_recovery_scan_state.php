<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inherited_image_source_events')) {
            Schema::create('inherited_image_source_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('image_id')->index();
                $table->unsignedBigInteger('source_link_id')->nullable();
                $table->string('action', 64);
                $table->json('snapshot');
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('image_relationship_scan_state')) {
            Schema::create('image_relationship_scan_state', function (Blueprint $table): void {
                $table->string('name', 64)->primary();
                $table->unsignedBigInteger('last_id')->default(0);
                $table->unsignedBigInteger('upper_id')->default(0);
                $table->unsignedInteger('completed_passes')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('image_relationship_scan_failures')) {
            Schema::create('image_relationship_scan_failures', function (Blueprint $table): void {
                $table->unsignedBigInteger('draft_id')->primary();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('error');
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('inherited_alt_resolutions', 'context_hash')) {
            Schema::table('inherited_alt_resolutions', function (Blueprint $table): void {
                $table->string('context_hash', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('inherited_media_retirements', 'last_operation_target')) {
            Schema::table('inherited_media_retirements', function (Blueprint $table): void {
                $table->string('last_operation_target')->nullable();
            });
        }
        if (! Schema::hasTable('image_variant_operations')) {
            Schema::create('image_variant_operations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('variant_gid');
                $table->string('active_key', 64)->nullable()->unique();
                $table->unsignedBigInteger('image_id')->nullable();
                $table->unsignedBigInteger('image_asset_id')->nullable();
                $table->string('desired_media_id')->nullable();
                $table->json('before_media_ids');
                $table->string('status', 32);
                $table->text('error')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->text('resolution_reason')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image recovery progress and operator-resolution history during rollback.');
    }
};
