<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('image_assets')) {
            Schema::create('image_assets', function (Blueprint $table) {
                $table->id();
                $table->string('sha256', 64);
                $table->string('storage_disk')->default('public');
                // This path is generated internally and stays far below 768 chars.
                // Keep the unique index MySQL-safe under utf8mb4.
                $table->string('storage_path', 768);
                $table->string('original_filename')->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->string('mime_type')->nullable();
                $table->string('extension', 32)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->timestamp('downloaded_at')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamp('missing_at')->nullable();
                $table->string('status')->default('available');
                $table->timestamps();
            });
        }

        $this->ensureStoragePathLength();
        $this->ensureUniqueIndex('image_assets', 'image_assets_sha256_unique', ['sha256']);
        $this->ensureUniqueIndex('image_assets', 'image_assets_storage_path_unique', ['storage_path']);
        $this->ensureIndex('image_assets', 'image_assets_downloaded_at_index', ['downloaded_at']);
        $this->ensureIndex('image_assets', 'image_assets_status_index', ['status']);
    }

    public function down(): void
    {
        Schema::dropIfExists('image_assets');
    }

    private function ensureStoragePathLength(): void
    {
        $column = DB::selectOne("
            SELECT CHARACTER_MAXIMUM_LENGTH AS max_length
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'image_assets'
              AND COLUMN_NAME = 'storage_path'
            LIMIT 1
        ");

        $maxLength = (int) ($column->max_length ?? 0);
        if ($maxLength > 0 && $maxLength <= 768) {
            return;
        }

        DB::statement("ALTER TABLE `image_assets` MODIFY `storage_path` VARCHAR(768) NOT NULL");
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        $exists = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$indexName]);
        if (!empty($exists)) {
            return;
        }

        $cols = implode(',', array_map(fn ($col) => "`{$col}`", $columns));
        DB::statement("CREATE INDEX `{$indexName}` ON `{$table}` ({$cols})");
    }

    private function ensureUniqueIndex(string $table, string $indexName, array $columns): void
    {
        $exists = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$indexName]);
        if (!empty($exists)) {
            return;
        }

        $cols = implode(',', array_map(fn ($col) => "`{$col}`", $columns));
        DB::statement("CREATE UNIQUE INDEX `{$indexName}` ON `{$table}` ({$cols})");
    }
};
