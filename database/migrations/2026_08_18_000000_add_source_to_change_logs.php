<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_logs', function (Blueprint $table): void {
            $table->string('source', 128)->nullable()->after('changed_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('change_logs', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
