<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engraved_bead_families', function (Blueprint $table): void {
            $table->unsignedInteger('automation_held_quantity')->default(0);
            $table->timestamp('recalculate_at')->nullable()->index();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->text('sync_error')->nullable();
        });
        Schema::table('engraved_bead_family_members', function (Blueprint $table): void {
            $table->boolean('manual_disabled')->default(false);
        });
        Schema::create('engraved_bead_sellability_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained('engraved_bead_families')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('engraved_bead_family_members')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedInteger('configuration_version');
            $table->string('mapping_hash', 64);
            $table->boolean('disable');
            $table->json('intent');
            $table->json('confirmed_steps')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('shopify_stack_inventory_movements', fn (Blueprint $t) => $t->json('bead_intent')->nullable());
    }

    public function down(): void
    {
        Schema::table('shopify_stack_inventory_movements', fn (Blueprint $t) => $t->dropColumn('bead_intent'));
        Schema::dropIfExists('engraved_bead_sellability_operations');
        Schema::table('engraved_bead_family_members', fn (Blueprint $t) => $t->dropColumn('manual_disabled'));
        Schema::table('engraved_bead_families', fn (Blueprint $t) => $t->dropColumn(['automation_held_quantity', 'recalculate_at', 'last_reconciled_at', 'sync_error']));
    }
};
