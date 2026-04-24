<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('footer_title', 255)->nullable()->after('seo_description');
            $table->string('elegant_footer_description', 255)->nullable()->after('footer_title');
            $table->string('draft_footer_title', 255)->nullable()->after('draft_seo_description');
            $table->string('draft_elegant_footer_description', 255)->nullable()->after('draft_footer_title');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn([
                'footer_title',
                'elegant_footer_description',
                'draft_footer_title',
                'draft_elegant_footer_description',
            ]);
        });
    }
};
