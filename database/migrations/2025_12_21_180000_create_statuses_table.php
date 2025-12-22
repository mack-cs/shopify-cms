<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        if (!Schema::hasTable('products')) {
            return;
        }

        $names = DB::table('products')
            ->select('status')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->pluck('status')
            ->all();

        $rows = array_map(function (string $name): array {
            return ['name' => $name, 'active' => true];
        }, $names);

        if (!empty($rows)) {
            DB::table('statuses')->upsert($rows, ['name'], ['active']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
