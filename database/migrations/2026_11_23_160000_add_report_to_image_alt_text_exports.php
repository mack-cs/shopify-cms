<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_alt_text_exports', function (Blueprint $table): void {
            $table->json('report')->nullable()->after('collection_gids');
        });
    }

    public function down(): void
    {
        Schema::table('image_alt_text_exports', function (Blueprint $table): void {
            $table->dropColumn('report');
        });
    }
};
