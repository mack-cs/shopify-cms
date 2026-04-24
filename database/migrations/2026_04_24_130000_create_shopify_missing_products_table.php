<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_missing_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->foreignId('previous_import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->foreignId('previous_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('handle', 255)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('shopify_id', 128)->nullable();
            $table->string('vendor', 255)->nullable();
            $table->string('status', 64)->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'shopify_id']);
            $table->index(['import_id', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_missing_products');
    }
};
