<?php

use App\Models\Import;
use App\Models\Variant;
use App\Services\Normalizer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Variant::query()
            ->whereNotNull('sku')
            ->chunkById(200, function ($variants): void {
                foreach ($variants as $variant) {
                    $sku = trim((string) $variant->sku);
                    $barcode = trim((string) ($variant->barcode ?? ''));

                    if ($sku === '' || $barcode !== '') {
                        continue;
                    }

                    Variant::withoutEvents(fn (): bool => $variant->forceFill([
                        'barcode' => $sku,
                    ])->save());
                }
            });

        $normalizer = app(Normalizer::class);

        Import::query()
            ->where('is_current', true)
            ->each(fn (Import $import): mixed => $normalizer->recalculateErrors($import));
    }

    public function down(): void
    {
        // Barcode is canonical product data and error state is derived; neither
        // should be reverted to an invalid blank/stale value.
    }
};
