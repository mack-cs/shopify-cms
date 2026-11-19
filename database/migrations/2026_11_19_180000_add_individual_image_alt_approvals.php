<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (! Schema::hasColumn('images', 'alt_inheritance_mode')) {
                $table->string('alt_inheritance_mode', 24)->nullable();
            }
        });
        if (! Schema::hasTable('image_alt_approvals')) {
            Schema::create('image_alt_approvals', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('image_id')->index();
                $table->unsignedBigInteger('image_asset_id');
                $table->string('asset_sha256', 64);
                $table->text('alt_text');
                $table->unsignedBigInteger('user_id');
                $table->string('context', 32);
                $table->text('reason');
                $table->json('snapshot');
                $table->timestamp('approved_at');
            });
        }
        if (! Schema::hasTable('image_alt_review_events')) {
            Schema::create('image_alt_review_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('image_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->string('action', 40);
                $table->text('reason');
                $table->json('snapshot');
                $table->timestamp('created_at');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain image and delivery evidence during application rollback; destructive schema rollback requires separate review.');
    }
};
