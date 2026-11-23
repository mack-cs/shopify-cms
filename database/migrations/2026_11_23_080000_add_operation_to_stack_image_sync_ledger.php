<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stack_image_sync_ledger', function (Blueprint $table): void {
            if (! Schema::hasColumn('stack_image_sync_ledger', 'operation')) {
                $table->string('operation', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stack_image_sync_ledger', function (Blueprint $table): void {
            if (Schema::hasColumn('stack_image_sync_ledger', 'operation')) {
                $table->dropIndex(['operation']);
                $table->dropColumn('operation');
            }
        });
    }
};
