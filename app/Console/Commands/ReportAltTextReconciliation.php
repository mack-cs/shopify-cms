<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Services\AltTextReconciliationReport;
use Illuminate\Console\Command;

final class ReportAltTextReconciliation extends Command
{
    protected $signature = 'images:alt-text-reconciliation-report
        {--output= : CSV path. Defaults to storage/app/alt-text-reconciliation-report.csv}
        {--status=failed : Alt text status to classify. This command never publishes or repairs.}';

    protected $description = 'Write a read-only classification of alt-text rows against current Shopify media. This does not publish, repair, or change image rows.';

    public function handle(AltTextReconciliationReport $report): int
    {
        $status = trim((string) $this->option('status'));
        $allowed = [
            Image::ALT_AWAITING_CONFIRMATION => Image::ALT_AWAITING_CONFIRMATION,
            Image::ALT_FAILED => Image::ALT_FAILED,
            Image::ALT_APPROVED => Image::ALT_APPROVED,
            Image::ALT_PUBLISHED => Image::ALT_PUBLISHED,
            Image::ALT_PENDING_REVIEW => Image::ALT_PENDING_REVIEW,
            'failed' => Image::ALT_FAILED,
            'approved' => Image::ALT_APPROVED,
            'published' => Image::ALT_PUBLISHED,
            'pending_review' => Image::ALT_PENDING_REVIEW,
        ];
        if (! isset($allowed[$status])) {
            $this->error('--status must be failed, approved, published, pending_review, or awaiting_confirmation.');

            return self::FAILURE;
        }

        $rows = $report->rows($allowed[$status]);
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            $path = storage_path('app/alt-text-reconciliation-report.csv');
        }

        $report->writeCsv($path, $rows);
        $this->info('Wrote '.count($rows).' alt text row(s) to '.$path);
        $this->line('No image rows were changed and no alt text was published.');

        return self::SUCCESS;
    }
}
