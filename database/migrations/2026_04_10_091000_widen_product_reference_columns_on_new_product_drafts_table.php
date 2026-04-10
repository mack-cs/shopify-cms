<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->longText('siblings')->nullable()->change();
            $table->longText('complementary_products')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->string('siblings', 255)->nullable()->change();
            $table->text('complementary_products')->nullable()->change();
        });
    }
};
