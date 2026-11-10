<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_alt_text_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('fingerprint', 64)->nullable()->unique();
            $table->json('criteria');
            $table->json('collection_gids')->nullable();
            $table->boolean('include_images');
            $table->string('connection');
            $table->string('queue');
            $table->string('disk');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('download_failed')->default(0);
            $table->text('error')->nullable();
            $table->string('path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('image_alt_text_export_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('export_id')->constrained('image_alt_text_exports')->cascadeOnDelete();
            // Deliberately not an FK: this is an immutable export snapshot, even if an image is later deleted.
            $table->unsignedBigInteger('image_id');
            $table->json('csv_row');
            $table->string('status')->default('pending');
            $table->string('path')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unique(['export_id', 'image_id']);
            $table->index(['export_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_alt_text_export_items');
        Schema::dropIfExists('image_alt_text_exports');
    }
};
