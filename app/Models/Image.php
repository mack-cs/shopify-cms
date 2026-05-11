<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Image extends Model
{
    public const SYNC_STATE_SYNCED = 'synced';
    public const SYNC_STATE_LOCAL_NEW = 'local_new';
    public const SYNC_STATE_LOCAL_UPDATED = 'local_updated';
    public const SYNC_STATE_LOCAL_DELETED = 'local_deleted';
    public const SYNC_STATE_REMOTE_DELETED = 'remote_deleted';
    public const SYNC_STATE_CONFLICT = 'conflict';
    public const BACKUP_STATUS_PENDING = 'pending';
    public const BACKUP_STATUS_BACKED_UP = 'backed_up';
    public const BACKUP_STATUS_FAILED = 'failed';
    public const BACKUP_STATUS_MISSING_SOURCE = 'missing_source';
    public const FILENAME_MODE_AUTO = 'auto';
    public const FILENAME_MODE_MANUAL = 'manual';

    protected $fillable = [
        'product_id',
        'shopify_id',
        'image_asset_id',
        'sync_state',
        'local_dirty',
        'last_shopify_seen_at',
        'last_synced_at',
        'src',
        'image_path',
        'backup_status',
        'backup_completed_at',
        'backup_error',
        'approved_filename',
        'filename_mode',
        'last_shopify_synced_image_asset_id',
        'last_shopify_synced_filename',
        'last_shopify_image_synced_at',
        'needs_shopify_image_sync',
        'shopify_image_sync_error',
        'position',
        'alt_text',
    ];

    protected $casts = [
        'local_dirty' => 'boolean',
        'last_shopify_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'backup_completed_at' => 'datetime',
        'last_shopify_image_synced_at' => 'datetime',
        'needs_shopify_image_sync' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('sync_state', [
            self::SYNC_STATE_LOCAL_DELETED,
            self::SYNC_STATE_REMOTE_DELETED,
        ]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(ImageAsset::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function lastShopifySyncedImageAsset(): BelongsTo
    {
        return $this->belongsTo(ImageAsset::class, 'last_shopify_synced_image_asset_id');
    }

    public function backupReady(): bool
    {
        return $this->backup_status === self::BACKUP_STATUS_BACKED_UP
            && $this->imageAsset !== null
            && $this->imageAsset->isAvailable();
    }

    public function desiredSyncSourceUrl(): ?string
    {
        if ($this->backupReady() || $this->localUploadReady()) {
            return route('product-image-backups.show', [
                'image' => $this,
                'filename' => $this->preferredFilename(),
            ]);
        }

        return null;
    }

    public function backupPublicUrl(): ?string
    {
        if (!$this->backupReady()) {
            return null;
        }

        return route('product-image-backups.show', [
            'image' => $this,
            'filename' => $this->preferredFilename(),
        ]);
    }

    public function localUploadReady(): bool
    {
        $imagePath = trim((string) $this->image_path);

        return $imagePath !== '' && Storage::disk('public')->exists($imagePath);
    }

    public function preferredFilename(): string
    {
        $approved = trim((string) $this->approved_filename);
        if ($approved !== '') {
            return $approved;
        }

        return $this->backupFilename();
    }

    public function backupFilename(): string
    {
        $productKey = trim((string) ($this->product?->shopify_id ?? ''));
        if ($productKey === '') {
            $productKey = trim((string) ($this->product?->handle ?? ''));
        }
        if ($productKey === '') {
            $productKey = 'product-' . ($this->product_id ?: 'unknown');
        }

        $productSlug = Str::slug(str_replace(['gid://', '/'], ' ', $productKey));
        $productSlug = $productSlug !== '' ? Str::limit($productSlug, 48, '') : 'product';

        $position = max(1, (int) ($this->position ?? 1));
        $positionSegment = str_pad((string) $position, 2, '0', STR_PAD_LEFT);

        $label = trim((string) ($this->alt_text ?: $this->product?->title ?: 'image'));
        $labelSlug = Str::slug($label);
        $labelSlug = $labelSlug !== '' ? Str::limit($labelSlug, 40, '') : 'image';

        $hash = trim((string) ($this->imageAsset?->sha256 ?? ''));
        $hashSegment = $hash !== '' ? substr($hash, 0, 8) : substr(sha1((string) $this->id), 0, 8);

        $extension = strtolower(trim((string) ($this->imageAsset?->extension ?? '')));
        if ($extension === '') {
            $extension = strtolower(trim((string) pathinfo((string) $this->image_path, PATHINFO_EXTENSION)));
        }
        if ($extension === '') {
            $extension = strtolower(trim((string) pathinfo((string) parse_url((string) $this->src, PHP_URL_PATH), PATHINFO_EXTENSION)));
        }
        if ($extension === '') {
            $extension = 'jpg';
        }

        return "{$productSlug}-{$positionSegment}-{$labelSlug}-{$hashSegment}.{$extension}";
    }

    public function needsShopifyRepublish(): bool
    {
        if ($this->needs_shopify_image_sync) {
            return true;
        }

        if ((int) ($this->image_asset_id ?? 0) !== (int) ($this->last_shopify_synced_image_asset_id ?? 0)) {
            return true;
        }

        return false;
    }

    public function hasManagedSource(): bool
    {
        if ($this->backupReady() || $this->localUploadReady()) {
            return true;
        }

        return $this->normalizeSourceUrl($this->src) !== null;
    }

    private function normalizeSourceUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
    }
}
