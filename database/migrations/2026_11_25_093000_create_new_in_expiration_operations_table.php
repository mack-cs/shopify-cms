<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_in_expiration_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('product_gid')->index();
            $table->string('active_product_gid')->nullable()->unique();
            $table->string('status', 32)->index();
            $table->json('tags_to_remove');
            $table->json('before_tags')->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->string('shopify_created_at_raw')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('mutating_at')->nullable();
            $table->timestamp('remote_confirmed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_in_expiration_operations');
    }
};
