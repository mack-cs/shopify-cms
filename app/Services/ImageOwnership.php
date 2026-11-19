<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;

/** Stack gallery role. Null on a stack image is unresolved and is never guessed from position. */
final class ImageOwnership
{
    public const STACK_OWNED = 'stack_owned';

    public const COMPONENT_INHERITED = 'component_inherited';

    public const UNRESOLVED = 'unresolved';

    public static function effective(Image $image): ?string
    {
        $product = $image->relationLoaded('product') ? $image->product : $image->product()->first();
        if (! $product instanceof Product || ! $product->isStackProduct()) {
            return null;
        }
        $ownership = trim((string) $image->ownership);

        return in_array($ownership, [self::STACK_OWNED, self::COMPONENT_INHERITED], true)
            ? $ownership
            : self::UNRESOLVED;
    }

    public static function isInherited(Image $image): bool
    {
        return self::effective($image) === self::COMPONENT_INHERITED;
    }

    public static function isStackOwned(Image $image): bool
    {
        return self::effective($image) === self::STACK_OWNED;
    }

    /** Unclassified stack images require ownership review before alt approval. */
    public static function blocksOwnAltApproval(Image $image): bool
    {
        return self::effective($image) === self::UNRESOLVED;
    }
}
