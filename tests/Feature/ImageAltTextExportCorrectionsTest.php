<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\PermissionEnum;
use App\Filament\Resources\ProductResource\Widgets\AltTextExportStatus;
use App\Models\Image;
use App\Models\ImageAltTextExport;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageAltTextExportService;
use App\Services\ShopifyCollectionMembership;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    config()->set('alt_text_exports.collection_retry_ms', [0, 0]);
    Http::preventStrayRequests();
    Permission::findOrCreate(PermissionEnum::ProductEdit->value);
    $this->exportUser = User::factory()->create();
    $this->exportUser->givePermissionTo(PermissionEnum::ProductEdit->value);
    $this->actingAs($this->exportUser);
});

it('exports one fully resolved collection', function (): void {
    [$product, $image] = correctionExportProduct('ONE', 801);
    correctionExportCollection($product, 'One', 1);
    $gateway = correctionExportGateway([
        'gid://shopify/Collection/1' => [correctionExportPage([801])],
    ]);

    $export = correctionExportFinish(['One'], false);

    expect($export->status)->toBe('completed')
        ->and($export->items()->pluck('image_id')->all())->toBe([$image->id])
        ->and($export->report['resolved_collections'])->toBe(['gid://shopify/Collection/1'])
        ->and($export->report['skipped_collections'])->toBe([])
        ->and($gateway->calls)->toBe(1);
    expect(Storage::disk('local')->get($export->path))->toContain((string) $image->id);
});

it('deduplicates products after every selected collection is fully paginated', function (): void {
    $first = 'gid://shopify/Collection/11';
    $second = 'gid://shopify/Collection/12';
    $gateway = correctionExportGateway([
        $first => [correctionExportPage([11], true, 'page-2'), correctionExportPage([12])],
        $second => [correctionExportPage([11, 13])],
    ]);

    $result = (new ShopifyCollectionMembership($gateway))->resolveForExport([$first, $second, $first]);

    expect($result['product_gids'])->toBe([
        'gid://shopify/Product/11',
        'gid://shopify/Product/12',
        'gid://shopify/Product/13',
    ])->and($result['skipped'])->toBe([])
        ->and($result['resolved'])->toBe([$first, $second])
        ->and($gateway->reads)->toBe([[$first, null], [$first, 'page-2'], [$second, null]]);
});

it('skips one unavailable collection when another collection resolves', function (): void {
    [$product, $image] = correctionExportProduct('KEPT', 802);
    correctionExportCollection($product, 'Kept', 21);
    correctionExportCollection($product, 'Missing', 305509499016);
    $missing = 'gid://shopify/Collection/305509499016';
    $gateway = correctionExportGateway([
        'gid://shopify/Collection/21' => [correctionExportPage([802])],
        $missing => [['collection' => null]],
    ]);

    $export = correctionExportFinish(['Kept', 'Missing'], false);

    expect($export->status)->toBe('partial')
        ->and($export->items()->pluck('image_id')->all())->toBe([$image->id])
        ->and($export->report['skipped_collections'])->toBe([[
            'gid' => $missing,
            'reason' => 'Shopify collection '.$missing.' could not be read.',
        ]])
        ->and($gateway->calls)->toBe(2);
    expect(Storage::disk('local')->get($export->path))->toContain((string) $image->id)->not->toContain('should-not-exist');
    Livewire::test(AltTextExportStatus::class)
        ->assertSee('Partial')
        ->assertSee('Download')
        ->assertSee($missing)
        ->assertSee('could not be read');
});

it('fails when every selected collection is unavailable and does not export the catalogue', function (): void {
    [$product, $image] = correctionExportProduct('VISIBLE', 803);
    $blank = correctionExportProduct('BLANK', '')[1];
    correctionExportCollection($product, 'First missing', 31);
    correctionExportCollection($product, 'Second missing', 305509499016);
    correctionExportGateway([
        'gid://shopify/Collection/31' => [['collection' => null]],
        'gid://shopify/Collection/305509499016' => [['collection' => null]],
    ]);

    $export = app(ImageAltTextExportService::class)->request($this->exportUser, ['First missing', 'Second missing'], false);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        correctionExportWork();
        $this->travel(200)->seconds();
    }

    expect($export->fresh()->status)->toBe('failed')
        ->and($export->fresh()->error)->toContain('No selected Shopify collection could be resolved')
        ->and($export->fresh()->error)->toContain('gid://shopify/Collection/305509499016')
        ->and($export->fresh()->path)->toBeNull()
        ->and($export->fresh()->items()->pluck('image_id')->all())->toBe([]);
    Storage::disk('local')->assertMissing('alt-text-exports/'.$export->id.'/alt-text.csv');
});

