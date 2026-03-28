<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ImageAsset extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_MISSING = 'missing';

    protected $fillable = [
        'sha256',
        'storage_disk',
        'storage_path',
        'original_filename',
        'source_url',
        'mime_type',
        'extension',
        'file_size',
        'downloaded_at',
        'last_verified_at',
        'missing_at',
        'status',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'missing_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function isAvailable(): bool
    {
        $disk = trim((string) $this->storage_disk) !== '' ? $this->storage_disk : 'public';
        $path = trim((string) $this->storage_path);

        return $this->status === self::STATUS_AVAILABLE
            && $path !== ''
            && Storage::disk($disk)->exists($path);
    }
}
