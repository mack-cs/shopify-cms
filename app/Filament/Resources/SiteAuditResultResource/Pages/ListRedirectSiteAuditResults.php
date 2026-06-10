<?php

namespace App\Filament\Resources\SiteAuditResultResource\Pages;

use App\Filament\Resources\SiteAuditResultResource;
use App\Models\SiteAuditResult;
use App\Models\SiteAuditRun;
use Illuminate\Database\Eloquent\Builder;

class ListRedirectSiteAuditResults extends ListSiteAuditResults
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
            ->where('result', SiteAuditResult::RESULT_REDIRECT);
    }

    public function getHeading(): string
    {
        return 'Redirect URLs';
    }
}
