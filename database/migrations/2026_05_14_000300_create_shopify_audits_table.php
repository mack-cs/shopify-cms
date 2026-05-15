<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('audit_type', 64)->index();
            $table->string('status', 32)->index();
            $table->boolean('needs_attention')->default(false)->index();
            $table->unsignedInteger('local_saved_count')->default(0);
            $table->unsignedInteger('local_valid_count')->default(0);
            $table->unsignedInteger('shopify_current_count')->default(0);
            $table->unsignedInteger('shopify_valid_count')->default(0);
            $table->json('details')->nullable();
            $table->timestamp('last_checked_at')->nullable()->index();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'audit_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_audits');
    }
};
