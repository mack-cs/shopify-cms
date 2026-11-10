<?php

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Jobs\GenerateImageAltTextExport;
use App\Models\ImageAltTextExport;
use App\Models\ImageAltTextExportItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class ImageAltTextExportService
{
    public const HEADERS = ['image_id', 'product_id', 'shopify_product_id', 'shopify_media_id', 'sku', 'product_title', 'position', 'image_url', 'alt_text'];

    public function request(User $user, array $collectionValues, bool $includeImages): ImageAltTextExport
    {
        abort_unless($user->can(PermissionEnum::ProductEdit->value), 403);
        $values = array_values(array_unique(array_filter(array_map(fn ($value): string => trim((string) $value), $collectionValues))));
        sort($values);
        $connection = (string) config('alt_text_exports.connection', 'database');
        $settings = config('queue.connections.'.$connection, []);
        // Never fall through to sync/deferred/background, even when the app default is sync.
        if (($settings['driver'] ?? '') !== 'database' || (int) ($settings['retry_after'] ?? 0) <= 540
            || ($settings['connection'] ?? config('database.default')) !== config('database.default')) {
            throw new RuntimeException('Alt text exports require the existing database queue on the application database, with retry_after greater than 540 seconds.');
        }
        $disk = (string) config('alt_text_exports.disk', 'local');
        if (! config('filesystems.disks.'.$disk) || $disk === 'public' || config('filesystems.disks.'.$disk.'.visibility') === 'public') {
            throw new RuntimeException('Alt text exports require an existing private storage disk.');
        }
        $fingerprint = hash('sha256', json_encode([$user->id, $values, $includeImages]));

        return DB::transaction(function () use ($user, $values, $includeImages, $connection, $fingerprint): ImageAltTextExport {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = ImageAltTextExport::where('fingerprint', $fingerprint)->where('expires_at', '>', now())->first();
            if ($existing) {
                return $existing;
            }
            ImageAltTextExport::where('fingerprint', $fingerprint)->update(['fingerprint' => null]);
            $export = ImageAltTextExport::create([
                'user_id' => $user->id, 'status' => 'queued', 'fingerprint' => $fingerprint,
                'criteria' => ['collections' => $values], 'include_images' => $includeImages,
                'connection' => $connection, 'queue' => config('alt_text_exports.queue', 'default'),
                'disk' => config('alt_text_exports.disk', 'local'),
                'expires_at' => now()->addHours((int) config('alt_text_exports.retention_hours', 24)),
            ]);
            // The existing database queue persists this job in the same transaction as the export.
            GenerateImageAltTextExport::dispatch($export->id, $export->connection, $export->queue)->beforeCommit();

            return $export;
        });
    }

    public function retry(User $user, ImageAltTextExport $export): void
    {
        $this->authorize($user, $export);
        DB::transaction(function () use ($user, $export): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $export = ImageAltTextExport::lockForUpdate()->findOrFail($export->id);
            if ($export->status !== 'failed' && ! ($export->status === 'completed' && $export->download_failed > 0)) {
                return;
            }
            $fingerprint = hash('sha256', json_encode([$user->id, $export->criteria['collections'], $export->include_images]));
            abort_if(ImageAltTextExport::where('fingerprint', $fingerprint)->whereKeyNot($export->id)->where('expires_at', '>', now())->exists(),
                409, 'An equivalent export is already running.');
            ImageAltTextExport::where('fingerprint', $fingerprint)->where('expires_at', '<=', now())->update(['fingerprint' => null]);
            $export->items()->where('status', 'failed')->update(['status' => 'pending', 'error' => null]);
            $export->update(['status' => 'queued', 'error' => null, 'completed_at' => null, 'fingerprint' => $fingerprint,
                'download_failed' => 0, 'processed' => $export->items()->where('status', 'downloaded')->count()]);
            GenerateImageAltTextExport::dispatch($export->id, $export->connection, $export->queue)->beforeCommit();
        });
    }

    public function authorize(User $user, ImageAltTextExport $export): void
    {
        abort_unless((int) $export->user_id === (int) $user->id && $user->can(PermissionEnum::ProductEdit->value), 403);
        abort_if($export->expires_at->isPast(), 410, 'This export has expired.');
    }

    /** Each invocation downloads at most one bounded batch, then releases the same queued job. */
    public function process(string $id): bool
    {
        $export = ImageAltTextExport::find($id);
        if (! $export || in_array($export->status, ['completed', 'failed'], true) || $export->expires_at->isPast()) {
            return true;
        }
        $export->update(['status' => 'processing', 'started_at' => $export->started_at ?? now(), 'error' => null]);
        try {
            if ($export->snapshot_at === null) {
                $this->snapshot($export);
                // Keep potentially slow Shopify pagination separate from download/ZIP time budgets.
                if ($export->include_images && $export->total > 0) {
                    return false;
                }
            }
            if ($export->include_images) {
                $size = min(10, max(1, (int) config('alt_text_exports.batch_size', 10)));
                $items = $export->items()->where('status', 'pending')->orderBy('id')->limit($size)->get();
                foreach ($items as $item) {
                    $this->download($export, $item);
                    $export->update([
                        'processed' => $export->items()->where('status', '!=', 'pending')->count(),
                        'download_failed' => $export->items()->where('status', 'failed')->count(),
                    ]);
                }
                // Archive finalization gets its own worker invocation and timeout budget.
                if ($items->isNotEmpty()) {
                    return false;
                }
            }
            $this->finish($export);

            return true;
        } catch (Throwable $exception) {
            $export->update(['error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            $this->removeWork($export);
        }
    }

    private function snapshot(ImageAltTextExport $export): void
    {
        $gids = app(ShopifyCollectionMembership::class)->resolveCollectionGids($export->criteria['collections'] ?? []);
        // An interrupted snapshot is discarded. Once snapshot_at is stored, retries never re-resolve it.
        $export->items()->delete();
        $batch = [];
        foreach (app(ImageAltTextBackfillService::class)->snapshotRows($gids) as $row) {
            $batch[] = ['export_id' => $export->id, 'image_id' => (int) $row[0], 'csv_row' => json_encode($row), 'status' => 'pending'];
            if (count($batch) === 100) {
                ImageAltTextExportItem::insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            ImageAltTextExportItem::insert($batch);
        }
        $export->update(['collection_gids' => $gids, 'snapshot_at' => now(), 'total' => $export->items()->count()]);
    }

    private function download(ImageAltTextExport $export, ImageAltTextExportItem $item): void
    {
        $directory = $this->workDirectory($export);
        File::ensureDirectoryExists($directory);
        $temporary = $directory.'/download.part';
        $input = null;
        $output = null;
        try {
            $url = (string) ($item->csv_row[7] ?? '');
            if (! preg_match('~^https?://~i', $url)) {
                throw new RuntimeException('No downloadable photograph URL.');
            }
            $timeout = min(15, max(1, (int) config('alt_text_exports.download_timeout', 15)));
            $response = Http::connectTimeout(5)->timeout($timeout)
                ->withOptions(['stream' => true, 'read_timeout' => 5])->get($url);
            if (! $response->successful()) {
                throw new RuntimeException('Photograph download returned HTTP '.$response->status().'.');
            }
            $input = $response->toPsrResponse()->getBody()->detach();
            $output = fopen($temporary, 'wb');
            if (! is_resource($input) || ! is_resource($output)) {
                throw new RuntimeException('Could not open photograph streams.');
            }
            $started = microtime(true);
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new RuntimeException('Photograph stream stopped before completion.');
                }
                $bytes += strlen($chunk);
                if ($bytes > (int) config('alt_text_exports.max_image_bytes', 52428800) || microtime(true) - $started > $timeout) {
                    throw new RuntimeException('Photograph exceeded the download size or time limit.');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write photograph temporary file.');
                }
            }
            fclose($output);
            $output = null;
            if ($bytes === 0) {
                throw new RuntimeException('Photograph response was empty.');
            }
            $input = $this->closeStream($input);
            $stream = fopen($temporary, 'rb');
            try {
                $path = $this->prefix($export).'/'.$this->imageName($item);
                if (! Storage::disk($export->disk)->put($path, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Could not store downloaded photograph.');
                }
            } finally {
                fclose($stream);
            }
            $item->update(['status' => 'downloaded', 'path' => $path, 'bytes' => $bytes, 'error' => null]);
        } catch (Throwable $exception) {
            $item->update(['status' => 'failed', 'error' => $exception->getMessage(), 'path' => null]);
        } finally {
            $this->closeStream($input);
            $this->closeStream($output);
            @unlink($temporary);
        }
    }

    private function finish(ImageAltTextExport $export): void
    {
        $directory = $this->workDirectory($export);
        File::ensureDirectoryExists($directory);
        $csvPath = $directory.'/alt-text.csv';
        $manifestPath = $directory.'/manifest.csv';
        $csv = fopen($csvPath, 'wb');
        $manifest = fopen($manifestPath, 'wb');
        try {
            fputcsv($csv, self::HEADERS);
            fputcsv($manifest, ['filename', 'image_id', 'product_id', 'sku', 'position', 'image_url', 'status', 'error']);
            foreach ($export->items()->orderBy('id')->lazyById(100) as $item) {
                $row = $item->csv_row;
                fputcsv($csv, $row);
                fputcsv($manifest, [$this->imageName($item), $item->image_id, $row[1], $row[4], $row[6], $row[7],
                    $export->include_images ? $item->status : 'not_requested', $item->error ?? '']);
            }
        } finally {
            fclose($csv);
            fclose($manifest);
        }
        $artifact = $csvPath;
        $filename = 'alt-text.csv';
        if ($export->include_images) {
            $filename = 'alt-text.zip';
            $artifact = $directory.'/'.$filename;
            $zip = new ZipArchive;
            if ($zip->open($artifact, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create ZIP.');
            }
            try {
                if (! $zip->addFile($csvPath, 'alt-text.csv') || ! $zip->addFile($manifestPath, 'manifest.csv')) {
                    throw new RuntimeException('Could not add CSV and manifest to ZIP.');
                }
                foreach ($export->items()->where('status', 'downloaded')->lazyById(100) as $item) {
                    $local = $directory.'/image-'.$item->image_id;
                    $input = Storage::disk($export->disk)->readStream($item->path);
                    $output = fopen($local, 'wb');
                    try {
                        if (! is_resource($input) || ! is_resource($output)
                            || stream_copy_to_stream($input, $output) !== (int) $item->bytes) {
                            throw new RuntimeException('Stored photograph '.$item->image_id.' is missing or incomplete.');
                        }
                    } finally {
                        $this->closeStream($input);
                        $this->closeStream($output);
                    }
                    if (! $zip->addFile($local, $this->imageName($item))) {
                        throw new RuntimeException('Could not add photograph '.$item->image_id.' to ZIP.');
                    }
                    $zip->setCompressionName($this->imageName($item), ZipArchive::CM_STORE);
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('Could not finalize ZIP.');
                }
            }
            $check = new ZipArchive;
            if ($check->open($artifact, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('ZIP integrity verification failed.');
            }
            try {
                if ($check->numFiles !== 2 + $export->items()->where('status', 'downloaded')->count()
                    || $check->locateName('alt-text.csv') === false || $check->locateName('manifest.csv') === false) {
                    throw new RuntimeException('ZIP is missing expected entries.');
                }
            } finally {
                $check->close();
            }
        }
        $disk = Storage::disk($export->disk);
        $path = $this->prefix($export).'/'.$filename;
        try {
            $stream = fopen($artifact, 'rb');
            try {
                if (! $disk->put($path, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('Export storage failed.');
                }
            } finally {
                fclose($stream);
            }
            $stored = $disk->readStream($path);
            if (! is_resource($stored)) {
                throw new RuntimeException('Stored export could not be verified.');
            }
            try {
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stored);
                $sha = hash_final($hash);
            } finally {
                fclose($stored);
            }
            if ($bytes !== filesize($artifact) || $sha !== hash_file('sha256', $artifact)) {
                throw new RuntimeException('Stored export checksum verification failed.');
            }
            $export->update(['status' => 'completed', 'path' => $path, 'sha256' => $sha, 'bytes' => $bytes,
                'processed' => $export->total, 'completed_at' => now(), 'fingerprint' => null, 'error' => null]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function fail(string $id, string $reason): void
    {
        $export = ImageAltTextExport::find($id);
        if (! $export || $export->status === 'completed') {
            return;
        }
        $export->update(['status' => 'failed', 'error' => $reason, 'fingerprint' => null]);
        Storage::disk($export->disk)->delete([$this->prefix($export).'/alt-text.zip', $this->prefix($export).'/alt-text.csv']);
        $this->removeWork($export);
    }

    public function cleanupExpired(): int
    {
        $deleted = 0;
        foreach (ImageAltTextExport::where('expires_at', '<=', now())->lazyById(100, 'id') as $export) {
            $lock = Cache::lock('alt-text-export:'.$export->id, 540);
            if (! $lock->get()) {
                continue;
            }
            try {
                if (! Storage::disk($export->disk)->deleteDirectory($this->prefix($export))) {
                    throw new RuntimeException('Could not remove expired export '.$export->id.'.');
                }
                $this->removeWork($export);
                $export->delete();
                $deleted++;
            } finally {
                $lock->release();
            }
        }

        return $deleted;
    }

    private function prefix(ImageAltTextExport $export): string
    {
        return 'alt-text-exports/'.$export->id;
    }

    private function workDirectory(ImageAltTextExport $export): string
    {
        return storage_path('app/private/alt-text-export-work/'.$export->id);
    }

    private function removeWork(ImageAltTextExport $export): void
    {
        File::deleteDirectory($this->workDirectory($export));
    }

    private function imageName(ImageAltTextExportItem $item): string
    {
        $extension = strtolower(pathinfo((string) parse_url($item->csv_row[7] ?? '', PHP_URL_PATH), PATHINFO_EXTENSION));

        return 'images/image-'.$item->image_id.'.'.(in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $extension : 'jpg');
    }

    private function closeStream(mixed $stream): mixed
    {
        if (is_resource($stream)) {
            fclose($stream);
        }

        return null;
    }
}
