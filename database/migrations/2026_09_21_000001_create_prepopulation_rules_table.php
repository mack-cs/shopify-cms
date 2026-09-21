<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepopulation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('behavior');
            $table->string('handle')->index();
            $table->string('collection_name')->nullable()->index();
            $table->string('layer')->nullable();
            $table->string('where_it_appears')->nullable();
            $table->json('parents')->nullable();
            $table->json('add_tags')->nullable();
            $table->json('remove_tags')->nullable();
            $table->string('auto_vendor')->nullable();
            $table->string('auto_type')->nullable();
            $table->string('auto_cms_collection')->nullable();
            $table->string('auto_product_category')->nullable();
            $table->string('auto_google_product_category')->nullable();
            $table->string('auto_design')->nullable();
            $table->string('auto_colour_style')->nullable();
            $table->string('auto_jewelry_type')->nullable();
            $table->string('auto_target_gender')->nullable();
            $table->string('auto_age_group')->nullable();
            $table->string('auto_status')->nullable();
            $table->string('flag')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_refs')->nullable();
            $table->timestamps();

            $table->unique(['behavior', 'handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepopulation_rules');
    }
};
