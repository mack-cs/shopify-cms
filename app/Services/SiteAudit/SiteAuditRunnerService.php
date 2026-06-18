<?php

namespace App\Services\SiteAudit;

use App\Jobs\CheckSiteAuditUrlJob;
use App\Models\SiteAuditRun;
use App\Models\SiteAuditUrl;
use Illuminate\Support\Collection;

final class SiteAuditRunnerService
{
    public function run(string $type = SiteAuditRun::TYPE_SCHEDULED, ?array $siteAuditUrlIds = null): SiteAuditRun
    {
        $query = SiteAuditUrl::query()
            ->select('id')
            ->orderBy('id');

        if ($siteAuditUrlIds === null) {
            $query->where('is_active', true);
        } else {
            $query->whereIn('id', array_values(array_unique(array_map('intval', $siteAuditUrlIds))));
        }

        $urlIds = $query->pluck('id');

        $run = SiteAuditRun::query()->create([
            'type' => $this->normalizeType($type),
            'status' => SiteAuditRun::STATUS_RUNNING,
            'total_urls' => $urlIds->count(),
            'started_at' => now(),
        ]);

        if ($urlIds->isEmpty()) {
            $run->update([
                'status' => SiteAuditRun::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $run;
        }

        $spacingSeconds = $this->checkSpacingSeconds();

        $urlIds->values()->each(function (int $urlId, int $index) use ($run, $spacingSeconds): void {
            $dispatch = CheckSiteAuditUrlJob::dispatch($run->id, $urlId)
                ->onQueue((string) config('site-audit.queue', 'default'));

            if ($spacingSeconds > 0 && $index > 0) {
                $dispatch->delay($index * $spacingSeconds);
            }
        });

        return $run;
    }

    public function runUrl(SiteAuditUrl $siteAuditUrl, string $type = SiteAuditRun::TYPE_MANUAL): SiteAuditRun
    {
        return $this->run($type, [(int) $siteAuditUrl->id]);
    }

    public function finalizeRunningRuns(): int
    {
        $finalized = 0;

        SiteAuditRun::query()
            ->where('status', SiteAuditRun::STATUS_RUNNING)
            ->orderBy('id')
            ->chunkById(100, function (Collection $runs) use (&$finalized): void {
                foreach ($runs as $run) {
                    if ($run instanceof SiteAuditRun && $this->finalizeRun($run)) {
                        $finalized++;
                    }
                }
            });

        return $finalized;
    }

    public function finalizeRun(SiteAuditRun $run): bool
    {
        $run = $run->fresh() ?? $run;

        if ($run->status !== SiteAuditRun::STATUS_RUNNING) {
            return false;
        }

        if ((int) $run->checked_urls < (int) $run->total_urls) {
            return false;
        }

        $run->update([
            'status' => SiteAuditRun::STATUS_COMPLETED,
            'completed_at' => $run->completed_at ?? now(),
        ]);

        return true;
    }

    private function normalizeType(string $type): string
    {
        return in_array($type, [SiteAuditRun::TYPE_SCHEDULED, SiteAuditRun::TYPE_MANUAL], true)
            ? $type
            : SiteAuditRun::TYPE_SCHEDULED;
    }

    private function checkSpacingSeconds(): int
    {
        $checksPerMinute = (int) config('site-audit.checks_per_minute', 20);

        if ($checksPerMinute <= 0) {
            return 0;
        }

        return max(1, (int) ceil(60 / $checksPerMinute));
    }
}
