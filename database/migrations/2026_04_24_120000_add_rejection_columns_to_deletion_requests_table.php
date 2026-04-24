<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table) {
            $table->foreignId('rejected_by')->nullable()->after('completed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('completed_at');
            $table->text('rejection_reason')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });
    }
};
