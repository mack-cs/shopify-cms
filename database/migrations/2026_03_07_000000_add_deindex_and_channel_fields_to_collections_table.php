<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('deindex')->nullable()->after('seo_description');
            $table->boolean('published_on_online_store_only')->default(false)->after('deindex');
            $table->text('published_channel_names')->nullable()->after('published_on_online_store_only');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn([
                'deindex',
                'published_on_online_store_only',
                'published_channel_names',
            ]);
        });
    }
};
