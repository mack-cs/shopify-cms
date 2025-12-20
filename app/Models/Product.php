<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'import_id','handle','title','body_html','vendor','tags',
        'product_category','google_product_category','status',
        'seo_title','seo_description','color_string','approval_version',
        'batch','is_bundle','you_save',
    ];

    protected $casts = [
        'is_bundle' => 'boolean',
        'you_save' => 'decimal:2',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function approvalsForCurrentVersion(): HasMany
    {
        return $this->approvals()->where('approval_version', $this->approval_version);
    }

    public function approvalsForCurrentVersionCount(): int
    {
        return $this->approvalsForCurrentVersion()
            ->distinct('user_id')
            ->count('user_id');
    }

    public function isApprovedByTwo(): bool
    {
        return $this->approvalsForCurrentVersionCount() >= 2;
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ChangeLog::class);
    }


}
