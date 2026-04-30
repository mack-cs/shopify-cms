<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_partial_approval_requests', function (Blueprint $table) {
            $table->string('request_batch_id', 36)
                ->nullable()
                ->after('requested_by');

            $table->index(['request_batch_id', 'status'], 'product_partial_approval_batch_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('product_partial_approval_requests', function (Blueprint $table) {
            $table->dropIndex('product_partial_approval_batch_lookup');
            $table->dropColumn('request_batch_id');
        });
    }
};
