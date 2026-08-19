<?php

namespace App\Services;

use App\Models\NewProductDraft;
use Throwable;

final class NewInTagService
{
    public const TAGS = [
        'new-arrivals',
        'new-in',
        'newbies',
    ];

    /**
     * @param  iterable<int, mixed>  $drafts
     * @return array{updated:int,already_marked:int,failed:int}
     */
    public function markDrafts(iterable $drafts): array
    {
        $updated = 0;
        $alreadyMarked = 0;
        $failed = 0;

        foreach ($drafts as $draft) {
            if (! $draft instanceof NewProductDraft) {
                continue;
            }

            $existing = TagNormalizer::parseTokens($draft->tags);
            if (array_diff(self::TAGS, $existing) === []) {
                $alreadyMarked++;

                continue;
            }

            try {
                $draft->tags = TagNormalizer::normalizeFromArray(array_merge($existing, self::TAGS));
                $draft->save();
                $updated++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'already_marked' => $alreadyMarked,
            'failed' => $failed,
        ];
    }
}
