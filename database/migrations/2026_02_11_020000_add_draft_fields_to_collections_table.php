<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->string('draft_title', 255)->nullable()->after('approval_version');
            $table->longText('draft_description_html')->nullable()->after('draft_title');
            $table->string('draft_seo_title', 255)->nullable()->after('draft_description_html');
            $table->string('draft_seo_description', 512)->nullable()->after('draft_seo_title');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn([
                'draft_title',
                'draft_description_html',
                'draft_seo_title',
                'draft_seo_description',
            ]);
        });
    }
};
