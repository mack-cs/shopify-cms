<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * MySQL commits DDL statements as they run. If this migration fails
         * while adding an index, the unregistered report tables can remain and
         * prevent a clean retry. A completed migration can never enter up()
         * again, so any tables found here are remnants of an incomplete run.
         */
        if (
            Schema::hasTable('shopify_collection_product_report_rows')
            || Schema::hasTable('shopify_collection_product_report_runs')
        ) {
            Schema::dropIfExists('shopify_collection_product_report_rows');
            Schema::dropIfExists('shopify_collection_product_report_runs');
        }

        Schema::create('shopify_collection_product_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('queued')->index();
            $table->string('api_version', 32)->nullable();
            $table->string('online_store_publication_id', 128)->nullable();
            $table->string('online_store_publication_name')->nullable();
            $table->unsignedInteger('collection_count')->default(0);
            $table->unsignedInteger('relationship_count')->default(0);
            $table->unsignedInteger('failed_collection_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_collection_product_report_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shopify_collection_product_report_run_id');
            $table->foreign('shopify_collection_product_report_run_id', 'scp_report_rows_run_fk')
                ->references('id')->on('shopify_collection_product_report_runs')->cascadeOnDelete();

            $table->string('collection_id', 128)->index();
            $table->string('collection_title')->nullable()->index();
            $table->string('collection_handle')->nullable()->index();
            $table->string('collection_url')->nullable();
            $table->longText('collection_description')->nullable();
            $table->longText('collection_description_html')->nullable();
            $table->string('collection_sort_order', 64)->nullable();
            $table->string('collection_template_suffix')->nullable();
            $table->unsignedInteger('collection_product_count')->default(0);
            $table->timestamp('collection_updated_at')->nullable();
            $table->string('collection_image_url')->nullable();
            $table->string('collection_image_alt')->nullable();
            $table->unsignedInteger('collection_image_width')->nullable();
            $table->unsignedInteger('collection_image_height')->nullable();
            $table->boolean('collection_published_online')->default(false);
            $table->timestamp('collection_online_publish_date')->nullable();
            $table->json('collection_publications')->nullable();

            $table->string('product_id', 128)->nullable()->index();
            $table->string('product_title')->nullable()->index();
            $table->string('product_handle')->nullable()->index();
            $table->string('product_url')->nullable();
            $table->string('product_online_store_url')->nullable();
            $table->string('product_status', 32)->nullable()->index();
            $table->string('vendor')->nullable()->index();
            $table->string('product_type')->nullable()->index();
            $table->timestamp('product_created_at')->nullable();
            $table->timestamp('product_updated_at')->nullable();
            $table->timestamp('product_published_at')->nullable();
            $table->json('tags')->nullable();
            $table->integer('total_inventory')->nullable();
            $table->string('featured_image_url')->nullable();
            $table->string('featured_image_alt')->nullable();
            $table->string('product_category_id', 128)->nullable();
            $table->string('product_category_name')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->text('sku_summary')->nullable();
            $table->json('variants')->nullable();
            $table->timestamps();

            $table->index(
                ['shopify_collection_product_report_run_id', 'collection_published_online'],
                'scp_rows_run_visibility_idx'
            );
            $table->index('collection_published_online', 'scp_rows_visibility_idx');
            $table->unique(
                ['shopify_collection_product_report_run_id', 'collection_id', 'product_id'],
                'scp_rows_run_collection_product_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_collection_product_report_rows');
        Schema::dropIfExists('shopify_collection_product_report_runs');
    }
};
