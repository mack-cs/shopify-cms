<?php

namespace App\Jobs;

use App\Services\ImageAltTextExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateImageAltTextExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 480;

    public int $tries = 0; // A bounded batch releases the same job; releases must not exhaust retries.

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public function __construct(public string $exportId, string $connection, string $queue)
    {
        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(ImageAltTextExportService $exports): void
    {
        $lock = Cache::lock('alt-text-export:'.$this->exportId, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            if (! $exports->process($this->exportId)) {
                $this->release(1);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ImageAltTextExportService::class)->fail($this->exportId, $exception?->getMessage() ?? 'Export worker stopped.');
    }
}
