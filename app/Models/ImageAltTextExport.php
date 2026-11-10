<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImageAltTextExport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'criteria' => 'array', 'collection_gids' => 'array', 'include_images' => 'boolean',
        'snapshot_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ImageAltTextExportItem::class, 'export_id');
    }
}
