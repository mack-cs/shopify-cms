<?php

namespace App\Filament\Resources\SiteAuditResultResource\Pages;

use App\Filament\Resources\SiteAuditResultResource;
use App\Models\SiteAuditRun;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListLatestSiteAuditResults extends ListSiteAuditResults
{
    protected function getTableQuery(): ?Builder
    {
        $query = SiteAuditResultResource::getEloquentQuery();
        $run = SiteAuditResultResource::latestCompletedRun();

        if (! $run instanceof SiteAuditRun) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('site_audit_run_id', $run->id);
    }

    public function getHeading(): string|Htmlable
    {
        $run = SiteAuditResultResource::latestCompletedRun();

        if (! $run instanceof SiteAuditRun) {
            return 'Latest Site Audit Report';
        }

        return new HtmlString("Latest Site Audit Report <span style=\"color:#6b7280;font-size:0.75em;font-weight:400;\">Run #{$run->id} | completed {$run->completed_at?->format('Y-m-d H:i')}</span>");
    }
}
