<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIndex('approvals', 'approvals_product_id_index', ['product_id']);
        $this->ensureIndex('approvals', 'approvals_user_id_index', ['user_id']);

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropUnique('approvals_product_id_user_id_unique');
            $table->unique(['product_id', 'user_id', 'approval_version'], 'approvals_product_user_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropUnique('approvals_product_user_version_unique');
            $table->unique(['product_id', 'user_id'], 'approvals_product_id_user_id_unique');
        });
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        $exists = DB::select('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?', [$indexName]);
        if (!empty($exists)) {
            return;
        }

        $cols = implode(',', array_map(fn ($col) => "`{$col}`", $columns));
        DB::statement("CREATE INDEX `{$indexName}` ON `{$table}` ({$cols})");
    }
};
