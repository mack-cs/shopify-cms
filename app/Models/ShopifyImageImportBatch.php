<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyImageImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        's3_prefix',
        'status',
        'total_files',
        'matched_count',
        'updated_count',
        'failed_count',
        'alt_text_csv_found',
        'alt_text_applied',
        'alt_text_missing',
        'alt_text_unchanged',
        'alt_text_preserved',
        'alt_text_error_count',
        'alt_text_report',
        'started_at',
        'completed_at',
        'created_by',
        'error_message',
    ];

    protected $casts = [
        'alt_text_csv_found' => 'boolean',
        'alt_text_report' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopifyImageImportItem::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function formatImportResult(array $result): string
    {
        $summary = 'Images imported or updated: '.(int) ($result['updated_count'] ?? 0)
            .'. Failed: '.(int) ($result['failed_count'] ?? 0)
            .'. Files: '.(int) ($result['total_files'] ?? 0).'.';

        if (empty($result['alt_text_csv_found'])) {
            return $summary;
        }

        $summary .= ' Alt text applied: '.(int) ($result['alt_text_applied'] ?? 0)
            .'. Alt text missing: '.(int) ($result['alt_text_missing'] ?? 0)
            .'. Alt-text errors: '.(int) ($result['alt_text_error_count'] ?? 0).'.';

        if ((int) ($result['alt_text_unchanged'] ?? 0) > 0) {
            $summary .= ' Alt text unchanged: '.(int) $result['alt_text_unchanged'].'.';
        }

        if ((int) ($result['alt_text_preserved'] ?? 0) > 0) {
            $summary .= ' Alt text preserved: '.(int) $result['alt_text_preserved'].'.';
        }

        return $summary;
    }

    public function resultSummary(): string
    {
        return self::formatImportResult($this->attributesToArray());
    }

    public function altTextReportText(): string
    {
        return collect(is_array($this->alt_text_report) ? $this->alt_text_report : [])
            ->map(function (mixed $row): string {
                if (! is_array($row)) {
                    return '';
                }

                $kind = trim((string) ($row['kind'] ?? 'note'));
                $filename = trim((string) ($row['filename'] ?? ''));
                $message = trim((string) ($row['message'] ?? ''));

                return trim($kind.': '.$filename.($message !== '' ? ' — '.$message : ''));
            })
            ->filter()
            ->implode("\n");
    }

    public static function latestCompletedId(): ?int
    {
        return static::query()
            ->where('status', self::STATUS_COMPLETED)
            ->latest('completed_at')
            ->latest('id')
            ->value('id');
    }
}
