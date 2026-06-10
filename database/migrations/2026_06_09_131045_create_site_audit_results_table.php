<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_audit_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_audit_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_audit_url_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('result');
            $table->string('final_url')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['site_audit_run_id', 'result']);
            $table->index(['site_audit_url_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_audit_results');
    }
};
