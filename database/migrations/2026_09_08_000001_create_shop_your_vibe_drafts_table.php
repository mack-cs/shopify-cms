<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_your_vibe_drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('collection_gid')->unique();
            $table->json('snapshot');
            $table->json('desired');
            $table->unsignedInteger('revision')->default(0);
            $table->boolean('pending')->default(false)->index();
            $table->string('status')->default('synced');
            $table->text('last_error')->nullable();
            $table->json('progress')->nullable();
            $table->json('remote_jobs')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_your_vibe_drafts');
    }
};
