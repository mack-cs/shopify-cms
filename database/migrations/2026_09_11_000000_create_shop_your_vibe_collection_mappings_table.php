<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_your_vibe_collection_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_collection_id');
            $table->string('parent_collection_id');
            $table->string('collection_name');
            $table->string('collection_handle');
            $table->string('membership_tag')->nullable();
            $table->string('design_value')->nullable();
            $table->string('colour_style_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_collection_id', 'shopify_collection_id'], 'syv_mapping_parent_collection_unique');
            $table->index(['parent_collection_id', 'is_active'], 'syv_mapping_parent_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_your_vibe_collection_mappings');
    }
};
