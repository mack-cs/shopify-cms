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
    public const RESULT_FAILED = 'failed';

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
        'error_message',
    ];

    public function siteAuditUrl(): BelongsTo
    {
        return $this->belongsTo(SiteAuditUrl::class);
    }

    public function siteAuditRun(): BelongsTo
    {
        return $this->belongsTo(SiteAuditRun::class);
    }
}