it('retries a transient collection failure and does not retry a persistent one', function (): void {
    $recoverable = 'gid://shopify/Collection/41';
    $gateway = correctionExportGateway([
        $recoverable => [
            new RuntimeException('Shopify is rate limiting requests.'),
            new RuntimeException('Shopify request timed out.'),
            correctionExportPage([41]),
        ],
    ]);
    $result = (new ShopifyCollectionMembership($gateway))->resolveForExport([$recoverable]);
    expect($result['product_gids'])->toBe(['gid://shopify/Product/41'])
        ->and($result['skipped'])->toBe([])
        ->and($gateway->calls)->toBe(3);

    $persistent = 'gid://shopify/Collection/42';
    $valid = 'gid://shopify/Collection/43';
    $gateway = correctionExportGateway([
        $persistent => [new RuntimeException('Shopify collection '.$persistent.' could not be read.')],
        $valid => [correctionExportPage([43])],
    ]);
    $result = (new ShopifyCollectionMembership($gateway))->resolveForExport([$persistent, $valid]);
    expect($result['product_gids'])->toBe(['gid://shopify/Product/43'])
        ->and($result['skipped'][0]['gid'])->toBe($persistent)
        ->and($gateway->calls)->toBe(2);

    $only = correctionExportGateway([
        $persistent => [new RuntimeException('Shopify collection '.$persistent.' could not be read.')],
    ]);
    expect(fn () => (new ShopifyCollectionMembership($only))->resolveForExport([$persistent]))
        ->toThrow(RuntimeException::class, 'No selected Shopify collection could be resolved');
    expect($only->calls)->toBe(1);
});

it('does not merge an incomplete collection page with a successful collection', function (): void {
    $broken = 'gid://shopify/Collection/51';
    $valid = 'gid://shopify/Collection/52';
    $gateway = correctionExportGateway([
        $broken => [
            correctionExportPage([51], true, 'next'),
            new RuntimeException('Shopify collection '.$broken.' returned an incomplete product page.'),
        ],
        $valid => [correctionExportPage([52])],
    ]);

    $result = (new ShopifyCollectionMembership($gateway))->resolveForExport([$broken, $valid]);

    expect($result['product_gids'])->toBe(['gid://shopify/Product/52'])
        ->and($result['skipped'][0]['gid'])->toBe($broken)
        ->and($gateway->reads[1])->toBe([$broken, 'next']);
});

it('keeps the strict collection reader failed closed for publication', function (): void {
    $gateway = correctionExportGateway([
        'gid://shopify/Collection/1' => [['collection' => null]],
    ]);

    expect(fn () => (new ShopifyCollectionMembership($gateway))->activeProductGids(['gid://shopify/Collection/1']))
        ->toThrow(RuntimeException::class, 'could not be read');
});

it('does not match blank or null shopify ids to an empty collection', function (): void {
    $blank = correctionExportProduct('BLANK-ROW', '')[1];
    $missing = correctionExportProduct('NULL-ROW', null)[1];
    $real = correctionExportProduct('REAL-ROW', 'gid://shopify/Product/61')[1];
    $rows = iterator_to_array(app(ImageAltTextBackfillService::class)->snapshotRows([]));
    expect(array_column($rows, 0))->not->toContain((string) $blank->id, (string) $missing->id, (string) $real->id);

    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            throw new RuntimeException('The empty-membership export must not read Shopify again.');
        }
    };
    app()->instance(ShopifyCollectionMembership::class, new class($gateway) extends ShopifyCollectionMembership
    {
        public function activeProductGids(array $collectionGids): array
        {
            return [];
        }
    });
    $response = app(ImageAltTextBackfillService::class)->export(['gid://shopify/Collection/61'])['response'];
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();
    expect(str_contains($csv, 'BLANK-ROW'))->toBeFalse()
        ->and(str_contains($csv, 'NULL-ROW'))->toBeFalse()
        ->and(str_contains($csv, 'REAL-ROW'))->toBeFalse();
});

