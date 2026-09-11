<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('procurement_supplier_order_lines', 'new_product_draft_id')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->foreignId('new_product_draft_id')->nullable()->after('variant_id');
            });
        }

        if (! $this->foreignKeyExists('procurement_supplier_order_lines', 'proc_sup_lines_draft_fk')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->foreign('new_product_draft_id', 'proc_sup_lines_draft_fk')
                    ->references('id')
                    ->on('new_product_drafts')
                    ->nullOnDelete();
            });
        }

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite && $this->foreignKeyExists('procurement_supplier_order_lines', 'proc_sup_lines_variant_fk')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->dropForeign('proc_sup_lines_variant_fk');
            });
        }

        Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
            $table->foreignId('variant_id')->nullable()->change();
        });

        if (! $isSqlite && ! $this->foreignKeyExists('procurement_supplier_order_lines', 'proc_sup_lines_variant_fk')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->foreign('variant_id', 'proc_sup_lines_variant_fk')
                    ->references('id')
                    ->on('variants')
                    ->restrictOnDelete();
            });
        }

        if (! $this->indexExists('procurement_supplier_order_lines', 'proc_sup_lines_order_draft_uq')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->unique(['supplier_order_id', 'new_product_draft_id'], 'proc_sup_lines_order_draft_uq');
            });
        }

        if (! $this->indexExists('procurement_supplier_order_lines', 'proc_sup_lines_draft_status_ix')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->index(['new_product_draft_id', 'status'], 'proc_sup_lines_draft_status_ix');
            });
        }
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if (! $isSqlite && $this->foreignKeyExists('procurement_supplier_order_lines', 'proc_sup_lines_draft_fk')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->dropForeign('proc_sup_lines_draft_fk');
            });
        }

        if ($this->indexExists('procurement_supplier_order_lines', 'proc_sup_lines_order_draft_uq')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->dropUnique('proc_sup_lines_order_draft_uq');
            });
        }

        if ($this->indexExists('procurement_supplier_order_lines', 'proc_sup_lines_draft_status_ix')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->dropIndex('proc_sup_lines_draft_status_ix');
            });
        }

        if (Schema::hasColumn('procurement_supplier_order_lines', 'new_product_draft_id')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->dropColumn('new_product_draft_id');
            });
        }

        if (! $isSqlite && $this->foreignKeyExists('procurement_supplier_order_lines', 'proc_sup_lines_variant_fk')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->dropForeign('proc_sup_lines_variant_fk');
            });
        }

        Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
            $table->foreignId('variant_id')->nullable(false)->change();
        });

        if (! $isSqlite && ! $this->foreignKeyExists('procurement_supplier_order_lines', 'proc_sup_lines_variant_fk')) {
            Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
                $table->foreign('variant_id', 'proc_sup_lines_variant_fk')
                    ->references('id')
                    ->on('variants')
                    ->restrictOnDelete();
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        return false;
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $foreignKey)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
