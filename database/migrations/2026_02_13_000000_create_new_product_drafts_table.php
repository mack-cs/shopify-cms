<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_product_drafts', function (Blueprint $table) {
            $table->id();

            $table->string('handle')->nullable()->unique();
            $table->string('shopify_id')->nullable()->index();
            $table->string('sku')->nullable()->index();

            $table->string('title', 255);
            $table->longText('body_html')->nullable();
            $table->string('vendor', 255)->nullable();
            $table->string('product_category', 255)->nullable();

            $table->string('status', 32)->default('draft');
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 512)->nullable();

            $table->decimal('variant_price', 10, 2)->nullable();
            $table->decimal('variant_compare_at_price', 10, 2)->nullable();
            $table->integer('variant_inventory_qty')->nullable();
            $table->string('variant_inventory_policy', 32)->default('deny');
            $table->string('variant_fulfillment_service', 64)->default('manual');

            $table->json('payload')->nullable();

            $table->unsignedInteger('approval_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_product_drafts');
    }
};
