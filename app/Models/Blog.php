<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Blog extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'status',
        'author_id',
        'published_at',
        'excerpt',
        'content',
        'keyword_focus',
        'seo_title',
        'meta_title',
        'meta_description',
        'notes',
        'reading_time_minutes',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function estimateReadingTimeMinutes(): int
    {
        $text = strip_tags((string) $this->content);
        $words = str_word_count($text);
        return max(1, (int) ceil($words / 200));
    }

    protected static function booted(): void
    {
        static::saving(function (self $blog): void {
            if ($blog->title && !$blog->slug) {
                $blog->slug = Str::slug($blog->title);
            }

            if (!$blog->reading_time_minutes) {
                $blog->reading_time_minutes = $blog->estimateReadingTimeMinutes();
            }
        });
    }
}
