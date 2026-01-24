<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropdown_options', function (Blueprint $table) {
            $table->id();
            $table->string('header');
            $table->string('value');
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('collection_style')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['header', 'active']);
            $table->index(['header', 'vendor', 'product_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropdown_options');
    }
};
