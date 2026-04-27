<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_partial_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('approval_version')->default(1);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('scopes')->nullable();
            $table->json('core_fields')->nullable();
            $table->text('request_note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'approval_version', 'status'], 'product_partial_approval_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_partial_approval_requests');
    }
};
