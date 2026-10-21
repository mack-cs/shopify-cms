<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_inventory_level_events', function (Blueprint $table): void {
            $table->id();
            $table->string('webhook_id', 191)->unique();
            $table->string('inventory_item_id')->index();
            $table->string('location_id')->nullable();
            $table->integer('available')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->json('payload');
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_inventory_level_events');
    }
};
