<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_supplier_receipts', fn (Blueprint $table) => $table->json('receiving_authorization')->nullable());
    }

    public function down(): void
    {
        Schema::table('procurement_supplier_receipts', fn (Blueprint $table) => $table->dropColumn('receiving_authorization'));
    }
};