it('exports every eligible normal and component image and only the stack-owned position one image', function (): void {
    [$normal, $front] = correctionExportProduct('NORMAL', 71);
    $second = correctionExportImage($normal, 2, 'Normal side');
    correctionExportImage($normal, 3, 'Hidden')->update(['is_duplicate_hidden' => true]);
    [$component, $componentFront] = correctionExportProduct('COMPONENT', 72);
    $componentSide = correctionExportImage($component, 2, 'Component side');
    [$stack, $owned] = correctionExportProduct('STACK', 73, true);
    $owned->update(['ownership' => 'stack_owned']);
    $extra = correctionExportImage($stack, 2, 'Stack owned later');
    $extra->update(['ownership' => 'stack_owned']);
    $inherited = correctionExportImage($stack, 3, 'Inherited stack');
    $inherited->update(['ownership' => 'component_inherited', 'source_image_id' => $componentFront->id]);
    $unresolved = correctionExportImage($stack, 1, 'Unresolved stack');

    $ids = array_map(intval(...), array_column(iterator_to_array(app(ImageAltTextBackfillService::class)->snapshotRows(null)), 0));

    expect($ids)->toBe([$front->id, $second->id, $componentFront->id, $componentSide->id, $owned->id])
        ->and($ids)->not->toContain($extra->id, $inherited->id, $unresolved->id);
});

it('keeps one snapshot for a partial csv and zip when a download fails', function (): void {
    [$product, $good] = correctionExportProduct('PARTIAL', 81);
    $failed = correctionExportImage($product, 2, 'Missing photo');
    [$stack, $owned] = correctionExportProduct('PARTIAL-STACK', 82, true);
    $owned->update(['ownership' => 'stack_owned']);
    $excluded = correctionExportImage($stack, 2, 'Excluded stack');
    $excluded->update(['ownership' => 'stack_owned']);
    correctionExportCollection($product, 'Partial collection', 81);
    correctionExportCollection($product, 'Unread collection', 82);
    correctionExportGateway([
        'gid://shopify/Collection/81' => [correctionExportPage([81, 82, 999])],
        'gid://shopify/Collection/82' => [['collection' => null]],
    ]);
    $recovered = false;
    Http::fake(function ($request) use ($failed, &$recovered) {
        $missing = ! $recovered && $request->url() === $failed->src;

        return Http::response($missing ? 'nope' : 'photograph', $missing ? 404 : 200);
    });

    $csvExport = correctionExportFinish(['Partial collection', 'Unread collection'], false);
    $csvIds = $csvExport->items()->pluck('image_id')->all();
    expect($csvExport->status)->toBe('partial')
        ->and($csvIds)->toBe([$good->id, $failed->id, $owned->id])
        ->and($csvExport->report['unmapped_products'])->toBe(1)
        ->and(Storage::disk('local')->get($csvExport->path))->toContain((string) $good->id, (string) $owned->id)
        ->and(Storage::disk('local')->get($csvExport->path))->not->toContain('Excluded stack');

    correctionExportGateway([
        'gid://shopify/Collection/81' => [correctionExportPage([81, 82, 999])],
        'gid://shopify/Collection/82' => [['collection' => null]],
    ]);
    $zipExport = app(ImageAltTextExportService::class)->request($this->exportUser, ['Partial collection', 'Unread collection'], true);
    correctionExportDrain($zipExport);
    $zipExport->refresh();
    $zip = new ZipArchive;
    expect($zipExport->status)->toBe('partial')
        ->and($zipExport->download_failed)->toBe(1)
        ->and($zipExport->items()->pluck('image_id')->all())->toBe($csvIds)
        ->and($zip->open(Storage::disk('local')->path($zipExport->path), ZipArchive::CHECKCONS))->toBeTrue();
    try {
        $zipCsv = $zip->getFromName('alt-text.csv');
        expect($zipCsv)->toContain((string) $good->id, (string) $failed->id, (string) $owned->id)
            ->and($zipCsv)->not->toContain('Excluded stack')
            ->and($zip->getFromName('exceptions.csv'))->toContain('skipped_collection', 'gid://shopify/Collection/82', 'image_download', 'HTTP 404', 'unmapped_products')
            ->and($zip->locateName('images/image-'.$good->id.'.jpg'))->not->toBeFalse()
            ->and($zip->locateName('images/image-'.$failed->id.'.jpg'))->toBeFalse()
            ->and($zip->numFiles)->toBe(5);
    } finally {
        $zip->close();
    }
    $this->get(route('image-alt-text-exports.download', $zipExport))->assertOk();
    Livewire::test(AltTextExportStatus::class)
        ->assertSee('1 Shopify products in the selected collections have no active CMS product.')
        ->assertSee('gid://shopify/Collection/82');

    $before = $zipExport->items()->orderBy('id')->pluck('image_id')->all();
    $recovered = true;
    app(ImageAltTextExportService::class)->retry($this->exportUser, $zipExport->fresh());
    correctionExportDrain($zipExport);
    expect($zipExport->fresh()->status)->toBe('partial')
        ->and($zipExport->fresh()->download_failed)->toBe(0)
        ->and($zipExport->fresh()->items()->orderBy('id')->pluck('image_id')->all())->toBe($before)
        ->and($zipExport->fresh()->items()->count())->toBe(3);
});

