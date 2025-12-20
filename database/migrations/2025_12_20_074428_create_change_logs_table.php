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
        Schema::create('change_logs', function (Blueprint $table) {
            $table->id();

            // Keep it tied to a dataset version
            $table->foreignId('import_id')
                ->nullable()
                ->constrained('imports')
                ->nullOnDelete();

            // Often you want quick access to "what changed for this product"
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // Optional: if you want row-level trace (raw shopify row)
            $table->foreignId('shopify_row_id')
                ->nullable()
                ->constrained('shopify_rows')
                ->nullOnDelete();

            // Who made the change
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // What changed
            $table->string('model_type')->nullable(); // e.g. App\Models\Product
            $table->unsignedBigInteger('model_id')->nullable(); // ID within model_type
            $table->string('field'); // e.g. "title" or "Variant Price" etc.

            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();

            $table->timestamps();

            $table->index(['import_id', 'product_id']);
            $table->index(['model_type', 'model_id']);
            $table->index(['changed_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_logs');
    }
};
