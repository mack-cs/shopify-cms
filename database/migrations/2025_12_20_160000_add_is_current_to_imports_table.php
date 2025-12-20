<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->boolean('is_current')->default(false)->index();
        });

        DB::table('imports')
            ->orderByDesc('id')
            ->limit(1)
            ->update(['is_current' => true]);
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropIndex(['is_current']);
            $table->dropColumn('is_current');
        });
    }
};
