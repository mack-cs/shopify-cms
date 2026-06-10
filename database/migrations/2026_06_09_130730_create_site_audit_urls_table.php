<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_audit_urls', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->string('source')->default('sitemap');
            $table->string('sitemap_url')->nullable();
            $table->string('resource_type')->nullable(); // product, collection, page, blog, unknown
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_audit_urls');
    }
};
