<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('deletable');
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type', 32);
            $table->string('entity_title', 255)->nullable();
            $table->string('entity_handle', 255)->nullable();
            $table->string('shopify_id', 128)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'deletable_type', 'deletable_id'], 'deletion_requests_status_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_requests');
    }
};
