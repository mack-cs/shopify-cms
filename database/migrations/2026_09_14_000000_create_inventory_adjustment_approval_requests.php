<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustment_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 24)->default('single')->index();
            $table->string('status', 32)->default('PENDING_APPROVAL')->index();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->string('sync_batch_id')->nullable()->unique();
            $table->string('source', 32)->default('cms')->index();
            $table->string('original_filename')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('shopify_result')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_adjustment_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_adjustment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('variants')->restrictOnDelete();
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('original_on_hand_quantity')->nullable();
            $table->unsignedInteger('requested_on_hand_quantity')->nullable();
            $table->boolean('original_inventory_tracked')->nullable();
            $table->boolean('requested_inventory_tracked')->nullable();
            $table->text('reason');
            $table->json('shopify_update_result')->nullable();
            $table->timestamps();

            $table->index(['inventory_adjustment_request_id', 'variant_id'], 'inv_adj_req_items_request_variant_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_request_items');
        Schema::dropIfExists('inventory_adjustment_requests');
    }
};
