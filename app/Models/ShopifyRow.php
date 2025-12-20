<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyRow extends Model
{
     protected $fillable = [
        'import_id','row_index','handle','row_type','variant_key','image_key','data'
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function get(string $header, mixed $default = ''): mixed
    {
        return $this->data[$header] ?? $default;
    }

    public function set(string $header, mixed $value): void
    {
        $data = $this->data;
        $data[$header] = $value;
        $this->data = $data;
    }
}
