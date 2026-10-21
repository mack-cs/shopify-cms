<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_operation_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('correlation_key', 191)->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32);
            $table->string('execution', 32);
            $table->string('process_name')->nullable();
            $table->string('workflow', 64)->index();
            $table->string('action', 64);
            $table->string('shopify_reason')->nullable();
            $table->text('explanation')->nullable();
            $table->string('inventory_state', 32)->nullable();
            $table->integer('delta')->nullable();
            $table->integer('quantity_before')->nullable();
            $table->integer('quantity_after')->nullable();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->string('sku')->nullable()->index();
            $table->string('shopify_inventory_item_id')->nullable()->index();
            $table->string('shopify_location_id')->nullable();
            $table->string('reference_document_uri', 512)->nullable()->index();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('shopify_adjustment_group_id')->nullable();
            $table->json('shopify_response')->nullable();
            $table->string('status', 32)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_operation_audits');
    }
};
