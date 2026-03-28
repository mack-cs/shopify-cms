<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('approved_handle')->nullable()->after('handle');
            $table->timestamp('first_handle_auto_lock_completed_at')->nullable()->after('first_image_auto_rename_approval_version');
            $table->unsignedInteger('first_handle_auto_lock_approval_version')->nullable()->after('first_handle_auto_lock_completed_at');

            $table->index('approved_handle');
            $table->index('first_handle_auto_lock_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['approved_handle']);
            $table->dropIndex(['first_handle_auto_lock_completed_at']);
            $table->dropColumn([
                'approved_handle',
                'first_handle_auto_lock_completed_at',
                'first_handle_auto_lock_approval_version',
            ]);
        });
    }
};
