<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteAuditResult extends Model
{
    public const RESULT_OK = 'ok';
    public const RESULT_REDIRECT = 'redirect';
    public const RESULT_BROKEN = 'broken';
    public const RESULT_SERVER_ERROR = 'server_error';
    public const RESULT_TIMEOUT = 'timeout';
    public const RESULT_SSL_ERROR = 'ssl_error';
    public const RESULT_RATE_LIMITED = 'rate_limited';
    public const RESULT_FAILED = 'failed';

    public const SPEED_GOOD = 'good';
    public const SPEED_ACCEPTABLE = 'acceptable';
    public const SPEED_SLOW = 'slow';
    public const SPEED_VERY_SLOW = 'very_slow';

    public const ISSUE_RESULTS = [
        self::RESULT_BROKEN,
        self::RESULT_SERVER_ERROR,
        self::RESULT_TIMEOUT,
        self::RESULT_SSL_ERROR,
        self::RESULT_FAILED,
    ];

    protected $fillable = [
        'site_audit_run_id',
        'site_audit_url_id',
        'status_code',
        'result',
        'final_url',
        'response_time_ms',
        'speed_classification',
        'error_reason',
        'shopify_resource_status',
        'shopify_context',
        'error_message',
    ];

    protected $casts = [
        'shopify_context' => 'array',
    ];

    public static function classifySpeed(?int $responseTimeMs): ?string
    {
        if ($responseTimeMs === null) {
            return null;
        }

        if ($responseTimeMs >= 5000) {
            return self::SPEED_VERY_SLOW;
        }

        if ($responseTimeMs >= 3000) {
            return self::SPEED_SLOW;
        }

        if ($responseTimeMs >= 1000) {
            return self::SPEED_ACCEPTABLE;
        }

        return self::SPEED_GOOD;
    }

    public static function speedLabels(): array
    {
        return [
            self::SPEED_GOOD => 'Good',
            self::SPEED_ACCEPTABLE => 'Acceptable',
            self::SPEED_SLOW => 'Slow',
            self::SPEED_VERY_SLOW => 'Very slow',
        ];
    }

    public function effectiveResult(): string
    {
        return self::effectiveResultFor($this->result, $this->status_code);
    }

    public static function effectiveResultFor(?string $result, ?int $statusCode): string
    {
        if ((int) $statusCode === 429) {
            return self::RESULT_RATE_LIMITED;
        }

        return trim((string) $result) ?: self::RESULT_FAILED;
    }

    public function siteAuditUrl(): BelongsTo
    {
        return $this->belongsTo(SiteAuditUrl::class);
    }

    public function siteAuditRun(): BelongsTo
    {
        return $this->belongsTo(SiteAuditRun::class);
    }
}
