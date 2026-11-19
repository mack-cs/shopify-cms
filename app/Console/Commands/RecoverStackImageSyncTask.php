<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ComponentStackPropagation;
use Illuminate\Console\Command;

/** Reopens a stuck stack task after an operator records why. It does not replay an uncertain Shopify mutation. */
final class RecoverStackImageSyncTask extends Command
{
    protected $signature = 'images:recover-stack-image-task {task} {--reason=} {--user=}';

    protected $description = 'Reopen an exhausted, blocked, failed, or alt-conflict stack image task after the operator records a reason.';

    public function handle(ComponentStackPropagation $propagation): int
    {
        $reason = trim((string) $this->option('reason'));
        $userId = (int) $this->option('user');
        if ($userId < 1) {
            $this->error('An authorized operator is required.');

            return self::FAILURE;
        }
        try {
            $propagation->reopenTask((int) $this->argument('task'), $reason, $userId);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info($propagation->enabled()
            ? 'The task was reopened and queued.'
            : 'The task was reopened. Automatic dispatch stays off until propagation is enabled.');

        return self::SUCCESS;
    }
}
