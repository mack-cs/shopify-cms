<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ComponentStackPropagation;
use Illuminate\Console\Command;

/** Records an operator choice among approved component descriptions. It does not invent alt text. */
final class ResolveInheritedAltConflict extends Command
{
    protected $signature = 'images:resolve-inherited-alt-conflict {task} {--alt=} {--reason=} {--user=}';

    protected $description = 'Resolve an inherited alt conflict with one of the approved source descriptions.';

    public function handle(ComponentStackPropagation $propagation): int
    {
        $userId = (int) $this->option('user');
        if ($userId < 1) {
            $this->error('An authorized operator is required.');

            return self::FAILURE;
        }
        try {
            $propagation->resolveInheritedAlt(
                (int) $this->argument('task'),
                (string) $this->option('alt'),
                (string) $this->option('reason'),
                $userId
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('The approved source description was saved. The task can be retried.');

        return self::SUCCESS;
    }
}
