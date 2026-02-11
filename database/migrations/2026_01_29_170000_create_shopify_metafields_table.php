<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_metafields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->string('handle')->index();
            $table->string('namespace');
            $table->string('key');
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'handle', 'namespace', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_metafields');
    }
};
