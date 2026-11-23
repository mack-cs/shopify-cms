<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores the original S3 filename used for CSV matching.
     * Existing approved filenames are left unchanged until a later import.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 's3_filename')) {
                $table->string('s3_filename')->nullable()->after('approved_filename');
            }
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (Schema::hasColumn('images', 's3_filename')) {
                $table->dropColumn('s3_filename');
            }
        });
    }
};
