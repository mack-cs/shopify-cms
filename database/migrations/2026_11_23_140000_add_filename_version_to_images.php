<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Import workers read this column before changing any image.
     * Apply this migration before those workers process a batch.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 'filename_version')) {
                $table->unsignedInteger('filename_version')->nullable()->after('alt_text_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (Schema::hasColumn('images', 'filename_version')) {
                $table->dropColumn('filename_version');
            }
        });
    }
};
