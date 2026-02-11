<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionApproval extends Model
{
    protected $fillable = [
        'collection_id',
        'user_id',
        'approval_version',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ShopifyCollection::class, 'collection_id');
    }
}
