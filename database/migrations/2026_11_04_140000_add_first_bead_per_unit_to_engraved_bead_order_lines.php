<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engraved_bead_order_lines', function (Blueprint $table): void {
            $table->unsignedTinyInteger('first_bead_per_unit')->default(0)->after('beads');
        });
    }

    public function down(): void
    {
        Schema::table('engraved_bead_order_lines', function (Blueprint $table): void {
            $table->dropColumn('first_bead_per_unit');
        });
    }
};
