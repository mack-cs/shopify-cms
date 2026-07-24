<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_orders', function (Blueprint $table): void {
            $table->json('payment_gateway_names')->nullable()->after('source_name');
        });

        Schema::table('shopify_sync_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('transactions_processed')->default(0)->after('discounts_processed');
            $table->unsignedBigInteger('refund_line_items_processed')->default(0)->after('refunds_processed');
        });

        Schema::create('shopify_order_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_transaction_id', 128)->unique();
            $table->string('shopify_order_id', 128)->index();
            $table->foreignId('shopify_order_db_id')->nullable()->constrained('shopify_orders')->nullOnDelete();
            $table->string('parent_transaction_id', 128)->nullable()->index();
            $table->string('kind', 32)->index();
            $table->string('status', 32)->index();
            $table->string('gateway')->nullable()->index();
            $table->string('formatted_gateway')->nullable()->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->timestamp('created_at_shopify')->nullable()->index();
            $table->timestamp('processed_at_shopify')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->boolean('manual_payment_gateway')->nullable();
            $table->boolean('is_test')->default(false);
            $table->foreignId('latest_sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'kind', 'status'], 'shopify_transactions_report_index');
            $table->index('shopify_order_db_id');
        });

        Schema::create('shopify_refund_line_items', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_refund_line_item_id', 128)->unique();
            $table->string('shopify_refund_id', 128)->index();
            $table->foreignId('shopify_refund_db_id')->nullable()->constrained('shopify_refunds')->nullOnDelete();
            $table->string('shopify_order_id', 128)->index();
            $table->foreignId('shopify_order_db_id')->nullable()->constrained('shopify_orders')->nullOnDelete();
            $table->string('shopify_line_item_id', 128)->index();
            $table->foreignId('shopify_order_item_db_id')->nullable()->constrained('shopify_order_items')->nullOnDelete();
            $table->integer('quantity')->default(0);
            $table->decimal('subtotal_amount', 12, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->boolean('restocked')->nullable();
            $table->string('restock_type', 32)->nullable();
            $table->string('shopify_location_id', 128)->nullable()->index();
            $table->string('location_name')->nullable();
            $table->foreignId('latest_sync_run_id')->nullable()->constrained('shopify_sync_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('shopify_refund_db_id');
            $table->index('shopify_order_db_id');
            $table->index('shopify_order_item_db_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_refund_line_items');
        Schema::dropIfExists('shopify_order_transactions');

        Schema::table('shopify_sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['transactions_processed', 'refund_line_items_processed']);
        });

        Schema::table('shopify_orders', function (Blueprint $table): void {
            $table->dropColumn('payment_gateway_names');
        });
    }
};
