<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            if (!Schema::hasColumn('new_product_drafts', 'editing_user_id')) {
                $table->foreignId('editing_user_id')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('new_product_drafts', 'editing_started_at')) {
                $table->timestamp('editing_started_at')
                    ->nullable()
                    ->after('editing_user_id');
            }

            if (!Schema::hasColumn('new_product_drafts', 'editing_expires_at')) {
                $table->timestamp('editing_expires_at')
                    ->nullable()
                    ->after('editing_started_at')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('new_product_drafts', 'editing_expires_at')) {
                $table->dropIndex(['editing_expires_at']);
                $table->dropColumn('editing_expires_at');
            }

            if (Schema::hasColumn('new_product_drafts', 'editing_started_at')) {
                $table->dropColumn('editing_started_at');
            }

            if (Schema::hasColumn('new_product_drafts', 'editing_user_id')) {
                $table->dropConstrainedForeignId('editing_user_id');
            }
        });
    }
};
