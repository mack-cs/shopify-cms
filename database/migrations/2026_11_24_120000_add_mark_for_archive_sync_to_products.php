<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'mark_for_archive')) {
                $table->boolean('mark_for_archive')->nullable()->after('error_fields');
            }
            if (! Schema::hasColumn('products', 'mark_for_archive_sync_status')) {
                $table->string('mark_for_archive_sync_status', 32)->nullable()->index()->after('mark_for_archive');
            }
            if (! Schema::hasColumn('products', 'mark_for_archive_synced_value')) {
                $table->boolean('mark_for_archive_synced_value')->nullable()->after('mark_for_archive_sync_status');
            }
            if (! Schema::hasColumn('products', 'mark_for_archive_synced_at')) {
                $table->timestamp('mark_for_archive_synced_at')->nullable()->after('mark_for_archive_synced_value');
            }
            if (! Schema::hasColumn('products', 'mark_for_archive_sync_error')) {
                $table->text('mark_for_archive_sync_error')->nullable()->after('mark_for_archive_synced_at');
            }
            if (! Schema::hasColumn('products', 'mark_for_archive_desired_version')) {
                $table->unsignedBigInteger('mark_for_archive_desired_version')->default(0)->after('mark_for_archive_sync_error');
            }
            if (! Schema::hasColumn('products', 'mark_for_archive_source_changed_at')) {
                $table->timestamp('mark_for_archive_source_changed_at')->nullable()->after('mark_for_archive_desired_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $columns = [
                'mark_for_archive',
                'mark_for_archive_sync_status',
                'mark_for_archive_synced_value',
                'mark_for_archive_synced_at',
                'mark_for_archive_sync_error',
                'mark_for_archive_desired_version',
                'mark_for_archive_source_changed_at',
            ];
            $existing = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn('products', $column)
            ));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
