<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_your_vibe_drafts', fn (Blueprint $table) => $table->json('refresh_state')->nullable());
    }

    public function down(): void
    {
        Schema::table('shop_your_vibe_drafts', fn (Blueprint $table) => $table->dropColumn('refresh_state'));
    }
};
