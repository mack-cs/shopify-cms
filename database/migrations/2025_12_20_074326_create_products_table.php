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
    Schema::create('products', function (Blueprint $table) {
        $table->id();

        $table->foreignId('import_id')
            ->constrained('imports')
            ->cascadeOnDelete();

        // Shopify handle/slug
        $table->string('handle', 255)->index();

        $table->unsignedInteger('approval_version')->default(1);

        // Product title: Shopify allows large, but SEO display is short.
        // Store up to 255.
        $table->string('title', 255)->nullable();

        // HTML description can be very large
        $table->longText('body_html')->nullable();

        // Brand/vendor names usually small
        $table->string('vendor', 255)->nullable();

        // Tags can be very long (you already saw this)
        $table->longText('tags')->nullable();

        // Shopify category label can be long-ish
        $table->string('product_category', 255)->nullable();

        // Google product category can also be long-ish
        $table->string('google_product_category', 255)->nullable();

        // draft/active/archived etc - this is small
        $table->string('status', 32)->nullable();

        // SEO title (meta title) - store up to 255, enforce app-level display rules
        $table->string('seo_title', 255)->nullable();

        // SEO description: if you want to enforce a max, use 512.
        // If you want no enforcement, use text().
        $table->string('seo_description', 512)->nullable();

        // Colors as a semicolon-separated string can exceed 255; give more room
        $table->string('color_string', 512)->nullable();

        $table->timestamps();

        $table->unique(['import_id', 'handle']);
    });
}


    // public function up(): void
    // {
    //     Schema::create('products', function (Blueprint $table) {
    //         $table->id();
    //         $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
    //         $table->string('handle')->index();
    //         $table->unsignedInteger('approval_version')->default(1);
    //         $table->string('title')->nullable();
    //         $table->longText('body_html')->nullable();
    //         $table->string('vendor')->nullable();
    //         $table->string('tags')->nullable();

    //         $table->string('product_category')->nullable();
    //         $table->string('google_product_category')->nullable();

    //         $table->string('status')->nullable(); // or enum draft/active/archived if you want
    //         $table->string('seo_title')->nullable();
    //         $table->text('seo_description')->nullable();

    //         $table->string('color_string')->nullable(); // e.g. Red;Gold;Black

    //         $table->timestamps();

    //         $table->unique(['import_id', 'handle']);
    //     });
    // }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
