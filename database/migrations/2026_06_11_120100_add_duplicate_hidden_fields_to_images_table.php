<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (!Schema::hasColumn('images', 'is_duplicate_hidden')) {
                $table->boolean('is_duplicate_hidden')
                    ->default(false)
                    ->after('local_dirty')
                    ->index();
            }

            if (!Schema::hasColumn('images', 'duplicate_of_image_id')) {
                $table->foreignId('duplicate_of_image_id')
                    ->nullable()
                    ->after('is_duplicate_hidden')
                    ->constrained('images')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('images', 'duplicate_hidden_at')) {
                $table->timestamp('duplicate_hidden_at')
                    ->nullable()
                    ->after('duplicate_of_image_id');
            }

            if (!Schema::hasColumn('images', 'duplicate_hidden_by')) {
                $table->foreignId('duplicate_hidden_by')
                    ->nullable()
                    ->after('duplicate_hidden_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('images', 'duplicate_hidden_reason')) {
                $table->string('duplicate_hidden_reason', 255)
                    ->nullable()
                    ->after('duplicate_hidden_by');
            }

            if (!Schema::hasColumn('images', 'duplicate_restored_at')) {
                $table->timestamp('duplicate_restored_at')
                    ->nullable()
                    ->after('duplicate_hidden_reason');
            }

            if (!Schema::hasColumn('images', 'duplicate_restored_by')) {
                $table->foreignId('duplicate_restored_by')
                    ->nullable()
                    ->after('duplicate_restored_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table): void {
            if (Schema::hasColumn('images', 'duplicate_restored_by')) {
                $table->dropConstrainedForeignId('duplicate_restored_by');
            }

            if (Schema::hasColumn('images', 'duplicate_restored_at')) {
                $table->dropColumn('duplicate_restored_at');
            }

            if (Schema::hasColumn('images', 'duplicate_hidden_reason')) {
                $table->dropColumn('duplicate_hidden_reason');
            }

            if (Schema::hasColumn('images', 'duplicate_hidden_by')) {
                $table->dropConstrainedForeignId('duplicate_hidden_by');
            }

            if (Schema::hasColumn('images', 'duplicate_hidden_at')) {
                $table->dropColumn('duplicate_hidden_at');
            }

            if (Schema::hasColumn('images', 'duplicate_of_image_id')) {
                $table->dropConstrainedForeignId('duplicate_of_image_id');
            }

            if (Schema::hasColumn('images', 'is_duplicate_hidden')) {
                $table->dropIndex(['is_duplicate_hidden']);
                $table->dropColumn('is_duplicate_hidden');
            }
        });
    }
};
