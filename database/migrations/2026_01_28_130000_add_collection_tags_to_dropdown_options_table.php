<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropdown_options', function (Blueprint $table) {
            $table->string('collection_tag_primary')->nullable()->after('collection_style');
            $table->string('collection_tag_secondary')->nullable()->after('collection_tag_primary');

            $table->index(['collection_tag_primary', 'collection_tag_secondary'], 'dropdown_options_collection_tags_index');
        });
    }

    public function down(): void
    {
        Schema::table('dropdown_options', function (Blueprint $table) {
            $table->dropIndex('dropdown_options_collection_tags_index');
            $table->dropColumn(['collection_tag_primary', 'collection_tag_secondary']);
        });
    }
};
