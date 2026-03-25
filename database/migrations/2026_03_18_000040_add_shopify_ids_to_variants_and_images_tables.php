<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->string('shopify_id')->nullable()->index()->after('product_id');
        });

        Schema::table('images', function (Blueprint $table) {
            $table->string('shopify_id')->nullable()->index()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn('shopify_id');
        });

        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn('shopify_id');
        });
    }
};
