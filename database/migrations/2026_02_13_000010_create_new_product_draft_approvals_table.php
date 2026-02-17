<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_product_draft_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('new_product_draft_id')
                ->constrained('new_product_drafts')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('approval_version')->default(1);
            $table->timestamps();
            $table->unique(
                ['new_product_draft_id', 'user_id', 'approval_version'],
                'new_product_draft_user_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_product_draft_approvals');
    }
};
