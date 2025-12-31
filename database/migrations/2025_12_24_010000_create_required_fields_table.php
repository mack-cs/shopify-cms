<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('required_fields', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 32);
            $table->string('source', 32);
            $table->string('attribute');
            $table->string('label');
            $table->boolean('required')->default(false);
            $table->timestamps();

            $table->unique(['source', 'attribute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('required_fields');
    }
};
