<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('new_product_drafts', 'bundle_component_quantities')) {
            Schema::table('new_product_drafts', function (Blueprint $table): void {
                $table->json('bundle_component_quantities')->nullable()->after('bundle_product_ids');
            });
        }

        if (! Schema::hasTable('shopify_fulfillments')) {
            Schema::create('shopify_fulfillments', function (Blueprint $table): void {
                $table->id();
                $table->string('shopify_fulfillment_id', 128)->unique();
                $table->string('shopify_order_id', 128)->index();
                $table->foreignId('shopify_order_db_id')->nullable();
                $table->foreign('shopify_order_db_id', 'sf_order_db_fk')
                    ->references('id')->on('shopify_orders')->nullOnDelete();
                $table->string('shopify_location_id', 128)->nullable()->index();
                $table->string('shopify_status', 32)->nullable()->index();
                $table->string('webhook_id', 128)->nullable()->index();
                $table->string('processing_status', 32)->default('pending')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->json('payload');
                $table->text('error_message')->nullable();
                $table->timestamp('fulfilled_at_shopify')->nullable();
                $table->timestamp('processing_started_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('shopify_stack_component_deductions')) {
            $this->createDeductionTable();

            return;
        }

        $this->repairDeductionIndexes();
        $this->repairDeductionForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_stack_component_deductions');
        Schema::dropIfExists('shopify_fulfillments');

        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->dropColumn('bundle_component_quantities');
        });
    }

    private function createDeductionTable(): void
    {
        Schema::create('shopify_stack_component_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shopify_fulfillment_id');
            $table->foreign('shopify_fulfillment_id', 'sscd_fulfillment_fk')
                ->references('id')->on('shopify_fulfillments')->cascadeOnDelete();
            $table->string('shopify_fulfillment_line_item_id', 128);
            $table->string('shopify_order_id', 128)->index('sscd_order_idx');
            $table->string('shopify_stack_variant_id', 128)->index('sscd_stack_variant_idx');
            $table->foreignId('stack_product_id')->nullable();
            $table->foreign('stack_product_id', 'sscd_stack_product_fk')
                ->references('id')->on('products')->nullOnDelete();
            $table->foreignId('stack_variant_id')->nullable();
            $table->foreign('stack_variant_id', 'sscd_stack_variant_fk')
                ->references('id')->on('variants')->nullOnDelete();
            $table->unsignedInteger('stack_quantity_fulfilled');
            $table->unsignedBigInteger('configured_component_product_id');
            $table->foreignId('component_product_id')->nullable();
            $table->foreign('component_product_id', 'sscd_component_product_fk')
                ->references('id')->on('products')->nullOnDelete();
            $table->foreignId('component_variant_id')->nullable();
            $table->foreign('component_variant_id', 'sscd_component_variant_fk')
                ->references('id')->on('variants')->nullOnDelete();
            $table->string('shopify_component_variant_id', 128)->nullable();
            $table->string('shopify_inventory_item_id', 128)->nullable()->index('sscd_inventory_item_idx');
            $table->string('shopify_location_id', 128)->nullable()->index('sscd_location_idx');
            $table->unsignedInteger('component_quantity_per_stack');
            $table->unsignedInteger('quantity_deducted');
            $table->uuid('idempotency_key')->unique('sscd_idempotency_unique');
            $table->string('status', 32)->default('pending')->index('sscd_status_idx');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('shopify_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shopify_fulfillment_id', 'shopify_fulfillment_line_item_id', 'configured_component_product_id'],
                'stack_component_deduction_once'
            );
        });
    }

    private function repairDeductionIndexes(): void
    {
        $indexes = [
            ['columns' => ['shopify_order_id'], 'name' => 'sscd_order_idx', 'unique' => false],
            ['columns' => ['shopify_stack_variant_id'], 'name' => 'sscd_stack_variant_idx', 'unique' => false],
            ['columns' => ['shopify_inventory_item_id'], 'name' => 'sscd_inventory_item_idx', 'unique' => false],
            ['columns' => ['shopify_location_id'], 'name' => 'sscd_location_idx', 'unique' => false],
            ['columns' => ['status'], 'name' => 'sscd_status_idx', 'unique' => false],
            ['columns' => ['idempotency_key'], 'name' => 'sscd_idempotency_unique', 'unique' => true],
            [
                'columns' => ['shopify_fulfillment_id', 'shopify_fulfillment_line_item_id', 'configured_component_product_id'],
                'name' => 'stack_component_deduction_once',
                'unique' => true,
            ],
        ];

        foreach ($indexes as $index) {
            $type = $index['unique'] ? 'unique' : null;
            if (Schema::hasIndex('shopify_stack_component_deductions', $index['columns'], $type)) {
                continue;
            }

            Schema::table('shopify_stack_component_deductions', function (Blueprint $table) use ($index): void {
                $index['unique']
                    ? $table->unique($index['columns'], $index['name'])
                    : $table->index($index['columns'], $index['name']);
            });
        }
    }

    private function repairDeductionForeignKeys(): void
    {
        $foreignKeys = [
            ['column' => 'shopify_fulfillment_id', 'table' => 'shopify_fulfillments', 'name' => 'sscd_fulfillment_fk', 'delete' => 'cascade'],
            ['column' => 'stack_product_id', 'table' => 'products', 'name' => 'sscd_stack_product_fk', 'delete' => 'set null'],
            ['column' => 'stack_variant_id', 'table' => 'variants', 'name' => 'sscd_stack_variant_fk', 'delete' => 'set null'],
            ['column' => 'component_product_id', 'table' => 'products', 'name' => 'sscd_component_product_fk', 'delete' => 'set null'],
            ['column' => 'component_variant_id', 'table' => 'variants', 'name' => 'sscd_component_variant_fk', 'delete' => 'set null'],
        ];

        foreach ($foreignKeys as $foreignKey) {
            if ($this->hasForeignKey('shopify_stack_component_deductions', $foreignKey['column'])) {
                continue;
            }

            Schema::table('shopify_stack_component_deductions', function (Blueprint $table) use ($foreignKey): void {
                $constraint = $table->foreign($foreignKey['column'], $foreignKey['name'])
                    ->references('id')->on($foreignKey['table']);

                $foreignKey['delete'] === 'cascade'
                    ? $constraint->cascadeOnDelete()
                    : $constraint->nullOnDelete();
            });
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
};
