<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deletion_request_id')->constrained('deletion_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['deletion_request_id', 'user_id'], 'deletion_request_approvals_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_request_approvals');
    }
};
