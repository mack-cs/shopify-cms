<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_supplier_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('procurement_supplier_receipts', 'out_of_sequence_confirmed_by')) {
                $table->foreignId('out_of_sequence_confirmed_by')->nullable()->after('created_by');
                $table->foreign('out_of_sequence_confirmed_by', 'proc_sup_receipts_oos_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('procurement_supplier_receipts', 'out_of_sequence_confirmed_at')) {
                $table->timestamp('out_of_sequence_confirmed_at')->nullable()->after('out_of_sequence_confirmed_by');
            }
            if (! Schema::hasColumn('procurement_supplier_receipts', 'out_of_sequence_reason')) {
                $table->text('out_of_sequence_reason')->nullable()->after('out_of_sequence_confirmed_at');
            }
            if (! Schema::hasColumn('procurement_supplier_receipts', 'out_of_sequence_audit')) {
                $table->json('out_of_sequence_audit')->nullable()->after('out_of_sequence_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('procurement_supplier_receipts', function (Blueprint $table): void {
            if (Schema::hasColumn('procurement_supplier_receipts', 'out_of_sequence_confirmed_by')) {
                $table->dropForeign('proc_sup_receipts_oos_user_fk');
            }

            foreach ([
                'out_of_sequence_audit',
                'out_of_sequence_reason',
                'out_of_sequence_confirmed_at',
                'out_of_sequence_confirmed_by',
            ] as $column) {
                if (Schema::hasColumn('procurement_supplier_receipts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
