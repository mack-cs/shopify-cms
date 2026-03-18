<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            if (Schema::hasColumn('variants', 'image_src')) {
                $table->dropColumn('image_src');
            }
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            if (!Schema::hasColumn('variants', 'image_src')) {
                $table->string('image_src', 2048)->nullable()->after('barcode');
            }
        });
    }
};
