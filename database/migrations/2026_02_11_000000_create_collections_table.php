<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_id')
                ->constrained('imports')
                ->cascadeOnDelete();

            // Shopify GID for the collection
            $table->string('shopify_id', 128)->index();

            // Handle controls the collection URL slug
            $table->string('handle', 255)->index();

            // On-page title and description
            $table->string('title', 255)->nullable();
            $table->longText('description_html')->nullable();

            // SEO metadata
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 512)->nullable();

            $table->timestamps();

            $table->unique(['import_id', 'shopify_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
