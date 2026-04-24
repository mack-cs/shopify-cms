<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('collections')) {
            return;
        }

        DB::statement('ALTER TABLE `collections` MODIFY `elegant_footer_description` TEXT NULL');
        DB::statement('ALTER TABLE `collections` MODIFY `draft_elegant_footer_description` TEXT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('collections')) {
            return;
        }

        DB::statement('ALTER TABLE `collections` MODIFY `elegant_footer_description` VARCHAR(255) NULL');
        DB::statement('ALTER TABLE `collections` MODIFY `draft_elegant_footer_description` VARCHAR(255) NULL');
    }
};
