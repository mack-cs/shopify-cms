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
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
             $table->string('filename');
            $table->enum('mode', ['overwrite', 'append'])->default('overwrite');
            $table->enum('status', ['uploaded', 'processing', 'ready', 'failed'])->default('uploaded');
            $table->foreignId('created_by')->constrained('users');
            $table->json('headers')->nullable(); // preserves column order exactly
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
