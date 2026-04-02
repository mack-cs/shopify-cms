<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->string('sibling_collection', 255)->nullable()->after('siblings_collection_name');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table) {
            $table->dropColumn('sibling_collection');
        });
    }
};
