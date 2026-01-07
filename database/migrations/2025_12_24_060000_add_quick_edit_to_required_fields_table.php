<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('required_fields', function (Blueprint $table) {
            $table->boolean('quick_edit')->default(false)->after('bulk_editable');
        });
    }

    public function down(): void
    {
        Schema::table('required_fields', function (Blueprint $table) {
            $table->dropColumn('quick_edit');
        });
    }
};
