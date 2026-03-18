<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_product_draft_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->json('to_emails');
            $table->json('cc_emails')->nullable();
            $table->string('subject', 255);
            $table->text('body')->nullable();
            $table->json('context_columns')->nullable();
            $table->json('selected_columns');
            $table->string('csv_disk', 64)->default('local');
            $table->string('csv_path', 512)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['sent_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_product_draft_assignments');
    }
};
