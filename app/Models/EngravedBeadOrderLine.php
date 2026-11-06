<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EngravedBeadOrderLine extends Model
{
    public const CONFIGURATION_VALID = 'valid';

    public const CONFIGURATION_INVALID = 'invalid';

    public const CONFIGURATION_ZERO = 'zero';

    public const CONFIGURATION_HISTORICAL = 'historical_skip';

    public const CONFIGURATION_BLOCKED = 'blocked';

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ShopifyStackInventoryReservation::class, 'reservation_id');
    }
}
