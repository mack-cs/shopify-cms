<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_supplier_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->nullable()->unique();
            $table->string('legacy_key')->nullable()->unique();
            $table->string('source', 32)->default('cms');
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'proc_sup_orders_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('procurement_supplier_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_order_id');
            $table->foreign('supplier_order_id', 'proc_sup_lines_order_fk')->references('id')->on('procurement_supplier_orders')->cascadeOnDelete();
            $table->foreignId('variant_id');
            $table->foreign('variant_id', 'proc_sup_lines_variant_fk')->references('id')->on('variants')->restrictOnDelete();
            $table->string('sku');
            $table->unsignedInteger('quantity_ordered');
            $table->date('eta_date')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('source', 32)->default('cms');
            $table->unsignedTinyInteger('legacy_phase')->nullable();
            $table->timestamps();
            $table->unique(['supplier_order_id', 'variant_id'], 'proc_sup_lines_order_variant_uq');
            $table->index(['variant_id', 'status'], 'proc_sup_lines_variant_status_ix');
        });

        Schema::create('procurement_supplier_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 16);
            $table->string('original_filename')->nullable();
            $table->string('file_hash', 64);
            $table->string('status', 24)->default('previewed');
            $table->json('preview_rows');
            $table->json('errors')->nullable();
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'proc_sup_batches_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'file_hash'], 'proc_sup_batches_type_hash_uq');
        });

        Schema::create('procurement_supplier_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_order_line_id');
            $table->foreign('supplier_order_line_id', 'proc_sup_receipts_line_fk')->references('id')->on('procurement_supplier_order_lines')->cascadeOnDelete();
            $table->unsignedInteger('quantity_received');
            $table->string('idempotency_key', 128)->unique();
            $table->string('source', 32)->default('cms');
            $table->foreignId('import_batch_id')->nullable();
            $table->foreign('import_batch_id', 'proc_sup_receipts_batch_fk')->references('id')->on('procurement_supplier_import_batches')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('post_process_status', 24)->default('pending')->index();
            $table->string('shopify_reference_uri')->unique();
            $table->timestamp('shopify_adjustment_started_at')->nullable();
            $table->timestamp('shopify_adjusted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'proc_sup_receipts_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->adoptLegacyIncomingStock();
    }

    private function adoptLegacyIncomingStock(): void
    {
        DB::table('procurement_incoming_stocks')->orderBy('id')->chunkById(200, function ($stocks): void {
            foreach ($stocks as $stock) {
                foreach ([1, 2, 3] as $phase) {
                    $quantity = (int) ($stock->{"quantity_on_order_phase_{$phase}"} ?? 0);
                    if ($quantity <= 0) {
                        continue;
                    }
                    $orderNumber = trim((string) ($stock->{"order_id_phase_{$phase}"} ?? '')) ?: null;
                    $legacyKey = $orderNumber === null ? "stock:{$stock->id}:phase:{$phase}" : null;
                    $order = DB::table('procurement_supplier_orders')
                        ->where($orderNumber !== null ? 'order_number' : 'legacy_key', $orderNumber ?? $legacyKey)->first();
                    if (! $order) {
                        $orderId = DB::table('procurement_supplier_orders')->insertGetId([
                            'uuid' => (string) Str::uuid(), 'order_number' => $orderNumber,
                            'legacy_key' => $legacyKey, 'source' => 'legacy_sheet',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    } else {
                        $orderId = $order->id;
                    }
                    $existing = DB::table('procurement_supplier_order_lines')
                        ->where('supplier_order_id', $orderId)->where('variant_id', $stock->variant_id)->first();
                    if ($existing) {
                        DB::table('procurement_supplier_order_lines')->where('id', $existing->id)->update([
                            'quantity_ordered' => (int) $existing->quantity_ordered + $quantity,
                            'eta_date' => $existing->eta_date ?: ($stock->{"eta_date_phase_{$phase}"} ?? null),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('procurement_supplier_order_lines')->insert([
                            'supplier_order_id' => $orderId, 'variant_id' => $stock->variant_id,
                            'sku' => $stock->sku, 'quantity_ordered' => $quantity,
                            'eta_date' => $stock->{"eta_date_phase_{$phase}"} ?? null,
                            'status' => 'open', 'source' => 'legacy_sheet', 'legacy_phase' => $phase,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_supplier_receipts');
        Schema::dropIfExists('procurement_supplier_import_batches');
        Schema::dropIfExists('procurement_supplier_order_lines');
        Schema::dropIfExists('procurement_supplier_orders');
    }
};
