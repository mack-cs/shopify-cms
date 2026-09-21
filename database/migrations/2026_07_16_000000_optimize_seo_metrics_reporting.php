<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_metrics', function (Blueprint $table): void {
            $table->char('entity_hash', 64)->nullable()->after('entity_value');
        });

        DB::table('seo_metrics')
            ->select(['id', 'entity_value'])
            ->orderBy('id')
            ->chunkById(500, function ($metrics): void {
                foreach ($metrics as $metric) {
                    DB::table('seo_metrics')
                        ->where('id', $metric->id)
                        ->update(['entity_hash' => hash('sha256', (string) $metric->entity_value)]);
                }
            });

        $this->mergeDuplicatePeriods();
        $this->mergeDuplicateMetrics();

        Schema::table('seo_periods', function (Blueprint $table): void {
            $table->unique('label');
            $table->index(['sort_order', 'start_date']);
        });

        Schema::table('seo_metrics', function (Blueprint $table): void {
            $table->unique(['period_id', 'entity_type', 'entity_hash'], 'seo_metrics_period_entity_hash_unique');
            $table->index(['entity_type', 'clicks']);
            $table->index(['entity_type', 'impressions']);
        });
    }

    public function down(): void
    {
        Schema::table('seo_metrics', function (Blueprint $table): void {
            $table->dropUnique('seo_metrics_period_entity_hash_unique');
            $table->dropIndex(['entity_type', 'clicks']);
            $table->dropIndex(['entity_type', 'impressions']);
            $table->dropColumn('entity_hash');
        });

        Schema::table('seo_periods', function (Blueprint $table): void {
            $table->dropUnique(['label']);
            $table->dropIndex(['sort_order', 'start_date']);
        });
    }

    private function mergeDuplicatePeriods(): void
    {
        $groups = DB::table('seo_periods')
            ->select('label')
            ->groupBy('label')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('label');

        foreach ($groups as $label) {
            $periods = DB::table('seo_periods')
                ->where('label', $label)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id']);

            $keeperId = (int) $periods->first()->id;
            $duplicateIds = $periods
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id !== $keeperId)
                ->values();

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('seo_metrics')
                ->whereIn('period_id', $duplicateIds->all())
                ->update(['period_id' => $keeperId]);

            DB::table('seo_periods')
                ->whereIn('id', $duplicateIds->all())
                ->delete();
        }
    }

    private function mergeDuplicateMetrics(): void
    {
        $groups = DB::table('seo_metrics')
            ->select('period_id', 'entity_type', 'entity_hash')
            ->whereNotNull('entity_hash')
            ->groupBy('period_id', 'entity_type', 'entity_hash')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $metrics = DB::table('seo_metrics')
                ->where('period_id', $group->period_id)
                ->where('entity_type', $group->entity_type)
                ->where('entity_hash', $group->entity_hash)
                ->orderBy('id')
                ->get();

            $keeper = $metrics->first();
            if (!$keeper) {
                continue;
            }

            $clicks = (int) $metrics->sum('clicks');
            $impressions = (int) $metrics->sum('impressions');
            $weightedPositionSum = $metrics->sum(
                fn ($metric): float => ((float) $metric->position) * ((int) $metric->impressions),
            );

            DB::table('seo_metrics')
                ->where('id', $keeper->id)
                ->update([
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
                    'position' => $impressions > 0 ? round($weightedPositionSum / $impressions, 2) : 0,
                    'updated_at' => now(),
                ]);

            $duplicateIds = $metrics
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id !== (int) $keeper->id)
                ->values();

            if ($duplicateIds->isNotEmpty()) {
                DB::table('seo_metrics')
                    ->whereIn('id', $duplicateIds->all())
                    ->delete();
            }
        }
    }
};
