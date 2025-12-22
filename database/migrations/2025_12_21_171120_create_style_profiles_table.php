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
        Schema::create('style_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // This sheet is per SKU (variant). Store SKU explicitly.
            $table->string('sku')->index();

            // Draft inputs (not exported)
            $table->string('style_type')->nullable();       // e.g. Bracelet
            $table->string('materials')->nullable();
            $table->string('components')->nullable();
            $table->text('colour_prompt')->nullable();

            // Draft outputs (still internal until applied)
            $table->string('draft_title')->nullable();
            $table->longText('draft_description')->nullable();
            $table->string('draft_seo_description', 255)->nullable();
            $table->string('draft_image_alt_text', 255)->nullable();

            // Optional: track lifecycle
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();


            // one draft row per product+sku (matches your sheet concept)
            $table->unique(['product_id', 'sku']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('style_profiles');
    }
};
