<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            if (!Schema::hasColumn('images', 'image_asset_id')) {
                $table->foreignId('image_asset_id')
                    ->nullable()
                    ->after('shopify_id')
                    ->constrained('image_assets')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('images', 'backup_status')) {
                $table->string('backup_status')
                    ->default('pending')
                    ->after('image_path');
                $table->index('backup_status');
            }

            if (!Schema::hasColumn('images', 'backup_completed_at')) {
                $table->timestamp('backup_completed_at')
                    ->nullable()
                    ->after('backup_status');
            }

            if (!Schema::hasColumn('images', 'backup_error')) {
                $table->text('backup_error')
                    ->nullable()
                    ->after('backup_completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            if (Schema::hasColumn('images', 'image_asset_id')) {
                $table->dropConstrainedForeignId('image_asset_id');
            }

            if (Schema::hasColumn('images', 'backup_status')) {
                $table->dropIndex(['backup_status']);
                $table->dropColumn('backup_status');
            }

            if (Schema::hasColumn('images', 'backup_completed_at')) {
                $table->dropColumn('backup_completed_at');
            }

            if (Schema::hasColumn('images', 'backup_error')) {
                $table->dropColumn('backup_error');
            }
        });
    }
};
