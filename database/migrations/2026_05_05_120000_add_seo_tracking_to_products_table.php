<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('seo_updated_at')->nullable()->after('last_synced_at');
            $table->foreignId('seo_updated_by')->nullable()->after('seo_updated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seo_updated_by');
            $table->dropColumn('seo_updated_at');
        });
    }
};
