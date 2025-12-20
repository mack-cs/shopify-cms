<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
             $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable();

            $table->string('option1_name')->nullable();
            $table->string('option1_value')->nullable();
            $table->string('option2_name')->nullable();
            $table->string('option2_value')->nullable();
            $table->string('option3_name')->nullable();
            $table->string('option3_value')->nullable();

            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();

            $table->integer('inventory_qty')->nullable();
            $table->string('inventory_policy')->nullable();

            $table->boolean('requires_shipping')->nullable();
            $table->boolean('taxable')->nullable();

            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit')->nullable();

            $table->unsignedInteger('position')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
