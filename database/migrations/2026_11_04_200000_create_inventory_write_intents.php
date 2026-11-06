<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', fn (Blueprint $table) => $table->unsignedBigInteger('inventory_write_version')->default(0));
        Schema::create('inventory_write_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->string('intent_key', 64)->unique();
            $table->json('payload');
            $table->string('status')->default('started')->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_write_intents');
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('inventory_write_version'));
    }
};
