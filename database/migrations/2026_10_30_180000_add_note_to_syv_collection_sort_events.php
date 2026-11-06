<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syv_collection_sort_events', function (Blueprint $table): void {
            $table->text('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('syv_collection_sort_events', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
