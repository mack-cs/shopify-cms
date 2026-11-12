<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_pipeline_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('kind');
            $table->string('source_type');
            $table->string('source_id');
            $table->unsignedInteger('generation')->default(1);
            $table->json('details');
            $table->timestamp('detected_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notification_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_pipeline_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('inventory_pipeline_incidents')->cascadeOnDelete();
            $table->string('delivery_key')->unique();
            $table->string('type');
            $table->json('details');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('delivery_error')->nullable();
            $table->timestamp('available_at')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_pipeline_alerts');
        Schema::dropIfExists('inventory_pipeline_incidents');
    }
};
