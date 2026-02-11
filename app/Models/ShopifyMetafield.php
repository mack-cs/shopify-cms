<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyMetafield extends Model
{
    protected $fillable = [
        'import_id',
        'handle',
        'namespace',
        'key',
        'type',
        'value',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
