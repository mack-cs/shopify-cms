<?php

namespace App\Console\Commands;

use App\Services\Shopify\FailedStackFulfillmentDiagnostic;
use App\Services\Shopify\ReadOnlyInventoryDiagnostic;
use Illuminate\Console\Command;

final class DiagnoseFailedStackFulfillments extends Command
{
    protected $signature = 'shopify:diagnose-failed-stack-fulfillments
        {--order= : Filter by Shopify numeric/GID order ID or order number, e.g. #5855}
        {--fulfillment= : Filter by Shopify numeric/GID fulfillment ID}
        {--format=table : table or json; JSON contains the full evidence report}';

    protected $description = 'Read-only evidence report for missing-component/reservation fulfillment failures; never retries or changes inventory';

    public function handle(FailedStackFulfillmentDiagnostic $diagnostic, ReadOnlyInventoryDiagnostic $readOnly): int
    {
        if (! in_array($this->option('format'), ['table', 'json'], true)) {
            $this->error('--format must be table or json.');

            return self::INVALID;
        }
        try {
            $report = $readOnly->run(fn () => $diagnostic->report($this->option('order'), $this->option('fulfillment')));
        } catch (\Throwable $error) {
            // Do not report()/log()/notify(): this command must not persist anything.
            $this->getOutput()->getErrorStyle()->error('Diagnostic failed without recovery actions: '.$error->getMessage());

            return self::FAILURE;
        }
        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->warn('READ ONLY — Shopify inventory is UNVERIFIED. Potential safe retry is conditional, not authorization.');
        $this->table(['Orders', 'Fulfillment (canonical)', 'CMS rows', 'Classification', 'Sequence'], collect($report['fulfillments'])
            ->map(fn ($group) => [implode(', ', $group['order_numbers'] ?: $group['canonical_order_ids']), $group['canonical_fulfillment_id'],
                implode(', ', array_column($group['stored_fulfillment_records'], 'id')), $group['classification'], $group['recommended_sequence']])->all());
        foreach ($report['fulfillments'] as $group) {
            $this->newLine();
            $this->line($group['canonical_fulfillment_id'].' — '.implode(', ', $group['canonical_order_ids']));
            foreach (array_merge($group['reasons'], $group['notes']) as $reason) {
                $this->line('  - '.$reason);
            }
            $this->table(['CMS row', 'Stored fulfillment ID', 'Status', 'Attempts', 'Last error'], array_map(fn ($record) => [
                $record['id'], $record['shopify_fulfillment_id'], $record['processing_status'], $record['attempts'], $record['error_message'],
            ], $group['stored_fulfillment_records']));
            $this->table(['Line', 'SKU', 'Snapshot', 'Missing component IDs'], array_map(fn ($line) => [
                $line['line_id'], $line['sku'], $line['reservation_state'], implode(', ', $line['missing_component_product_ids']),
            ], $group['lines']));
            $this->table(['Reservation', 'Stack', 'Component', 'Status', 'Reserved', 'Consumed', 'Released', 'Written off'], array_map(fn ($r) => [
                $r['id'], $r['stack_sku'], $r['component_sku'], $r['status'], $r['reserved_quantity'], $r['consumed_quantity'], $r['released_quantity'], $r['written_off_quantity'],
            ], $group['order_reservations']));
            $this->table(['Movement', 'Reservation', 'Action', 'Qty', 'Status', 'Attempts', 'Remote outcome'], array_map(fn ($m) => [
                $m['id'], $m['reservation_id'], $m['action'], $m['quantity'], $m['status'], $m['attempts'], $m['remote_outcome'],
            ], $group['order_movements']));
        }
        foreach ($report['unlinked_failed_jobs'] as $job) {
            $this->warn('Unlinked failed job '.$job['uuid'].' — requires_manual_investigation: '.$job['error']);
        }
        $this->line('Full alias, audit, queue and movement evidence: rerun with --format=json and redirect stdout to a report file.');

        return self::SUCCESS;
    }
}
