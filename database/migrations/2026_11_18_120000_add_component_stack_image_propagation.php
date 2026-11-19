<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 'ownership')) {
                $table->string('ownership', 32)->nullable()->index();
            }
        });

        if (! Schema::hasTable('component_photograph_generations')) {
            Schema::create('component_photograph_generations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('component_product_id')->index();
                $table->unsignedBigInteger('component_image_id')->index();
                $table->unsignedBigInteger('image_asset_id');
                $table->string('asset_sha256', 64);
                $table->string('shopify_media_id')->nullable();
                $table->string('status', 32)->index();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->unique(['component_image_id', 'image_asset_id'], 'component_generation_asset');
            });
        }

        if (! Schema::hasTable('component_publication_outbox')) {
            Schema::create('component_publication_outbox', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('generation_id')->unique();
                $table->unsignedBigInteger('component_product_id')->index();
                $table->string('status', 32)->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('available_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stack_image_sync_tasks')) {
            Schema::create('stack_image_sync_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stack_product_id')->index();
                $table->unsignedBigInteger('component_product_id');
                $table->unsignedBigInteger('component_image_id');
                $table->unsignedBigInteger('generation_id');
                $table->unsignedBigInteger('applied_generation_id')->nullable();
                $table->string('phase', 32)->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('available_at')->nullable();
                $table->timestamps();
                $table->unique(['stack_product_id', 'generation_id'], 'stack_generation_once');
            });
        }

        if (! Schema::hasTable('inherited_image_sources')) {
            Schema::create('inherited_image_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('image_id')->index();
                $table->unsignedBigInteger('component_image_id');
                $table->unsignedBigInteger('component_product_id');
                $table->unsignedBigInteger('generation_id')->nullable();
                $table->unsignedBigInteger('image_asset_id');
                $table->timestamps();
                $table->unique(['image_id', 'component_image_id', 'image_asset_id'], 'inherited_source_asset_once');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
