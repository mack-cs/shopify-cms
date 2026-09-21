<?php

use App\Models\Import;
use App\Services\Normalizer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $normalizer = app(Normalizer::class);

        Import::query()
            ->where('is_current', true)
            ->each(fn (Import $import): mixed => $normalizer->recalculateErrors($import));
    }

    public function down(): void
    {
        // Error state is derived data and should not be restored to stale values.
    }
};
