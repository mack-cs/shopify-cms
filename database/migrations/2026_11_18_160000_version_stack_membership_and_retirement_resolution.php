<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stack_membership_revisions')) {
            Schema::create('stack_membership_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('stack_product_id');
                $table->unsignedInteger('revision');
                $table->json('component_ids');
                $table->string('source', 64);
                $table->timestamps();
                $table->unique(['stack_product_id', 'revision'], 'stack_membership_revision');
            });
        }

        if (! Schema::hasTable('inherited_alt_resolutions')) {
            Schema::create('inherited_alt_resolutions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('image_id');
                $table->unsignedBigInteger('image_asset_id');
                $table->text('alt_text');
                $table->json('source_generation_ids');
                $table->unsignedBigInteger('user_id');
                $table->text('reason');
                $table->timestamps();
                $table->index(['image_id', 'image_asset_id'], 'inherited_alt_resolution_image');
            });
        }

        Schema::table('inherited_image_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('inherited_image_sources', 'removed_at')) {
                $table->timestamp('removed_at')->nullable()->index();
            }
        });

        Schema::table('inherited_media_retirements', function (Blueprint $table) {
            if (! Schema::hasColumn('inherited_media_retirements', 'last_operation')) {
                $table->string('last_operation', 32)->nullable();
            }
            if (! Schema::hasColumn('inherited_media_retirements', 'resolution')) {
                $table->string('resolution', 64)->nullable();
            }
        });

        Schema::table('stack_image_sync_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('stack_image_sync_tasks', 'membership_revision')) {
                $table->unsignedInteger('membership_revision')->default(0);
            }
        });

        Schema::table('stack_image_sync_tasks', function (Blueprint $table) {
            if (Schema::hasIndex('stack_image_sync_tasks', 'stack_generation_purpose')) {
                $table->dropUnique('stack_generation_purpose');
            }
        });

        Schema::table('stack_image_sync_tasks', function (Blueprint $table) {
            if (! Schema::hasIndex('stack_image_sync_tasks', 'stack_generation_membership')) {
                $table->unique(
                    ['stack_product_id', 'generation_id', 'purpose', 'membership_revision'],
                    'stack_generation_membership'
                );
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
