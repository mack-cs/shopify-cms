<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('style_profiles', function (Blueprint $table): void {
            $table->timestamp('seo_updated_at')->nullable()->after('draft_image_alt_text');
            $table->foreignId('seo_updated_by')->nullable()->after('seo_updated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('seo_approved_at')->nullable()->after('seo_updated_by');
            $table->foreignId('seo_approved_by')->nullable()->after('seo_approved_at')->constrained('users')->nullOnDelete();
            $table->string('seo_approval_source', 40)->nullable()->after('seo_approved_by');
            $table->foreignId('seo_approval_request_id')->nullable()->after('seo_approval_source')->constrained('product_partial_approval_requests')->nullOnDelete();
            $table->foreignId('seo_synced_by')->nullable()->after('seo_synced_at')->constrained('users')->nullOnDelete();
            $table->string('seo_sync_batch_id', 64)->nullable()->after('seo_synced_by');
        });
    }

    public function down(): void
    {
        Schema::table('style_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seo_synced_by');
            $table->dropColumn('seo_sync_batch_id');
            $table->dropConstrainedForeignId('seo_approval_request_id');
            $table->dropColumn('seo_approval_source');
            $table->dropConstrainedForeignId('seo_approved_by');
            $table->dropColumn('seo_approved_at');
            $table->dropConstrainedForeignId('seo_updated_by');
            $table->dropColumn('seo_updated_at');
        });
    }
};
