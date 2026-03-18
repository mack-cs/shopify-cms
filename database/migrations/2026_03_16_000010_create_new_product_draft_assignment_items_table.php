<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_product_draft_assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')
                ->constrained('new_product_draft_assignments')
                ->cascadeOnDelete();
            $table->foreignId('new_product_draft_id')
                ->nullable()
                ->constrained('new_product_drafts')
                ->nullOnDelete();
            $table->string('handle', 255)->nullable();
            $table->string('title', 255)->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'new_product_draft_id'], 'draft_assignment_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_product_draft_assignment_items');
    }
};
