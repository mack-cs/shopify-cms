<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_url_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_handle');
            $table->string('new_handle');
            $table->string('path', 512);
            $table->string('target', 512);
            $table->string('status')->default('pending');
            $table->string('shopify_redirect_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique('path');
            $table->index(['collection_id', 'status']);
            $table->index('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_url_redirects');
    }
};
