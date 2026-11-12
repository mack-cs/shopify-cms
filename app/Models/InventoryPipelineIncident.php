<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InventoryPipelineIncident extends Model
{
    protected $guarded = [];

    protected $casts = [
        'details' => 'array', 'detected_at' => 'datetime', 'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime', 'last_notification_at' => 'datetime',
    ];
}
