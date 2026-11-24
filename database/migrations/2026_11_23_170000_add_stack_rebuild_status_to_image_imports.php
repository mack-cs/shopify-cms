<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('image_rebuild_status', 32)->nullable()->after('image_import_status');
            $table->text('image_rebuild_message')->nullable()->after('image_rebuild_status');
            $table->unsignedBigInteger('image_rebuild_batch_id')->nullable()->index()->after('image_rebuild_message');
        });

        Schema::create('stack_rebuild_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('claim_key', 255)->unique();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->string('owner', 64);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shopify_image_import_batches', function (Blueprint $table): void {
            $table->string('rebuild_status', 32)->nullable()->after('error_message');
            $table->text('rebuild_message')->nullable()->after('rebuild_status');
            $table->json('rebuild_stack_product_ids')->nullable()->after('rebuild_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stack_rebuild_claims');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['image_rebuild_status', 'image_rebuild_message', 'image_rebuild_batch_id']);
        });

        Schema::table('shopify_image_import_batches', function (Blueprint $table): void {
            $table->dropColumn(['rebuild_status', 'rebuild_message', 'rebuild_stack_product_ids']);
        });
    }
};
