<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
            $table->foreignId('new_product_draft_id')->nullable()->after('variant_id');
            $table->foreign('new_product_draft_id', 'proc_sup_lines_draft_fk')
                ->references('id')
                ->on('new_product_drafts')
                ->nullOnDelete();
        });

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('procurement_supplier_order_lines', function (Blueprint $table) use ($isSqlite): void {
            if (! $isSqlite) {
                $table->dropForeign('proc_sup_lines_variant_fk');
                $table->dropUnique('proc_sup_lines_order_variant_uq');
                $table->dropIndex('proc_sup_lines_variant_status_ix');
            }
            $table->foreignId('variant_id')->nullable()->change();
            if (! $isSqlite) {
                $table->foreign('variant_id', 'proc_sup_lines_variant_fk')
                    ->references('id')
                    ->on('variants')
                    ->restrictOnDelete();
                $table->unique(['supplier_order_id', 'variant_id'], 'proc_sup_lines_order_variant_uq');
                $table->index(['variant_id', 'status'], 'proc_sup_lines_variant_status_ix');
            }
            $table->unique(['supplier_order_id', 'new_product_draft_id'], 'proc_sup_lines_order_draft_uq');
            $table->index(['new_product_draft_id', 'status'], 'proc_sup_lines_draft_status_ix');
        });
    }

    public function down(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('procurement_supplier_order_lines', function (Blueprint $table) use ($isSqlite): void {
            if (! $isSqlite) {
                $table->dropForeign('proc_sup_lines_draft_fk');
            }
            $table->dropUnique('proc_sup_lines_order_draft_uq');
            $table->dropIndex('proc_sup_lines_draft_status_ix');
            $table->dropColumn('new_product_draft_id');
        });

        Schema::table('procurement_supplier_order_lines', function (Blueprint $table) use ($isSqlite): void {
            if (! $isSqlite) {
                $table->dropForeign('proc_sup_lines_variant_fk');
                $table->dropUnique('proc_sup_lines_order_variant_uq');
                $table->dropIndex('proc_sup_lines_variant_status_ix');
            }
            $table->foreignId('variant_id')->nullable(false)->change();
            if (! $isSqlite) {
                $table->foreign('variant_id', 'proc_sup_lines_variant_fk')
                    ->references('id')
                    ->on('variants')
                    ->restrictOnDelete();
                $table->unique(['supplier_order_id', 'variant_id'], 'proc_sup_lines_order_variant_uq');
                $table->index(['variant_id', 'status'], 'proc_sup_lines_variant_status_ix');
            }
        });
    }
};
