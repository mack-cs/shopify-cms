<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopYourVibeCollectionMapping extends Model
{
    public const MISSING_MESSAGE = 'Collection no longer exists in Shopify';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Durable deletion evidence, separate from mappings detached from a layout. */
    public static function confirmedMissingIds(): array
    {
        return ShopYourVibeDraft::query()
            ->whereNotNull('desired->missing_collection_ids')
            ->get(['desired->missing_collection_ids as missing_ids'])
            ->flatMap(fn ($row) => is_array($row->missing_ids) ? $row->missing_ids : (json_decode($row->missing_ids ?? '[]', true) ?: []))
            ->unique()->values()->all();
    }

    public static function isConfirmedMissing(string $gid): bool
    {
        return ShopYourVibeDraft::query()->whereJsonContains('desired->missing_collection_ids', $gid)->exists();
    }
}
