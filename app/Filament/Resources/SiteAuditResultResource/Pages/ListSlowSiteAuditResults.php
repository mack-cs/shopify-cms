<?php

namespace App\Filament\Resources\SiteAuditResultResource\Pages;

use App\Filament\Resources\SiteAuditResultResource;
use App\Models\SiteAuditRun;
use Illuminate\Database\Eloquent\Builder;

class ListSlowSiteAuditResults extends ListSiteAuditResults
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
            ->where('response_time_ms', '>=', SiteAuditResultResource::slowThreshold());
    }

    public function getHeading(): string
    {
        return 'Slow URLs';
    }
}
