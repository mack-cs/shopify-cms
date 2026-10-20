<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_your_vibe_drafts', function (Blueprint $table): void {
            $table->json('operation')->nullable()->after('remote_jobs');
        });
    }

    public function down(): void
    {
        Schema::table('shop_your_vibe_drafts', function (Blueprint $table): void {
            $table->dropColumn('operation');
        });
    }
};
