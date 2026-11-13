<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InventoryPushCancellation extends Model
{
    protected $guarded = [];

    protected $casts = ['batch_ids' => 'array', 'job_uuids' => 'array', 'evidence' => 'array'];

    public static function blocks(int $variantId, ?string $batch, ?string $uuid = null): bool
    {
        return self::query()->where('variant_id', $variantId)->get()->contains(function (self $row) use ($batch, $uuid): bool {
            // Unidentified legacy pushes must be replaced by an explicitly reviewed new batch.
            return $batch === null || ($batch !== '' && in_array($batch, $row->batch_ids, true))
                || ($uuid !== null && in_array($uuid, $row->job_uuids, true));
        });
    }
}
