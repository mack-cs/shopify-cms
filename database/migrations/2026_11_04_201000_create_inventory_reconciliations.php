<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('inventory_item_id')->unique();
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('processed_version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reconciliations');
    }
};
