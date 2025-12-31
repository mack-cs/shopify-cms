<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('seo_periods')->cascadeOnDelete();
            $table->string('entity_type'); // query|page
            $table->string('entity_value', 1024);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 2)->default(0);
            $table->decimal('position', 7, 2)->default(0);
            $table->timestamps();

            $table->index(['period_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_metrics');
    }
};
