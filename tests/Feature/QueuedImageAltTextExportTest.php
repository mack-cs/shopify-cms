<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\PermissionEnum;
use App\Filament\Resources\ProductResource\Widgets\AltTextExportStatus;
use App\Jobs\GenerateImageAltTextExport;
use App\Models\Image;
use App\Models\ImageAltTextExport;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\ImageAltTextExportService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('alt_text_exports.connection', 'database');
    config()->set('alt_text_exports.queue', 'default');
    config()->set('alt_text_exports.disk', 'local');
    Http::preventStrayRequests();
    Permission::findOrCreate(PermissionEnum::ProductEdit->value);
    $this->exportUser = User::factory()->create();
    $this->exportUser->givePermissionTo(PermissionEnum::ProductEdit->value);
    $this->actingAs($this->exportUser);
});

it('persists the filter and queues immediately without Shopify reads or photograph downloads', function (): void {
    $gateway = queuedExportGateway([]);
    $service = app(ImageAltTextExportService::class);
    $started = microtime(true);
    $export = $service->request($this->exportUser, ['Collection B', 'Collection A'], true);
    $elapsed = microtime(true) - $started;
    $duplicate = $service->request($this->exportUser, ['Collection A', 'Collection B'], true);
    expect($export->status)->toBe('queued')->and($export->snapshot_at)->toBeNull()
        ->and($export->criteria)->toBe(['collections' => ['Collection A', 'Collection B']])
        ->and($export->items()->count())->toBe(0)
        ->and($duplicate->id)->toBe($export->id)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($gateway->queries)->toBe([])
        ->and($elapsed)->toBeLessThan(2.0)
        ->and(config('queue.connections.'.$export->connection.'.driver'))->toBe('database')
        ->and(config('queue.connections.'.$export->connection.'.retry_after'))->toBeGreaterThan((new GenerateImageAltTextExport($export->id, $export->connection, $export->queue))->timeout + 60);
    Http::assertNothingSent();
});

it('rejects synchronous connections instead of running an export in a browser request', function (): void {
    config()->set('alt_text_exports.connection', 'sync');
    expect(fn () => app(ImageAltTextExportService::class)->request($this->exportUser, [], true))->toThrow(RuntimeException::class);
    expect(ImageAltTextExport::count())->toBe(0);
});

it('runs a real queued worker and freezes collection union image IDs while recording missing photographs', function (): void {
    config()->set('alt_text_exports.batch_size', 1);
    [$a, $front] = queuedExportProduct('A', 701);
    $side = queuedExportImage($a, 2, 'A side');
    [$b, $bfront] = queuedExportProduct('B', 702);
    [$stack, $owned] = queuedExportProduct('STACK', 703, true);
    queuedExportImage($stack, 2, 'Unmapped legacy');
    $copy = queuedExportImage($stack, 3, 'Inherited copy');
    $copy->update(['source_image_id' => $front->id]);
    [$outside] = queuedExportProduct('OUTSIDE', 704);
    [$inactive] = queuedExportProduct('INACTIVE', 705);
    Product::withoutEvents(fn () => $inactive->update(['status' => 'archived']));
    Product::withoutEvents(fn () => $outside->update(['tags' => 'Collection A,Collection B']));
    queuedExportCollection($a, 'Collection A', 1);
    queuedExportCollection($a, 'Collection B', 2);
    $gateway = queuedExportGateway([1 => [701, 703, 705], 2 => [702, 703]]);
    $recovered = false;
    Http::fake(function ($request) use ($side, &$recovered) {
        $missing = ! $recovered && $request->url() === $side->src;

        return Http::response($missing ? 'Missing' : 'photo-'.$request->url(), $missing ? 404 : 200);
    });
    $service = app(ImageAltTextExportService::class);
    $export = $service->request($this->exportUser, ['Collection A', 'Collection B'], true);
    queuedExportWork(); // Shopify pagination and durable snapshot, no image downloads.
    expect($export->fresh()->status)->toBe('processing')->and($export->fresh()->total)->toBe(4)
        ->and($export->fresh()->items()->pluck('image_id')->all())->toBe([$front->id, $side->id, $bfront->id, $owned->id]);
    Http::assertNothingSent();
    queuedExportImage($a, 4, 'Created after snapshot');
    Image::withoutEvents(fn () => $front->update(['alt_text' => 'Changed after snapshot']));
    $this->travel(2)->seconds();
    queuedExportWork();
    expect($export->fresh()->processed)->toBe(1)->and($export->fresh()->status)->toBe('processing');
    Livewire::test(AltTextExportStatus::class)->assertSee('Processing')->assertSee('1 / 4 images processed');
    queuedExportDrain($export);
    $export->refresh();
    expect($export->status)->toBe('completed')->and($export->processed)->toBe(4)->and($export->download_failed)->toBe(1)
        ->and($gateway->queries)->toHaveCount(2);
    Http::assertSentCount(4);
    $path = Storage::disk('local')->path($export->path);
    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CHECKCONS))->toBeTrue();
    try {
        $csv = $zip->getFromName('alt-text.csv');
        $manifest = $zip->getFromName('manifest.csv');
        expect($csv)->toContain('A front')->not->toContain('Changed after snapshot', 'Created after snapshot', 'Unmapped legacy', 'Inherited copy', 'OUTSIDE', 'INACTIVE');
        expect($manifest)->toContain('failed', 'HTTP 404')
            ->and($zip->locateName('images/image-'.$side->id.'.jpg'))->toBeFalse()
            ->and($zip->numFiles)->toBe(5);
    } finally {
        $zip->close();
    }
    expect(hash_file('sha256', $path))->toBe($export->sha256);
    Livewire::test(AltTextExportStatus::class)->assertSee('Completed')->assertSee('Download')
        ->assertSee('1 photographs could not be downloaded');
    $response = $this->get(route('image-alt-text-exports.download', $export));
    $response->assertOk();
    expect(hash('sha256', $response->streamedContent()))->toBe($export->sha256);
    Http::assertSentCount(4); // Download serves the artifact; it does not generate again.
    $count = DB::table('jobs')->count();
    (new GenerateImageAltTextExport($export->id, $export->connection, $export->queue))->handle($service);
    Http::assertSentCount(4);
    expect(DB::table('jobs')->count())->toBe($count);
    $recovered = true;
    $service->retry($this->exportUser, $export->fresh());
    queuedExportDrain($export);
    expect($export->fresh()->download_failed)->toBe(0)->and($export->fresh()->status)->toBe('completed')
        ->and($gateway->queries)->toHaveCount(2);
    Http::assertSentCount(5); // Only the failed photograph was retried; successful files and snapshot remain intact.
});

