<?php

namespace App\Console\Commands;

use App\Services\VariantImageSynchronizer;
use Illuminate\Console\Command;

final class ResolveVariantImageOperation extends Command
{
    protected $signature = 'images:resolve-variant-image-operation {operation} {--operator=} {--reason=} {--abandon}';

    protected $description = 'Read Shopify and confirm or explicitly abandon variant tracking; never replay a mutation';

    public function handle(VariantImageSynchronizer $sync): int
    {
        try {
            $sync->recover((int) $this->argument('operation'), (int) $this->option('operator'), (string) $this->option('reason'), (bool) $this->option('abandon'));
            $this->info('Variant operation resolved. No Shopify mutation was sent.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
