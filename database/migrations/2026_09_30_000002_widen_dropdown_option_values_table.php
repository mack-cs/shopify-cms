<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dropdown_options')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE dropdown_options MODIFY value TEXT NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dropdown_options')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE dropdown_options MODIFY value VARCHAR(255) NOT NULL');
        }
    }
};
