<?php

namespace App\Console\Commands;

use App\Services\ComponentStackPropagation;
use App\Services\StackImageSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Re-dispatches durable component publication and per-stack tasks. It sends nothing when the feature is disabled. */
final class RecoverComponentStackPropagation extends Command
{
    protected $signature = 'images:recover-component-stack-propagation {--report : Read recovery progress and failures without dispatching or changing records}';

    protected $description = 'Recover pending component-to-stack image tasks. No work is done while automatic propagation is disabled.';

    public function handle(ComponentStackPropagation $propagation, StackImageSync $sync): int
    {
        if (! $this->option('report')) {
            $recovered = $sync->recoverAbandonedCursors();
            if ($recovered > 0) {
                $this->info('Requeued '.$recovered.' abandoned stack synchronization cursor(s).');
            }
        }
        if ($this->option('report')) {
            $db = DB::class;
            $this->table(['scan', 'last draft', 'upper draft', 'completed passes'], $db::table('image_relationship_scan_state')->get()
                ->map(fn ($row) => [$row->name, $row->last_id, $row->upper_id, $row->completed_passes])->all());
            $this->table(['draft', 'attempts', 'error'], $db::table('image_relationship_scan_failures')->orderBy('updated_at')->limit(50)->get()
                ->map(fn ($row) => [$row->draft_id, $row->attempts, $row->error])->all());
            $this->table(['task phase', 'count'], $db::table('stack_image_sync_tasks')->selectRaw('phase, count(*) as total')->groupBy('phase')->get()
                ->map(fn ($row) => [$row->phase, $row->total])->all());
            $this->table(['variant operation', 'product', 'variant', 'phase', 'error'], $db::table('image_variant_operations')->whereNotNull('active_key')->limit(50)->get()
                ->map(fn ($row) => [$row->id, $row->product_id, $row->variant_gid, $row->status, $row->error])->all());
            $this->info('Read-only recovery report. No task was dispatched and no Shopify request was made.');

            return self::SUCCESS;
        }
        if (! $propagation->enabled()) {
            $this->info('Component-to-stack propagation is disabled. No tasks were dispatched.');

            return self::SUCCESS;
        }
        $count = $propagation->recoverPending();
        $this->info('Recovered '.$count.' pending component-to-stack item(s).');
        $state = DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->first();
        if ($state) {
            $this->info('Membership scan: '.$state->last_id.'/'.$state->upper_id.'; completed passes '.$state->completed_passes
                .'; failures '.DB::table('image_relationship_scan_failures')->count());
        }

        return self::SUCCESS;
    }
}
