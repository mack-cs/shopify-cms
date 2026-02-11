<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collection_approvals')) {
            $existing = DB::select(
                "SHOW INDEX FROM `collection_approvals` WHERE Key_name = 'collection_approvals_unique'"
            );
            if (empty($existing)) {
                Schema::table('collection_approvals', function (Blueprint $table) {
                    $table->unique(['collection_id', 'user_id', 'approval_version'], 'collection_approvals_unique');
                });
            }
            return;
        }

        Schema::create('collection_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('approval_version')->default(1);
            $table->timestamps();
            $table->unique(['collection_id', 'user_id', 'approval_version'], 'collection_approvals_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_approvals');
    }
};
