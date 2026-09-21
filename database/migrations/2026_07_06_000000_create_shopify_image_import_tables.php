<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_image_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('s3_prefix')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_image_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('shopify_image_import_batches')->cascadeOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('s3_key')->index();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('shopify_product_id')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 's3_key']);
        });

        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'image_import_batch_id')) {
                $table->foreignId('image_import_batch_id')
                    ->nullable()
                    ->after('last_synced_at')
                    ->constrained('shopify_image_import_batches')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('products', 'image_imported_at')) {
                $table->timestamp('image_imported_at')
                    ->nullable()
                    ->after('image_import_batch_id')
                    ->index();
            }

            if (!Schema::hasColumn('products', 'image_import_status')) {
                $table->string('image_import_status', 32)
                    ->nullable()
                    ->after('image_imported_at')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'image_import_status')) {
                $table->dropIndex(['image_import_status']);
                $table->dropColumn('image_import_status');
            }

            if (Schema::hasColumn('products', 'image_imported_at')) {
                $table->dropIndex(['image_imported_at']);
                $table->dropColumn('image_imported_at');
            }

            if (Schema::hasColumn('products', 'image_import_batch_id')) {
                $table->dropConstrainedForeignId('image_import_batch_id');
            }
        });

        Schema::dropIfExists('shopify_image_import_items');
        Schema::dropIfExists('shopify_image_import_batches');
    }
};
