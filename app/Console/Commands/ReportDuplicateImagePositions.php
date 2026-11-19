<?php

namespace App\Console\Commands;

use App\Services\DuplicateImagePositionReport;
use Illuminate\Console\Command;

final class ReportDuplicateImagePositions extends Command
{
    protected $signature = 'images:duplicate-position-report {--output= : CSV path. Defaults to storage/app/duplicate-image-position-report.csv}';

    protected $description = 'Write a read-only report of active duplicate image positions. This does not hide, merge, or delete anything.';

    public function handle(DuplicateImagePositionReport $report): int
    {
        $rows = $report->rows();
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            $path = storage_path('app/duplicate-image-position-report.csv');
        }

        $report->writeCsv($path, $rows);
        $this->info('Wrote '.count($rows).' duplicate position row(s) to '.$path);
        $this->line('No image rows were changed.');

        return self::SUCCESS;
    }
}
