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
        Schema::create('shopify_rows', function (Blueprint $table) {
            $table->id();
             $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('row_index'); // original CSV order
            $table->string('handle')->nullable()->index();

            $table->enum('row_type', ['product_primary', 'variant', 'image', 'unknown'])->default('unknown');
            $table->string('variant_key')->nullable()->index(); // SKU preferred, else option signature
            $table->string('image_key')->nullable()->index();   // src|position, etc

            $table->json('data'); // full row payload: header => value
            $table->timestamps();
            $table->unique(['import_id', 'row_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_rows');
    }
};
