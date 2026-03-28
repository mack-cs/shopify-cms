<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_disk')->default('public');
            $table->string('storage_path', 768);
            $table->string('filename');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('header_count')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique('import_id');
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_sync_snapshots');
    }
};
