<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Normalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecalculateDropdownOptionProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public readonly ?string $tagPrimary,
        public readonly ?string $tagSecondary,
    ) {}

    public function handle(Normalizer $normalizer): void
    {
        $query = Product::query();
        foreach (array_filter([$this->tagPrimary, $this->tagSecondary]) as $tag) {
            $query->whereRaw("FIND_IN_SET(?, REPLACE(tags, ', ', ','))", [$tag]);
        }

        $query->chunkById(200, function ($products) use ($normalizer): void {
            foreach ($products as $product) {
                $normalizer->recalculateErrorsForProduct($product);
            }
        });
    }
}
