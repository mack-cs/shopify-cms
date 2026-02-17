<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->string('batch', 32)->nullable()->index()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropIndex(['batch']);
            $table->dropColumn('batch');
        });
    }
};
