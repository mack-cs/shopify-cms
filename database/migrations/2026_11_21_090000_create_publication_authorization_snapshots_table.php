<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publication_authorization_snapshots')) {
            return;
        }

        Schema::create('publication_authorization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('approval_version');
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['product_id', 'approval_version'], 'publication_snapshot_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_authorization_snapshots');
    }
};
