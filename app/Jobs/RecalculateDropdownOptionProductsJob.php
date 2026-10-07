<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Normalizer;
use App\Services\TagNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
        $tags = array_filter([$this->tagPrimary, $this->tagSecondary]);
        if (TagNormalizer::containsBundleOrStackTag($this->tagSecondary)) {
            // Bundle products may intentionally omit the parent collection tag.
            $tags = array_filter([$this->tagSecondary]);
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            Product::query()->chunkById(200, function ($products) use ($normalizer, $tags): void {
                foreach ($products as $product) {
                    $productTags = array_map(
                        static fn (string $tag): string => strtolower(trim($tag)),
                        preg_split('/\s*,\s*/', (string) ($product->tags ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []
                    );

                    foreach ($tags as $tag) {
                        if (! in_array(strtolower(trim((string) $tag)), $productTags, true)) {
                            continue 2;
                        }
                    }

                    $normalizer->recalculateErrorsForProduct($product);
                }
            });

            return;
        }

        foreach ($tags as $tag) {
            $query->whereRaw("FIND_IN_SET(?, REPLACE(tags, ', ', ','))", [$tag]);
        }

        $query->chunkById(200, function ($products) use ($normalizer): void {
            foreach ($products as $product) {
                $normalizer->recalculateErrorsForProduct($product);
            }
        });
    }
}