it('resumes a failed job from its immutable snapshot without redownloading successful files', function (): void {
    config()->set('alt_text_exports.batch_size', 1);
    [$product, $first] = queuedExportProduct('RESUME', 710);
    queuedExportImage($product, 2, 'Side');
    queuedExportGateway([]);
    Http::fake(['*' => Http::response('photograph', 200)]);
    $service = app(ImageAltTextExportService::class);
    $export = $service->request($this->exportUser, [], true);
    queuedExportWork();
    $this->travel(2)->seconds();
    queuedExportWork();
    expect($export->fresh()->processed)->toBe(1);
    (new GenerateImageAltTextExport($export->id, $export->connection, $export->queue))->failed(new RuntimeException('Worker interrupted'));
    expect($export->fresh()->status)->toBe('failed');
    Livewire::test(AltTextExportStatus::class)->assertSee('Failed')->assertSee('Worker interrupted')->assertSee('Retry')
        ->call('retryExport', $export->id);
    expect($export->fresh()->status)->toBe('queued');
    $service->retry($this->exportUser, $export->fresh()); // repeated click does not enqueue another retry
    queuedExportImage($product, 3, 'Not in snapshot');
    queuedExportDrain($export);
    expect($export->fresh()->status)->toBe('completed')->and($export->fresh()->total)->toBe(2);
    Http::assertSentCount(2);
});

it('records terminal failures after real queue retries and can retry after the cause is fixed', function (): void {
    [$product] = queuedExportProduct('RECOVER', 720);
    $gateway = queuedExportGateway([1 => [720]]);
    $export = app(ImageAltTextExportService::class)->request($this->exportUser, ['Missing mapping'], false);
    for ($i = 0; $i < 3; $i++) {
        queuedExportWork();
        $this->travel(200)->seconds();
    }
    expect($export->fresh()->status)->toBe('failed')->and($export->fresh()->error)->toContain('exactly one synced Shopify collection')
        ->and(DB::table('failed_jobs')->count())->toBe(1);
    queuedExportCollection($product, 'Missing mapping', 1);
    app(ImageAltTextExportService::class)->retry($this->exportUser, $export->fresh());
    queuedExportDrain($export);
    expect($export->fresh()->status)->toBe('completed')->and($gateway->queries)->toHaveCount(1);
    Http::assertNothingSent();
});

it('restricts downloads to the requesting authorised user and removes expired exports', function (): void {
    queuedExportProduct('ACCESS', 730);
    queuedExportGateway([]);
    $service = app(ImageAltTextExportService::class);
    $export = $service->request($this->exportUser, [], false);
    $this->get(route('image-alt-text-exports.download', $export))->assertStatus(409);
    queuedExportDrain($export);
    $this->get(route('image-alt-text-exports.download', $export))->assertOk();
    $other = User::factory()->create();
    $other->givePermissionTo(PermissionEnum::ProductEdit->value);
    $this->actingAs($other)->get(route('image-alt-text-exports.download', $export))->assertForbidden();
    $this->actingAs($this->exportUser);
    $this->exportUser->revokePermissionTo(PermissionEnum::ProductEdit->value);
    $this->get(route('image-alt-text-exports.download', $export))->assertForbidden();
    $this->exportUser->givePermissionTo(PermissionEnum::ProductEdit->value);
    $this->travel(25)->hours();
    $this->get(route('image-alt-text-exports.download', $export))->assertStatus(410);
    expect($service->cleanupExpired())->toBe(1)->and(ImageAltTextExport::count())->toBe(0);
    Storage::disk('local')->assertMissing($export->path);
    expect($export->items()->count())->toBe(0);
});

