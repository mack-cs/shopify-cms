<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syv_collection_sort_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('shop_your_vibe_drafts')->cascadeOnDelete();
            $table->string('collection_gid');
            $table->string('product_key');
            $table->string('status', 32)->default('not_sorted')->index();
            $table->boolean('explicit')->default(false);
            $table->boolean('highlight')->default(false);
            $table->unsignedInteger('from_position')->nullable();
            $table->unsignedInteger('to_position')->nullable();
            $table->foreignId('sorted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sorted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('submitted_revision')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('approved_revision')->nullable();
            $table->timestamp('shopify_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'collection_gid', 'product_key'], 'syv_sort_state_unique');
            $table->index(['draft_id', 'status'], 'syv_sort_state_draft_status');
            $table->index('product_key', 'syv_sort_state_product');
        });

        Schema::create('syv_collection_sort_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sort_state_id')->constrained('syv_collection_sort_states')->cascadeOnDelete();
            $table->foreignId('draft_id')->constrained('shop_your_vibe_drafts')->cascadeOnDelete();
            $table->string('collection_gid');
            $table->string('product_key');
            $table->string('type', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->nullable();
            $table->unsignedInteger('from_position')->nullable();
            $table->unsignedInteger('to_position')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['draft_id', 'product_key'], 'syv_sort_event_product');
        });

        Schema::create('syv_verified_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('shop_your_vibe_drafts')->cascadeOnDelete();
            $table->string('parent_collection_gid');
            $table->string('collection_gid');
            $table->string('collection_title')->nullable();
            $table->string('product_gid');
            $table->timestamp('first_verified_at');
            $table->timestamp('last_verified_at');
            $table->timestamps();

            $table->unique(['draft_id', 'collection_gid', 'product_gid'], 'syv_verified_membership_unique');
            $table->index('product_gid', 'syv_verified_membership_product');
        });

        Schema::create('syv_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('idempotency_key')->unique();
            $table->foreignId('draft_id')->nullable()->constrained('shop_your_vibe_drafts')->nullOnDelete();
            $table->foreignId('product_launch_item_id')->nullable()->constrained('product_launch_items')->nullOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_tag_provenance', function (Blueprint $table): void {
            $table->id();
            $table->string('taggable_type');
            $table->unsignedBigInteger('taggable_id');
            $table->string('tag');
            $table->string('label');
            $table->string('source', 16);
            $table->timestamps();

            $table->unique(['taggable_type', 'taggable_id', 'tag'], 'product_tag_provenance_unique');
            $table->index(['taggable_type', 'taggable_id'], 'product_tag_provenance_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tag_provenance');
        Schema::dropIfExists('syv_notifications');
        Schema::dropIfExists('syv_verified_memberships');
        Schema::dropIfExists('syv_collection_sort_events');
        Schema::dropIfExists('syv_collection_sort_states');
    }
};
