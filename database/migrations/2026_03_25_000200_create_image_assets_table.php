<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_assets', function (Blueprint $table) {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->string('storage_disk')->default('public');
            $table->string('storage_path', 2048)->unique();
            $table->string('original_filename')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('mime_type')->nullable();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('downloaded_at')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('missing_at')->nullable();
            $table->string('status')->default('available')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_assets');
    }
};