it('caps each worker download batch and excludes a concurrent duplicate worker', function (): void {
    [$product] = queuedExportProduct('BOUNDED', 740);
    for ($position = 2; $position <= 25; $position++) {
        queuedExportImage($product, $position, 'Photo '.$position);
    }
    queuedExportGateway([]);
    Http::fake(['*' => Http::response('photograph', 200)]);
    config()->set('alt_text_exports.batch_size', 999); // Hard cap still applies.
    $export = app(ImageAltTextExportService::class)->request($this->exportUser, [], true);
    $lock = Cache::lock('alt-text-export:'.$export->id, 540);
    expect($lock->get())->toBeTrue();
    queuedExportWork();
    expect($export->fresh()->snapshot_at)->toBeNull();
    Http::assertNothingSent();
    $lock->release();
    $this->travel(16)->seconds();
    queuedExportWork();
    expect($export->fresh()->total)->toBe(25);
    $this->travel(2)->seconds();
    queuedExportWork();
    expect($export->fresh()->processed)->toBe(10)->and($export->fresh()->status)->toBe('processing');
    Http::assertSentCount(10);
});

it('never completes a corrupt stored artifact and removes partial files before retry', function (): void {
    queuedExportProduct('STORAGE-CHECK', 750);
    queuedExportGateway([]);
    $disk = Storage::disk('local');
    $proxy = Mockery::mock(FilesystemAdapter::class);
    $proxy->shouldReceive('put')->andReturnUsing(fn (...$args) => $disk->put(...$args));
    $proxy->shouldReceive('readStream')->andReturnUsing(function () {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'corrupt');
        rewind($stream);

        return $stream;
    });
    $proxy->shouldReceive('delete')->andReturnUsing(fn (...$args) => $disk->delete(...$args));
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
    $export = app(ImageAltTextExportService::class)->request($this->exportUser, [], false);
    queuedExportWork();
    expect($export->fresh()->status)->toBe('processing')->and($export->fresh()->error)->toContain('checksum verification failed');
    $disk->assertMissing('alt-text-exports/'.$export->id.'/alt-text.csv');
    expect(is_dir(storage_path('app/private/alt-text-export-work/'.$export->id)))->toBeFalse();
    Http::assertNothingSent();
});

function queuedExportProduct(string $name, int $gid, bool $stack = false): array
{
    $user = User::factory()->create();
    $import = Import::create(['filename' => 'export.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'title' => $name,
        'handle' => strtolower($name).'-'.$gid, 'shopify_id' => 'gid://shopify/Product/'.$gid, 'status' => 'active', 'is_bundle' => $stack]));

    return [$product, queuedExportImage($product, 1, $name.' front')];
}

function queuedExportImage(Product $product, int $position, string $alt): Image
{
    return Image::withoutEvents(fn () => Image::create(['product_id' => $product->id, 'position' => $position,
        'alt_text' => $alt, 'sync_state' => Image::SYNC_STATE_SYNCED, 'src' => 'https://cdn.example.test/'.$product->id.'-'.$position.'.jpg']));
}

function queuedExportCollection(Product $product, string $title, int $gid): void
{
    ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create(['import_id' => $product->import_id,
        'title' => $title, 'handle' => Str::slug($title), 'shopify_id' => 'gid://shopify/Collection/'.$gid]));
}

function queuedExportGateway(array $collections): object
{
    $gateway = new class($collections) implements ShopifyGraphqlGateway
    {
        public array $queries = [];

        public function __construct(private array $collections) {}

        public function graphql(string $query, array $variables = []): array
        {
            if (str_contains($query, 'mutation')) {
                throw new RuntimeException('An export must never mutate Shopify.');
            }
            $this->queries[] = $query;
            $gid = (int) basename($variables['id']);

            return ['collection' => ['products' => ['nodes' => array_map(fn ($id) => ['id' => 'gid://shopify/Product/'.$id, 'status' => 'ACTIVE'], $this->collections[$gid] ?? []),
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]];
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    return $gateway;
}

function queuedExportWork(): void
{
    // Executes Laravel's real Worker against its database queue, not Bus::fake or the sync driver.
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0, '--tries' => 3]);
}

function queuedExportDrain(ImageAltTextExport $export): void
{
    for ($i = 0; $i < 20 && ! in_array($export->fresh()->status, ['completed', 'failed'], true); $i++) {
        test()->travel(2)->seconds();
        queuedExportWork();
    }
}
