<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\ProcurementSheetSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class RestoreProcurementSheetBackup extends Command
{
    protected $signature = 'procurement:sheets-restore {tab} {--file= : Backup filename under procurement-sheet-backups}';

    protected $description = 'Safely restore one Google Sheet tab from a procurement JSON backup';

    public function handle(GoogleSheetsClient $sheets, ProcurementSheetSchema $schema): int
    {
        $tab = trim((string) $this->argument('tab'));
        $disk = Storage::disk('local');
        $requested = trim((string) $this->option('file'));
        $files = $requested !== ''
            ? ['procurement-sheet-backups/'.basename($requested)]
            : collect($disk->files('procurement-sheet-backups'))
                ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
                ->values()->all();

        foreach ($files as $file) {
            $payload = json_decode((string) $disk->get($file), true);
            $rows = is_array($payload) ? ($payload['tabs'][$tab] ?? null) : null;
            if (! is_array($rows) || count($rows) < 2 || ! is_array($rows[0] ?? null)) {
                continue;
            }
            $skuIndex = collect($rows[0])->search(
                fn ($header): bool => mb_strtolower(trim((string) $header)) === 'sku'
            );
            if ($skuIndex === false || ! collect(array_slice($rows, 1))->contains(
                fn ($row): bool => is_array($row) && trim((string) ($row[$skuIndex] ?? '')) !== ''
            )) {
                continue;
            }

            $width = max(array_map(fn ($row): int => is_array($row) ? count($row) : 0, $rows));
            if ($width < 1) {
                continue;
            }
            $lastColumn = $schema->columnName($width - 1);
            $sheets->batchUpdateValues([[
                'range' => $sheets->range($tab, "A1:{$lastColumn}".count($rows)),
                'values' => $rows,
            ]]);
            $this->info("Restored [{$tab}] from [{$file}] with ".count($rows).' row(s).');

            return self::SUCCESS;
        }

        $this->error("No non-empty backup containing tab [{$tab}] was found.");

        return self::FAILURE;
    }
}
