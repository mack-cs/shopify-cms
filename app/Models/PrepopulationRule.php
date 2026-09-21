<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepopulationRule extends Model
{
    public const BEHAVIOR_AUTO_ON_COLLECTION_SELECTION = 'AUTO_ON_COLLECTION_SELECTION';
    public const BEHAVIOR_SHOP_YOUR_VIBE_DROPDOWN = 'SHOP_YOUR_VIBE_DROPDOWN';
    public const HANDLE_GLOBAL_DEFAULTS = '__global_defaults';

    protected $guarded = [];

    protected $casts = [
        'parents' => 'array',
        'add_tags' => 'array',
        'remove_tags' => 'array',
    ];
}
