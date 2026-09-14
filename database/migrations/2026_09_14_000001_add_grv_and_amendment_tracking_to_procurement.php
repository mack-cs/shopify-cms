<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_supplier_order_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_order_id')->constrained('procurement_supplier_orders')->cascadeOnDelete();
            $table->foreignId('supplier_order_line_id')->nullable()->constrained('procurement_supplier_order_lines')->nullOnDelete();
            $table->foreignId('amended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_grv_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });
        DB::table('procurement_grv_sequences')->insert([
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('procurement_supplier_receipts', function (Blueprint $table): void {
            $table->string('grv_number', 32)->nullable()->unique()->after('uuid');
            $table->timestamp('received_at')->nullable()->after('quantity_received');
            $table->integer('inventory_before')->nullable()->after('received_at');
            $table->integer('inventory_after')->nullable()->after('inventory_before');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_supplier_receipts', function (Blueprint $table): void {
            $table->dropColumn(['grv_number', 'received_at', 'inventory_before', 'inventory_after']);
        });

        Schema::dropIfExists('procurement_supplier_order_amendments');
        Schema::dropIfExists('procurement_grv_sequences');
    }
};