it('reports unmapped shopify products without failing the export', function (): void {
    [$product] = correctionExportProduct('MAPPED', 91);
    $draft = correctionExportProduct('DRAFT', 'gid://shopify/Product/92')[0];
    Product::withoutEvents(fn () => $draft->update(['status' => 'draft']));
    correctionExportCollection($product, 'Mapped', 91);
    correctionExportGateway([
        'gid://shopify/Collection/91' => [correctionExportPage([91, 92, 93])],
    ]);

    $export = correctionExportFinish(['Mapped'], false);

    expect($export->status)->toBe('completed')
        ->and($export->report['unmapped_products'])->toBe(2)
        ->and($export->error)->toBeNull();
    Livewire::test(AltTextExportStatus::class)
        ->assertSee('Completed')
        ->assertSee('2 Shopify products in the selected collections have no active CMS product.');
});

it('rejects a stored export whose bytes do not match its checksum', function (): void {
    [$product] = correctionExportProduct('CHECK', 95);
    correctionExportCollection($product, 'Check', 95);
    correctionExportGateway([
        'gid://shopify/Collection/95' => [correctionExportPage([95])],
    ]);
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
    $export = app(ImageAltTextExportService::class)->request($this->exportUser, ['Check'], false);
    correctionExportWork();

    expect($export->fresh()->status)->not->toBe('completed')
        ->and($export->fresh()->status)->not->toBe('partial')
        ->and($export->fresh()->error)->toContain('checksum verification failed')
        ->and($export->fresh()->path)->toBeNull();
    $disk->assertMissing('alt-text-exports/'.$export->id.'/alt-text.csv');
});

function correctionExportProduct(string $name, int|string|null $gid, bool $stack = false): array
{
    $user = User::factory()->create();
    $import = Import::create(['filename' => 'export.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id]);
    $shopifyId = is_int($gid) ? 'gid://shopify/Product/'.$gid : $gid;
    $product = Product::withoutEvents(fn () => Product::create([
        'import_id' => $import->id,
        'title' => $name,
        'handle' => Str::slug($name).'-'.$user->id,
        'shopify_id' => $shopifyId,
        'status' => 'active',
        'is_bundle' => $stack,
    ]));

    return [$product, correctionExportImage($product, 1, $name.' front')];
}

function correctionExportImage(Product $product, int $position, string $alt): Image
{
    return Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => $position,
        'alt_text' => $alt,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'src' => 'https://cdn.example.test/'.$product->id.'-'.$position.'.jpg',
    ]));
}

function correctionExportCollection(Product $product, string $title, int $gid): void
{
    ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
        'import_id' => $product->import_id,
        'title' => $title,
        'handle' => Str::slug($title),
        'shopify_id' => 'gid://shopify/Collection/'.$gid,
    ]));
}

function correctionExportPage(array $productIds, bool $hasNextPage = false, ?string $cursor = null): array
{
    return ['collection' => ['products' => [
        'nodes' => array_map(fn ($id): array => [
            'id' => str_starts_with((string) $id, 'gid://') ? (string) $id : 'gid://shopify/Product/'.$id,
            'status' => 'ACTIVE',
        ], $productIds),
        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor],
    ]]];
}

function correctionExportGateway(array $plans): object
{
    $gateway = new class($plans) implements ShopifyGraphqlGateway
    {
        public int $calls = 0;

        public array $reads = [];

        public function __construct(private array $plans) {}

        public function graphql(string $query, array $variables = []): array
        {
            if (str_contains($query, 'mutation')) {
                throw new RuntimeException('An export must never mutate Shopify.');
            }
            $this->calls++;
            $gid = (string) ($variables['id'] ?? '');
            $cursor = $variables['cursor'] ?? null;
            $this->reads[] = [$gid, $cursor];
            $outcome = array_shift($this->plans[$gid]);
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    return $gateway;
}

function correctionExportFinish(array $collections, bool $includeImages): ImageAltTextExport
{
    $export = app(ImageAltTextExportService::class)->request(test()->exportUser, $collections, $includeImages);
    correctionExportDrain($export);

    return $export->fresh();
}

function correctionExportWork(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0, '--tries' => 3]);
}

function correctionExportDrain(ImageAltTextExport $export): void
{
    for ($i = 0; $i < 20 && ! in_array($export->fresh()->status, ['completed', 'partial', 'failed'], true); $i++) {
        test()->travel(2)->seconds();
        correctionExportWork();
    }
}
