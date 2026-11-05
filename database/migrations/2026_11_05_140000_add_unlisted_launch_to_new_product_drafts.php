<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->boolean('unlisted_launch')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->dropColumn('unlisted_launch');
        });
    }
};
