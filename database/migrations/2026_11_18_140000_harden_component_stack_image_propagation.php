<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_photograph_generations', function (Blueprint $table): void {
            if (! Schema::hasColumn('component_photograph_generations', 'selection_revision')) {
                $table->unsignedInteger('selection_revision')->default(1);
            }
        });
        Schema::table('component_photograph_generations', function (Blueprint $table): void {
            if (Schema::hasIndex('component_photograph_generations', 'component_generation_asset')) {
                $table->dropUnique('component_generation_asset');
            }
            if (! Schema::hasIndex('component_photograph_generations', 'component_generation_revision')) {
                $table->unique(
                    ['component_image_id', 'image_asset_id', 'selection_revision'],
                    'component_generation_revision'
                );
            }
        });

        if (! Schema::hasTable('component_selection_revisions')) {
            Schema::create('component_selection_revisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('component_product_id')->unique();
                $table->unsignedInteger('revision')->default(0);
                $table->timestamps();
            });
        }

        Schema::table('stack_image_sync_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('stack_image_sync_tasks', 'purpose')) {
                $table->string('purpose', 32)->default('publish');
            }
        });
        Schema::table('stack_image_sync_tasks', function (Blueprint $table): void {
            if (Schema::hasIndex('stack_image_sync_tasks', 'stack_generation_once')) {
                $table->dropUnique('stack_generation_once');
            }
            if (! Schema::hasIndex('stack_image_sync_tasks', 'stack_generation_purpose') && ! Schema::hasIndex('stack_image_sync_tasks', 'stack_generation_membership')) {
                $table->unique(['stack_product_id', 'generation_id', 'purpose'], 'stack_generation_purpose');
            }
        });

        if (! Schema::hasTable('inherited_media_retirements')) {
            Schema::create('inherited_media_retirements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stack_product_id')->index();
                $table->unsignedBigInteger('image_id')->index();
                $table->unsignedBigInteger('generation_id')->nullable();
                $table->string('old_media_id');
                $table->string('replacement_media_id')->nullable();
                $table->unsignedBigInteger('replacement_image_id')->nullable();
                $table->string('status', 32)->index();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['image_id', 'old_media_id', 'generation_id'], 'inherited_retirement_once');
            });
        }

        if (! Schema::hasTable('stack_image_sync_task_audits')) {
            Schema::create('stack_image_sync_task_audits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id')->index();
                $table->string('previous_phase', 32);
                $table->string('action', 64);
                $table->text('reason');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
