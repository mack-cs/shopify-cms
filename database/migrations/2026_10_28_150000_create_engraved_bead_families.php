<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engraved_bead_families', function (Blueprint $table): void {
            $table->id();
            $table->string('family_tag')->unique();
            $table->string('display_name');
            $table->string('source_product_id')->nullable();
            $table->string('source_variant_id')->nullable();
            $table->string('source_inventory_item_id')->nullable();
            $table->string('source_location_id')->nullable();
            $table->string('status')->default('pending_validation')->index();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->string('mapping_hash', 64)->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('observed_mapping')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });
        Schema::create('engraved_bead_family_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained('engraved_bead_families')->restrictOnDelete();
            $table->string('shopify_product_id')->unique();
            $table->string('shopify_variant_id')->unique();
            $table->string('shopify_inventory_item_id')->unique();
            $table->string('sku');
            $table->unsignedTinyInteger('required_beads');
            $table->unsignedInteger('disable_threshold');
            $table->boolean('last_verified_tracked')->nullable();
            $table->boolean('last_verified_sellable')->nullable();
            $table->boolean('automation_disabled')->default(false);
            $table->string('status')->default('pending_validation');
            $table->timestamps();
            $table->unique(['family_id', 'required_beads']);
        });
        Schema::create('engraved_bead_family_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained('engraved_bead_families')->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->json('details');
            $table->timestamps();
        });
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('bead_family_id')->nullable()->constrained('engraved_bead_families')->restrictOnDelete();
            $table->unsignedInteger('bead_configuration_version')->nullable();
            $table->unsignedInteger('historical_quantity')->default(0);
        });
    }

    public function down(): void
    {
        // Operational rollback must retain ledger provenance; never roll this down on a live ledger.
        Schema::table('shopify_stack_inventory_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bead_family_id');
            $table->dropColumn(['bead_configuration_version', 'historical_quantity']);
        });
        Schema::dropIfExists('engraved_bead_family_audits');
        Schema::dropIfExists('engraved_bead_family_members');
        Schema::dropIfExists('engraved_bead_families');
    }
};
