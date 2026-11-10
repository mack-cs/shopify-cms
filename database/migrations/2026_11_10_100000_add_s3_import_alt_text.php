<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            $table->string('imported_alt_text')->nullable()->after('alt_text');
        });

        Schema::table('shopify_image_import_batches', function (Blueprint $table): void {
            $table->boolean('alt_text_csv_found')->default(false)->after('failed_count');
            $table->unsignedInteger('alt_text_applied')->default(0)->after('alt_text_csv_found');
            $table->unsignedInteger('alt_text_missing')->default(0)->after('alt_text_applied');
            $table->unsignedInteger('alt_text_unchanged')->default(0)->after('alt_text_missing');
            $table->unsignedInteger('alt_text_preserved')->default(0)->after('alt_text_unchanged');
            $table->unsignedInteger('alt_text_error_count')->default(0)->after('alt_text_preserved');
            $table->json('alt_text_report')->nullable()->after('alt_text_error_count');
        });

        Schema::table('shopify_image_import_items', function (Blueprint $table): void {
            $table->text('alt_message')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_image_import_items', function (Blueprint $table): void {
            $table->dropColumn('alt_message');
        });

        Schema::table('shopify_image_import_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'alt_text_csv_found',
                'alt_text_applied',
                'alt_text_missing',
                'alt_text_unchanged',
                'alt_text_preserved',
                'alt_text_error_count',
                'alt_text_report',
            ]);
        });

        Schema::table('images', function (Blueprint $table): void {
            $table->dropColumn('imported_alt_text');
        });
    }
};
