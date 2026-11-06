<?php

use App\Models\Product;
use App\Services\EngravedBeads\EngravedBeadConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedTinyInteger('engraved_bead_quantity')->nullable()->index();
            $table->string('engraved_bead_configuration', 16)->nullable()->index();
        });

        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->unsignedInteger('restocked_quantity')->default(0)->after('released_quantity');
        });

        Schema::create('engraved_bead_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_order_id', 128);
            $table->string('shopify_order_line_item_id', 128);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('configuration', 32)->index();
            $table->unsignedTinyInteger('beads')->nullable();
            $table->unsignedInteger('additional_per_unit')->default(0);
            $table->unsignedInteger('ordered_quantity')->default(0);
            $table->string('stock_pool', 32)->nullable();
            $table->string('shopify_inventory_item_id', 128)->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained('shopify_stack_inventory_reservations')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['shopify_order_id', 'shopify_order_line_item_id'], 'ebol_order_line_unique');
        });

        Schema::create('product_launch_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('queued')->index();
            $table->json('publication_ids');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_launch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_launch_run_id')->constrained('product_launch_runs')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('validation', 16)->default('blocked')->index();
            $table->string('outcome', 16)->default('pending')->index();
            $table->string('current_status')->nullable();
            $table->string('proposed_status')->nullable();
            $table->string('inventory_tracking')->nullable();
            $table->integer('current_inventory')->nullable();
            $table->integer('proposed_inventory')->nullable();
            $table->boolean('inventory_write_required')->default(false);
            $table->timestamp('inventory_written_at')->nullable();
            $table->string('inventory_idempotency_key', 64)->nullable();
            $table->json('publication_ids')->nullable();
            $table->json('confirmed_publication_ids')->nullable();
            $table->timestamp('status_confirmed_at')->nullable();
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->unique(['product_launch_run_id', 'product_id'], 'pli_run_product_unique');
        });

        if (Schema::hasTable('products')) {
            Product::query()->whereNotNull('tags')->orderBy('id')->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    if (! $product instanceof Product) {
                        continue;
                    }
                    $configuration = EngravedBeadConfiguration::fromTags($product->tags);
                    if (! $configuration->participating()) {
                        continue;
                    }
                    $product->forceFill([
                        'engraved_bead_quantity' => $configuration->quantity(),
                        'engraved_bead_configuration' => $configuration->state(),
                    ])->saveQuietly();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_launch_items');
        Schema::dropIfExists('product_launch_runs');
        Schema::dropIfExists('engraved_bead_order_lines');
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->dropColumn('restocked_quantity');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['engraved_bead_quantity', 'engraved_bead_configuration']);
        });
    }
};
