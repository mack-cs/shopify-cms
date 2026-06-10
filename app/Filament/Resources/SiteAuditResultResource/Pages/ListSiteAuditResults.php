<?php

namespace App\Filament\Resources\SiteAuditResultResource\Pages;

use App\Filament\Resources\SiteAuditResultResource;
use App\Models\SiteAuditRun;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class ListSiteAuditResults extends ListRecords
{
    protected static string $resource = SiteAuditResultResource::class;

    #[Url(as: 'run')]
    public ?int $run = null;

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if ($this->run) {
            $query->where('site_audit_run_id', $this->run);
        }

        return $query;
    }

    public function getHeading(): string|Htmlable
    {
        if (! $this->run) {
            return 'Site Audit Results';
        }

        $run = SiteAuditRun::query()->find($this->run);

        if (! $run instanceof SiteAuditRun) {
            return 'Site Audit Results';
        }

        return new HtmlString("Site Audit Results <span style=\"color:#6b7280;font-size:0.75em;font-weight:400;\">Run #{$run->id} | {$run->type} | {$run->status}</span>");
    }
}
