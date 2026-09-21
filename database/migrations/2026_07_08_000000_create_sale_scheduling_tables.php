<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sale_import_batches')) {
            Schema::create('sale_import_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('filename')->nullable();
                $table->string('status', 32)->default('completed')->index();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sale_import_items')) {
            Schema::create('sale_import_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sale_import_batch_id')->constrained('sale_import_batches')->cascadeOnDelete();
                $table->string('sku')->index();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
                $table->decimal('old_price', 10, 2)->nullable();
                $table->decimal('compare_at_price', 10, 2)->nullable();
                $table->decimal('sale_price', 10, 2)->nullable();
                $table->string('status', 32)->default('matched')->index();
                $table->text('message')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('scheduled_jobs')) {
            Schema::create('scheduled_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 64)->index();
                $table->string('status', 32)->default('draft')->index();
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->text('error_summary')->nullable();
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('succeeded_items')->default(0);
                $table->unsignedInteger('failed_items')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sale_product_updates')) {
            Schema::create('sale_product_updates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sale_import_batch_id')->nullable()->constrained('sale_import_batches')->nullOnDelete();
                $table->foreignId('scheduled_job_id')->nullable()->constrained('scheduled_jobs')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
                $table->string('sku')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->decimal('current_price', 10, 2)->nullable();
                $table->decimal('imported_old_price', 10, 2)->nullable();
                $table->decimal('sale_price', 10, 2);
                $table->decimal('compare_at_price', 10, 2);
                $table->text('existing_tags')->nullable();
                $table->text('prepared_tags')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('pushed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['product_id', 'status']);
                $table->index(['variant_id', 'status']);
            });
        }

        if (!Schema::hasTable('scheduled_job_items')) {
            Schema::create('scheduled_job_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scheduled_job_id')->constrained('scheduled_jobs')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('sale_product_update_id')->nullable()->constrained('sale_product_updates')->nullOnDelete();
                $table->string('sku')->nullable()->index();
                $table->string('status', 32)->default('pending')->index();
                $table->json('payload')->nullable();
                $table->json('response')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['scheduled_job_id', 'sale_product_update_id'], 'sched_sale_update_unique');
            });
        } elseif (!Schema::hasIndex('scheduled_job_items', 'sched_sale_update_unique')) {
            Schema::table('scheduled_job_items', function (Blueprint $table): void {
                $table->unique(['scheduled_job_id', 'sale_product_update_id'], 'sched_sale_update_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_job_items');
        Schema::dropIfExists('sale_product_updates');
        Schema::dropIfExists('scheduled_jobs');
        Schema::dropIfExists('sale_import_items');
        Schema::dropIfExists('sale_import_batches');
    }
};
