<?php

namespace App\Filament\Resources\ProcurementPredictionResource\Pages;

use App\Filament\Resources\ProcurementPredictionResource;
use App\Models\ProcurementPredictionRun;
use App\Services\ProcurementPipelineService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class ListProcurementPredictions extends ListRecords
{
    protected static string $resource = ProcurementPredictionResource::class;

    public function getSubheading(): ?string
    {
        $run = $this->selectedRun();
        if (!$run instanceof ProcurementPredictionRun) {
            return 'No successful procurement prediction is available yet.';
        }

        $timezone = (string) config('procurement.timezone', 'Africa/Johannesburg');
        $generated = $run->completed_at?->timezone($timezone)->format('d M Y \a\t H:i T') ?? '-';
        $movement = $run->product_movement_generated_at?->timezone($timezone)->format('d M Y H:i T') ?? '-';
        $latest = ProcurementPredictionRun::query()->latest('calculation_date')->latest('id')->first();
        $latestStatus = $latest instanceof ProcurementPredictionRun
            ? "Latest run #{$latest->id}: " . str($latest->status)->replace('_', ' ')->title()->toString() . '. '
            : '';

        return $latestStatus . "Last successful generation {$generated} | Movement snapshot {$movement} | Model {$run->model_version} | "
            . "Lead time {$run->default_lead_time_days} days | Attention horizon {$run->attention_horizon_days} days | "
            . "{$run->total_prediction_rows} rows | {$run->warning_count} warnings. "
            . 'Preliminary Order Quantity does not yet include minimum order quantities, stock already on order, '
            . 'product-specific lead times or expected delivery dates.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runNow')->label('Run Now')->icon('heroicon-o-play')->color('primary')
                ->authorize(fn (): bool => ProcurementPredictionResource::canViewAny())
                ->requiresConfirmation()
                ->action(function (ProcurementPipelineService $pipeline): void {
                    $result = $pipeline->queue(
                        now((string) config('procurement.timezone'))->toDateString(),
                        Auth::id(),
                    );
                    Notification::make()
                        ->title($result['queued'] ? 'Procurement run queued' : 'Procurement run already exists')
                        ->body("Run #{$result['run']->id} has status {$result['run']->status}.")
                        ->success()->send();
                }),
        ];
    }

    private function selectedRun(): ?ProcurementPredictionRun
    {
        $runId = data_get($this->tableFilters, 'procurement_prediction_run_id.value');
        return ProcurementPredictionRun::query()
            ->where('status', ProcurementPredictionRun::STATUS_COMPLETED)
            ->when(filled($runId), fn (Builder $query): Builder => $query->whereKey((int) $runId))
            ->latest('calculation_date')->first();
    }
}
