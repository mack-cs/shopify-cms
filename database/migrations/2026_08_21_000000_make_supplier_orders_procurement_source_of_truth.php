<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_incoming_stocks', function (Blueprint $table): void {
            $table->unsignedInteger('quantity_to_order')->default(0)->after('ignore');
            $table->unsignedInteger('number_of_wip_orders')->default(0)->after('total_confirmed_quantity_on_order');
        });
        Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('updated_by')->nullable()->after('source');
            $table->foreign('updated_by', 'proc_sup_lines_updated_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('procurement_incoming_stocks')->orderBy('id')->chunkById(200, function ($stocks): void {
            foreach ($stocks as $stock) {
                $lines = DB::table('procurement_supplier_order_lines as lines')
                    ->leftJoin('procurement_supplier_receipts as receipts', function ($join): void {
                        $join->on('receipts.supplier_order_line_id', '=', 'lines.id')
                            ->where('receipts.status', '=', 'succeeded');
                    })
                    ->where('lines.variant_id', $stock->variant_id)
                    ->where('lines.status', 'open')
                    ->groupBy('lines.id', 'lines.supplier_order_id', 'lines.quantity_ordered')
                    ->selectRaw('lines.supplier_order_id, lines.quantity_ordered, COALESCE(SUM(receipts.quantity_received), 0) as quantity_received')
                    ->get()
                    ->filter(fn ($line): bool => (int) $line->quantity_ordered > (int) $line->quantity_received);

                $outstanding = $lines->sum(fn ($line): int => (int) $line->quantity_ordered - (int) $line->quantity_received);
                DB::table('procurement_incoming_stocks')->where('id', $stock->id)->update([
                    'total_quantity_on_order' => $outstanding,
                    'total_confirmed_quantity_on_order' => $outstanding,
                    'number_of_wip_orders' => $lines->pluck('supplier_order_id')->unique()->count(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('procurement_supplier_order_lines', function (Blueprint $table): void {
            $table->dropForeign('proc_sup_lines_updated_user_fk');
            $table->dropColumn(['completed_at', 'cancelled_at', 'updated_by']);
        });
        Schema::table('procurement_incoming_stocks', function (Blueprint $table): void {
            $table->dropColumn(['quantity_to_order', 'number_of_wip_orders']);
        });
    }
};
