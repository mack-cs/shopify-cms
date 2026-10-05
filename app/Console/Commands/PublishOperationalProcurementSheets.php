<?php

namespace App\Console\Commands;

use App\Models\Variant;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use Illuminate\Console\Command;

class PublishOperationalProcurementSheets extends Command
{
    protected $signature = 'procurement:sheets-operational
        {--variant=* : Optional local variant IDs, or SKUs for local testing}
        {--sku=* : Optional SKUs to publish}
        {--append-missing : Append missing targeted rows instead of updating existing rows only}';
    protected $description = 'Publish current inventory and CMS supplier-order fields without rerunning ML';

    public function handle(ProcurementSheetSyncService $sheets): int
    {
        $ids = $this->variantIds();
        $appendMissing = (bool) $this->option('append-missing');
        if ($appendMissing && $ids === []) {
            $this->error('Use --append-missing with at least one --variant or --sku value.');

            return self::FAILURE;
        }

        $result = $sheets->publishOperational($ids, appendMissing: $appendMissing);
        $this->info("Published {$result['rows']} operational row update(s) across {$result['tabs']} tab(s).");
        return self::SUCCESS;
    }

    /** @return array<int,int> */
    private function variantIds(): array
    {
        $ids = collect($this->option('variant'))
            ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
            ->filter(fn (mixed $value): bool => ctype_digit(trim((string) $value)))
            ->map(fn (mixed $id): int => (int) $id);

        $skus = collect([...$this->option('sku'), ...$this->option('variant')])
            ->map(fn (mixed $value): string => strtoupper(trim((string) $value)))
            ->filter(fn (string $value): bool => $value !== '' && ! ctype_digit($value))
            ->unique()
            ->values();

        if ($skus->isNotEmpty()) {
            $matched = Variant::query()
                ->whereIn(\DB::raw('UPPER(TRIM(sku))'), $skus->all())
                ->pluck('id');

            $ids = $ids->merge($matched);
        }

        return $ids->unique()->values()->all();
    }
}
