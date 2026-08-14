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
            $table->boolean('ignore')->default(false)->after('sku');
            foreach ([1, 2, 3] as $phase) {
                $table->string("order_id_phase_{$phase}")->nullable()->after("quantity_on_order_phase_{$phase}");
                $table->date("eta_date_phase_{$phase}")->nullable()->after("order_id_phase_{$phase}");
                $table->unsignedInteger("confirmed_quantity_on_order_phase_{$phase}")->default(0)
                    ->after("eta_date_phase_{$phase}");
            }
            $table->unsignedInteger('total_confirmed_quantity_on_order')->default(0)
                ->after('total_quantity_on_order');
        });

        Schema::table('procurement_prediction_inputs', function (Blueprint $table): void {
            $table->boolean('ignore')->default(false)->after('sku');
            foreach ([1, 2, 3] as $phase) {
                $table->string("order_id_phase_{$phase}")->nullable()->after("quantity_on_order_phase_{$phase}");
                $table->date("eta_date_phase_{$phase}")->nullable()->after("order_id_phase_{$phase}");
                $table->unsignedInteger("confirmed_quantity_on_order_phase_{$phase}")->default(0)
                    ->after("eta_date_phase_{$phase}");
            }
            $table->unsignedInteger('total_confirmed_quantity_on_order')->default(0)
                ->after('total_quantity_on_order');
            $table->boolean('procurement_actioned')->default(false)
                ->after('total_confirmed_quantity_on_order');
        });

        Schema::table('procurement_predictions', function (Blueprint $table): void {
            $table->boolean('ignore')->default(false)->after('current_inventory');
            $table->decimal('sale_percentage', 7, 2)->nullable()->after('currently_on_sale');
            foreach ([1, 2, 3] as $phase) {
                $table->string("order_id_phase_{$phase}")->nullable()->after("quantity_on_order_phase_{$phase}");
                $table->date("eta_date_phase_{$phase}")->nullable()->after("order_id_phase_{$phase}");
                $table->unsignedInteger("confirmed_quantity_on_order_phase_{$phase}")->default(0)
                    ->after("eta_date_phase_{$phase}");
            }
            $table->unsignedInteger('total_confirmed_quantity_on_order')->default(0)
                ->after('total_quantity_on_order');
            $table->boolean('procurement_actioned')->default(false)
                ->after('total_confirmed_quantity_on_order');
        });

        // Previous runs may have counted raw quantities that were not backed by
        // both an order ID and ETA. Require a fresh run before publishing again.
        DB::table('procurement_incoming_stocks')->update([
            'last_prediction_run_id' => null,
            'input_changed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('procurement_predictions', function (Blueprint $table): void {
            $table->dropColumn($this->predictionColumns());
        });
        Schema::table('procurement_prediction_inputs', function (Blueprint $table): void {
            $table->dropColumn($this->inputColumns());
        });
        Schema::table('procurement_incoming_stocks', function (Blueprint $table): void {
            $table->dropColumn($this->stockColumns());
        });
    }

    /** @return array<int,string> */
    private function stockColumns(): array
    {
        return array_merge(['ignore', 'total_confirmed_quantity_on_order'], $this->phaseColumns());
    }

    /** @return array<int,string> */
    private function inputColumns(): array
    {
        return array_merge($this->stockColumns(), ['procurement_actioned']);
    }

    /** @return array<int,string> */
    private function predictionColumns(): array
    {
        return array_merge($this->inputColumns(), ['sale_percentage']);
    }

    /** @return array<int,string> */
    private function phaseColumns(): array
    {
        $columns = [];
        foreach ([1, 2, 3] as $phase) {
            $columns[] = "order_id_phase_{$phase}";
            $columns[] = "eta_date_phase_{$phase}";
            $columns[] = "confirmed_quantity_on_order_phase_{$phase}";
        }

        return $columns;
    }
};
