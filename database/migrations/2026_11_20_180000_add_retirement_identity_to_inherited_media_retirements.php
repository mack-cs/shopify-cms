<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inherited_media_retirements', function (Blueprint $table): void {
            if (! Schema::hasColumn('inherited_media_retirements', 'component_product_id')) {
                $table->unsignedBigInteger('component_product_id')->nullable();
            }
            if (! Schema::hasColumn('inherited_media_retirements', 'component_image_id')) {
                $table->unsignedBigInteger('component_image_id')->nullable();
            }
            if (! Schema::hasColumn('inherited_media_retirements', 'image_asset_id')) {
                $table->unsignedBigInteger('image_asset_id')->nullable();
            }
            if (! Schema::hasColumn('inherited_media_retirements', 'asset_sha256')) {
                $table->string('asset_sha256', 64)->nullable();
            }
            if (! Schema::hasColumn('inherited_media_retirements', 'membership_evidence')) {
                $table->text('membership_evidence')->nullable();
            }
            if (! Schema::hasColumn('inherited_media_retirements', 'deferral_reason')) {
                $table->text('deferral_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain retirement identity during rollback; destructive schema rollback requires separate review.');
    }
};
