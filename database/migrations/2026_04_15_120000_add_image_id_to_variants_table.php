<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            if (!Schema::hasColumn('variants', 'image_id')) {
                $table->foreignId('image_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('images')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            if (Schema::hasColumn('variants', 'image_id')) {
                $table->dropConstrainedForeignId('image_id');
            }
        });
    }
};
