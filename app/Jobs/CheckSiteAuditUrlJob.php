<?php

namespace App\Jobs;

use App\Models\SiteAuditResult;
use App\Models\SiteAuditRun;
use App\Models\SiteAuditUrl;
use App\Services\SiteAudit\SiteAuditContextService;
use App\Services\SiteAudit\SiteAuditRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckSiteAuditUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public int $siteAuditRunId,
        public int $siteAuditUrlId,
    ) {
    }

    public function handle(SiteAuditContextService $contextService): void
    {
        $run = SiteAuditRun::query()->find($this->siteAuditRunId);
        $auditUrl = SiteAuditUrl::query()->find($this->siteAuditUrlId);

        if (! $run instanceof SiteAuditRun || ! $auditUrl instanceof SiteAuditUrl) {
            return;
        }

        $started = microtime(true);

        try {
            $response = Http::timeout((int) config('site-audit.check_timeout_seconds', 15))
                ->connectTimeout((int) config('site-audit.check_connect_timeout_seconds', 10))
                ->retry(
                    max(1, (int) config('site-audit.rate_limit_retry_attempts', 2)),
                    max(0, (int) config('site-audit.rate_limit_retry_delay_ms', 10000)),
                    fn (Throwable $exception): bool => $exception instanceof RequestException
                        && $exception->response?->status() === 429,
                    throw: false,
                )
                ->withHeaders([
                    'User-Agent' => (string) config('site-audit.user_agent'),
                ])
                ->get($auditUrl->url);

            $responseTimeMs = $this->elapsedMilliseconds($started);
            $statusCode = $response->status();
            $finalUrl = $response->effectiveUri() ? (string) $response->effectiveUri() : $auditUrl->url;
            $result = $this->classifyResult($statusCode, $auditUrl->url, $finalUrl);
            $context = $contextService->explain($auditUrl, $result, $statusCode, $finalUrl);

            SiteAuditResult::query()->create([
                'site_audit_run_id' => $run->id,
                'site_audit_url_id' => $auditUrl->id,
                'status_code' => $statusCode,
                'result' => $result,
                'final_url' => $finalUrl,
                'response_time_ms' => $responseTimeMs,
                'speed_classification' => SiteAuditResult::classifySpeed($responseTimeMs),
                'error_reason' => $context['error_reason'],
                'shopify_resource_status' => $context['shopify_resource_status'],
                'shopify_context' => $context['shopify_context'],
            ]);

            $auditUrl->update([
                'last_checked_at' => now(),
            ]);

            $this->recordProgress($run, in_array($result, SiteAuditResult::ISSUE_RESULTS, true));
        } catch (Throwable $exception) {
            $result = $this->classifyException($exception);
            $responseTimeMs = $this->elapsedMilliseconds($started);
            $context = $contextService->explain($auditUrl, $result, null, null, $exception);

            SiteAuditResult::query()->create([
                'site_audit_run_id' => $run->id,
                'site_audit_url_id' => $auditUrl->id,
                'result' => $result,
                'response_time_ms' => $responseTimeMs,
                'speed_classification' => SiteAuditResult::classifySpeed($responseTimeMs),
                'error_reason' => $context['error_reason'],
                'shopify_resource_status' => $context['shopify_resource_status'],
                'shopify_context' => $context['shopify_context'],
                'error_message' => $exception->getMessage(),
            ]);

            $auditUrl->update([
                'last_checked_at' => now(),
            ]);

            $this->recordProgress($run, true);
        }
    }

    private function classifyResult(int $statusCode, string $originalUrl, string $finalUrl): string
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return $originalUrl === $finalUrl
                ? SiteAuditResult::RESULT_OK
                : SiteAuditResult::RESULT_REDIRECT;
        }

        if ($statusCode >= 300 && $statusCode < 400) {
            return SiteAuditResult::RESULT_REDIRECT;
        }

        if ($statusCode === 404 || $statusCode === 410) {
            return SiteAuditResult::RESULT_BROKEN;
        }

        if ($statusCode === 429) {
            return SiteAuditResult::RESULT_RATE_LIMITED;
        }

        if ($statusCode >= 500) {
            return SiteAuditResult::RESULT_SERVER_ERROR;
        }

        return SiteAuditResult::RESULT_FAILED;
    }

    private function classifyException(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return SiteAuditResult::RESULT_TIMEOUT;
        }

        if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return SiteAuditResult::RESULT_SSL_ERROR;
        }

        if (str_contains($message, 'redirect')) {
            return SiteAuditResult::RESULT_FAILED;
        }

        return SiteAuditResult::RESULT_FAILED;
    }

    private function recordProgress(SiteAuditRun $run, bool $failed): void
    {
        $run->increment('checked_urls');

        if ($failed) {
            $run->increment('failed_urls');
        }

        app(SiteAuditRunnerService::class)->finalizeRun($run);
    }

    private function elapsedMilliseconds(float $started): int
    {
        return max(0, (int) ((microtime(true) - $started) * 1000));
    }
}
