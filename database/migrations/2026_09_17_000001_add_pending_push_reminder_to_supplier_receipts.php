<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_supplier_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('procurement_supplier_receipts', 'pending_push_reminded_at')) {
                $table->timestamp('pending_push_reminded_at')->nullable()->after('out_of_sequence_audit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('procurement_supplier_receipts', function (Blueprint $table): void {
            if (Schema::hasColumn('procurement_supplier_receipts', 'pending_push_reminded_at')) {
                $table->dropColumn('pending_push_reminded_at');
            }
        });
    }
};
