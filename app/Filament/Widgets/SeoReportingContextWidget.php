<?php

namespace App\Filament\Widgets;

use App\Services\SeoReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class SeoReportingContextWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.seo-reporting-context-widget';

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return app(SeoReportService::class)->dashboardContext($this->periodId());
    }

    public function scopeLabel(): string
    {
        return match ($this->filters['entity_type'] ?? 'site') {
            'query' => 'Search queries',
            'page' => 'Pages',
            default => 'Site totals',
        };
    }

    private function periodId(): ?int
    {
        $value = $this->filters['period_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
