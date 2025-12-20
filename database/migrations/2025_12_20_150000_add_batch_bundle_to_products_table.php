<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('batch', 64)->nullable()->index();
            $table->boolean('is_bundle')->default(false)->index();
            $table->decimal('you_save', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['batch']);
            $table->dropIndex(['is_bundle']);
            $table->dropColumn(['batch', 'is_bundle', 'you_save']);
        });
    }
};
