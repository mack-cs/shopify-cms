<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('style_profiles', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
        });

        Schema::table('style_profiles', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('style_profiles', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('style_profiles', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
        });

        Schema::table('style_profiles', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
