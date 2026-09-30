<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('new_product_drafts', 'bead_colour_finish')) {
                $table->string('bead_colour_finish', 255)->nullable()->after('uvp_short_paragraph');
            }
        });
    }

    public function down(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('new_product_drafts', 'bead_colour_finish')) {
                $table->dropColumn('bead_colour_finish');
            }
        });
    }
};
