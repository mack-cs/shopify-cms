<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_partial_approval_requests', function (Blueprint $table) {
            $table->foreignId('target_approver_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['status', 'target_approver_id'], 'product_partial_approval_target_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('product_partial_approval_requests', function (Blueprint $table) {
            $table->dropIndex('product_partial_approval_target_lookup');
            $table->dropConstrainedForeignId('target_approver_id');
        });
    }
};
