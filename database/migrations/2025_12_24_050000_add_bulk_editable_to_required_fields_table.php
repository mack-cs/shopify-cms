<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('required_fields', function (Blueprint $table) {
            $table->boolean('bulk_editable')->default(false)->after('required');
        });
    }

    public function down(): void
    {
        Schema::table('required_fields', function (Blueprint $table) {
            $table->dropColumn('bulk_editable');
        });
    }
};
