<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'first_image_auto_rename_completed_at')) {
                $table->timestamp('first_image_auto_rename_completed_at')
                    ->nullable()
                    ->after('approval_version');
            }

            if (!Schema::hasColumn('products', 'first_image_auto_rename_approval_version')) {
                $table->unsignedInteger('first_image_auto_rename_approval_version')
                    ->nullable()
                    ->after('first_image_auto_rename_completed_at');
            }
        });

        Schema::table('images', function (Blueprint $table) {
            if (!Schema::hasColumn('images', 'approved_filename')) {
                $table->string('approved_filename', 255)
                    ->nullable()
                    ->after('backup_error');
            }

            if (!Schema::hasColumn('images', 'filename_mode')) {
                $table->string('filename_mode', 32)
                    ->default('auto')
                    ->after('approved_filename');
                $table->index('filename_mode');
            }

            if (!Schema::hasColumn('images', 'last_shopify_synced_image_asset_id')) {
                $table->foreignId('last_shopify_synced_image_asset_id')
                    ->nullable()
                    ->after('filename_mode')
                    ->constrained('image_assets')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('images', 'last_shopify_synced_filename')) {
                $table->string('last_shopify_synced_filename', 255)
                    ->nullable()
                    ->after('last_shopify_synced_image_asset_id');
            }

            if (!Schema::hasColumn('images', 'last_shopify_image_synced_at')) {
                $table->timestamp('last_shopify_image_synced_at')
                    ->nullable()
                    ->after('last_shopify_synced_filename');
            }

            if (!Schema::hasColumn('images', 'needs_shopify_image_sync')) {
                $table->boolean('needs_shopify_image_sync')
                    ->default(true)
                    ->after('last_shopify_image_synced_at');
                $table->index('needs_shopify_image_sync');
            }

            if (!Schema::hasColumn('images', 'shopify_image_sync_error')) {
                $table->text('shopify_image_sync_error')
                    ->nullable()
                    ->after('needs_shopify_image_sync');
            }
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            if (Schema::hasColumn('images', 'shopify_image_sync_error')) {
                $table->dropColumn('shopify_image_sync_error');
            }

            if (Schema::hasColumn('images', 'needs_shopify_image_sync')) {
                $table->dropIndex(['needs_shopify_image_sync']);
                $table->dropColumn('needs_shopify_image_sync');
            }

            if (Schema::hasColumn('images', 'last_shopify_image_synced_at')) {
                $table->dropColumn('last_shopify_image_synced_at');
            }

            if (Schema::hasColumn('images', 'last_shopify_synced_filename')) {
                $table->dropColumn('last_shopify_synced_filename');
            }

            if (Schema::hasColumn('images', 'last_shopify_synced_image_asset_id')) {
                $table->dropConstrainedForeignId('last_shopify_synced_image_asset_id');
            }

            if (Schema::hasColumn('images', 'filename_mode')) {
                $table->dropIndex(['filename_mode']);
                $table->dropColumn('filename_mode');
            }

            if (Schema::hasColumn('images', 'approved_filename')) {
                $table->dropColumn('approved_filename');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'first_image_auto_rename_approval_version')) {
                $table->dropColumn('first_image_auto_rename_approval_version');
            }

            if (Schema::hasColumn('products', 'first_image_auto_rename_completed_at')) {
                $table->dropColumn('first_image_auto_rename_completed_at');
            }
        });
    }
};
