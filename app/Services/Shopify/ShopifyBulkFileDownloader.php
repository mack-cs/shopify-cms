<?php

namespace App\Services\Shopify;

use App\Models\ShopifySyncRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class ShopifyBulkFileDownloader
{
    /**
     * @return array{raw_s3_key:string,metadata_s3_key:string,file_size:int}
     */
    public function downloadAndArchive(ShopifySyncRun $run, string $downloadUrl): array
    {
        if (blank($downloadUrl)) {
            throw new \RuntimeException('Shopify bulk operation completed without a download URL.');
        }

        $sourcePath = tempnam(sys_get_temp_dir(), 'shopify-bulk-jsonl-');
        $gzPath = $sourcePath . '.gz';

        try {
            $response = Http::timeout(900)->sink($sourcePath)->get($downloadUrl);
            if (!$response->successful()) {
                throw new \RuntimeException('Failed to download Shopify bulk file with status ' . $response->status() . '.');
            }

            $this->gzipFile($sourcePath, $gzPath);

            $rawKey = $this->rawKey($run);
            $metadataKey = dirname($rawKey) . '/metadata.json';
            $successKey = dirname($rawKey) . '/_SUCCESS';
            $metadata = $this->metadata($run, $rawKey, filesize($gzPath) ?: 0);
            $disk = $this->disk();

            $rawStream = fopen($gzPath, 'rb');
            if ($rawStream === false) {
                throw new \RuntimeException('Unable to open compressed Shopify bulk file for S3 upload.');
            }

            try {
                if ($disk->put($rawKey, $rawStream) === false) {
                    throw new \RuntimeException("Unable to archive Shopify bulk file at {$rawKey}.");
                }
            } finally {
                fclose($rawStream);
            }

            if ($disk->put($metadataKey, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
                throw new \RuntimeException("Unable to archive Shopify bulk metadata at {$metadataKey}.");
            }

            if ($disk->put($successKey, '') === false) {
                throw new \RuntimeException("Unable to write Shopify bulk success marker at {$successKey}.");
            }

            return [
                'raw_s3_key' => $rawKey,
                'metadata_s3_key' => $metadataKey,
                'file_size' => (int) (filesize($gzPath) ?: 0),
            ];
        } finally {
            @unlink($sourcePath);
            @unlink($gzPath);
        }
    }

    public function archiveToLocalTemp(ShopifySyncRun $run): string
    {
        $rawKey = trim((string) $run->raw_s3_key);
        if ($rawKey === '') {
            throw new \RuntimeException('Sync run does not have an archived raw S3 key.');
        }

        $stream = $this->disk()->readStream($rawKey);
        if (!is_resource($stream)) {
            throw new \RuntimeException("Unable to read archived Shopify file at {$rawKey}. Check SHOPIFY_SYNC_S3_DISK bucket configuration and read permissions.");
        }

        $path = tempnam(sys_get_temp_dir(), 'shopify-bulk-archive-') . '.jsonl.gz';
        $target = fopen($path, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new \RuntimeException('Unable to create local temp file for Shopify archive.');
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        $diskName = (string) config('shopify_sync.s3.disk', 's3');
        $diskConfig = config("filesystems.disks.{$diskName}", []);

        if (!is_array($diskConfig) || $diskConfig === []) {
            throw new \RuntimeException("Shopify sync filesystem disk '{$diskName}' is not configured.");
        }

        if (($diskConfig['driver'] ?? null) === 's3' && blank($diskConfig['bucket'] ?? null)) {
            throw new \RuntimeException(
                "Shopify sync filesystem disk '{$diskName}' is missing an S3 bucket. " .
                'Set AWS_BUCKET or use SHOPIFY_SYNC_S3_DISK with a disk that has a bucket.',
            );
        }

        return Storage::disk($diskName);
    }

    private function gzipFile(string $sourcePath, string $targetPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $target = gzopen($targetPath, 'wb9');

        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                gzclose($target);
            }
            throw new \RuntimeException('Unable to compress Shopify bulk file.');
        }

        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Unable to read Shopify bulk file while compressing.');
                }
                gzwrite($target, $chunk);
            }
        } finally {
            fclose($source);
            gzclose($target);
        }
    }

    private function rawKey(ShopifySyncRun $run): string
    {
        if ($run->dataset === ShopifySyncRun::DATASET_INVENTORY) {
            $prefix = trim((string) config('shopify_sync.s3.raw_inventory_prefix', 'raw/inventory'), '/');
            $businessDate = $run->business_date?->toDateString() ?? now((string) config('shopify_sync.timezone'))->toDateString();

            return "{$prefix}/daily/business_date={$businessDate}/run_id={$run->id}/inventory.jsonl.gz";
        }

        $prefix = trim((string) config('shopify_sync.s3.raw_orders_prefix', 'raw/orders'), '/');
        if ($run->sync_type === ShopifySyncRun::SYNC_TYPE_FULL) {
            return "{$prefix}/full/run_id={$run->id}/orders.jsonl.gz";
        }

        $businessDate = $run->business_date?->toDateString() ?? 'unknown';

        return "{$prefix}/daily/business_date={$businessDate}/run_id={$run->id}/orders.jsonl.gz";
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ShopifySyncRun $run, string $rawKey, int $fileSize): array
    {
        return [
            'sync_run_id' => $run->id,
            'uuid' => $run->uuid,
            'dataset' => $run->dataset,
            'sync_type' => $run->sync_type,
            'run_mode' => $run->run_mode,
            'business_date' => $run->business_date?->toDateString(),
            'window_start' => $run->window_start?->toIso8601String(),
            'window_end' => $run->window_end?->toIso8601String(),
            'operation_id' => $run->shopify_operation_id,
            'operation_status' => $run->shopify_operation_status,
            'operation_created_at' => data_get($run->metadata, 'operation_created_at'),
            'operation_completed_at' => data_get($run->metadata, 'operation_completed_at'),
            'root_object_count' => $run->root_object_count,
            'object_count' => $run->object_count,
            'file_size' => $fileSize,
            'downloaded_at' => now()->toIso8601String(),
            'raw_s3_key' => $rawKey,
        ];
    }
}
