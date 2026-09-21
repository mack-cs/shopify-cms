<?php

namespace App\Filament\Resources\SiteAuditResultResource\Pages;

use App\Filament\Resources\SiteAuditResultResource;
use App\Models\SiteAuditResult;
use App\Models\SiteAuditRun;
use Illuminate\Database\Eloquent\Builder;

class ListBrokenSiteAuditResults extends ListSiteAuditResults
{
    protected function getTableQuery(): ?Builder
    {
        $query = SiteAuditResultResource::getEloquentQuery();
        $run = SiteAuditResultResource::latestCompletedRun();

        if (! $run instanceof SiteAuditRun) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('site_audit_run_id', $run->id)
            ->whereIn('result', SiteAuditResult::ISSUE_RESULTS)
            ->where(function (Builder $notRateLimitedQuery): void {
                $notRateLimitedQuery->whereNull('status_code')
                    ->orWhere('status_code', '!=', 429);
            });
    }

    public function getHeading(): string
    {
        return 'Broken URLs';
    }
}
