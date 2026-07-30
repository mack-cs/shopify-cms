<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table): void {
            $table->foreignId('target_approver_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(
                ['status', 'target_approver_id'],
                'deletion_requests_target_approver_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table): void {
            $table->dropIndex('deletion_requests_target_approver_lookup');
            $table->dropConstrainedForeignId('target_approver_id');
        });
    }
};
