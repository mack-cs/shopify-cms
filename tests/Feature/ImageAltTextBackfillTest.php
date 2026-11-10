<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Filament\Pages\ImportShopifyProductImages;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Jobs\GenerateImageAltTextExport;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Jobs\SendProductPartialApprovalSlackReminderJob;
use App\Models\Image;
use App\Models\ImageAltTextExport;
use App\Models\ImageAsset;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyCollection;
use App\Models\ShopifyImageImportBatch;
use App\Models\User;
use App\Models\Variant;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageAltTextShopifyWriter;
use App\Services\Normalizer;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyCollectionMembership;
use App\Services\ShopifyImageImportService;
use App\Services\StackImageSourceRepairService;
use Filament\Actions\ActionGroup;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('exports every eligible image for active products and leaves out draft products and hidden images', function (): void {
    $product = altTextProduct('LA0550');
    $first = altTextImage($product, 1, null);
    $second = altTextImage($product, 2, 'Already described');
    foreach ([Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED] as $state) {
        altTextImage($product, 3, 'Excluded '.$state)->update(['sync_state' => $state]);
    }
    altTextImage($product, 4, 'Excluded hidden')->update(['is_duplicate_hidden' => true]);
    foreach (['draft', 'archived'] as $status) {
        $inactive = altTextProduct('EXCLUDED-'.$status);
        Product::withoutEvents(fn () => $inactive->update(['status' => $status]));
        altTextImage($inactive, 1, 'Excluded '.$status);
    }

    $csv = altTextCsv(app(ImageAltTextBackfillService::class)->downloadActive());

    expect($csv)->toContain('image_id,product_id,shopify_product_id,shopify_media_id,sku')
        ->and($csv)->toContain((string) $first->id)
        ->and($csv)->toContain((string) $second->id)
        ->and($csv)->toContain('LA0550')
        ->and($csv)->toContain('Already described')
        ->and($csv)->not->toContain('Excluded ');
});

it('exports a component product images and only the stack owned first image', function (): void {
    $component = altTextProduct('COMP-1', 'gid://shopify/Product/11');
    $componentFirst = altTextImage($component, 1, 'Component front');
    $componentSecond = altTextImage($component, 2, 'Component side');
    $stack = altTextProduct('STACK-1', 'gid://shopify/Product/21', true);
    $owned = altTextImage($stack, 1, 'Stack lifestyle');
    $inherited = altTextImage($stack, 2, 'Old stack copy');
    $inherited->update(['source_image_id' => $componentFirst->id]);
    altTextDraft($stack, [$component->id]);

    $csv = altTextCsv(app(ImageAltTextBackfillService::class)->downloadActive());

    expect($csv)->toContain((string) $componentFirst->id)
        ->and($csv)->toContain((string) $componentSecond->id)
        ->and($csv)->toContain((string) $owned->id)
        ->and($csv)->not->toContain((string) $inherited->id);
});

it('filters active products by shopify collection membership and does not duplicate overlapping products', function (): void {
    $inside = altTextProduct('IN-1', 'gid://shopify/Product/31');
    $image = altTextImage($inside, 1, 'Inside');
    $outside = altTextProduct('OUT-1', 'gid://shopify/Product/32');
    altTextImage($outside, 1, 'Outside');
    app()->instance(ShopifyCollectionMembership::class, new class(app(ShopifyGraphqlGateway::class)) extends ShopifyCollectionMembership
    {
        public function activeProductGids(array $collectionGids): array
        {
            return ['gid://shopify/Product/31', 'gid://shopify/Product/31'];
        }
    });

    $csv = altTextCsv(app(ImageAltTextBackfillService::class)->export(['gid://shopify/Collection/1', 'gid://shopify/Collection/2'])['response']);

    $ids = altTextImageIds($csv);

    expect($ids)->toBe([$image->id])
        ->and($csv)->not->toContain('OUT-1');
});

it('reports a stack component that is outside the selected collection without exporting it', function (): void {
    $component = altTextProduct('COMP-OUT', 'gid://shopify/Product/41');
    $componentImage = altTextImage($component, 1, 'Component');
    $stack = altTextProduct('STACK-IN', 'gid://shopify/Product/42', true);
    altTextImage($stack, 1, 'Lifestyle');
    altTextDraft($stack, [$component->id]);
    app()->instance(ShopifyCollectionMembership::class, new class(app(ShopifyGraphqlGateway::class)) extends ShopifyCollectionMembership
    {
        public function activeProductGids(array $collectionGids): array
        {
            return ['gid://shopify/Product/42'];
        }
    });

    $export = app(ImageAltTextBackfillService::class)->export(['gid://shopify/Collection/9']);
    $csv = altTextCsv($export['response']);

    expect(altTextImageIds($csv))->not->toContain($componentImage->id)
        ->and($export['exceptions'][0]['kind'])->toBe('component_dependency')
        ->and($export['exceptions'][0]['message'])->toContain('COMP-OUT');
});

it('updates the image named by image id and does not use the position column', function (): void {
    altTextGateway();
    $product = altTextProduct('LA0550', 'gid://shopify/Product/51');
    $first = altTextImage($product, 1, null, 'gid://shopify/MediaImage/501');
    $second = altTextImage($product, 2, 'Keep me', 'gid://shopify/MediaImage/502');

    $result = app(ImageAltTextBackfillService::class)->import(<<<CSV
image_id,product_id,shopify_product_id,shopify_media_id,sku,product_title,position,image_url,alt_text
{$first->id},{$product->id},gid://shopify/Product/51,gid://shopify/MediaImage/501,LA0550,LA0550,9,https://cdn.shopify.com/1.jpg,The Art of Temptation Necklace
{$first->id},{$product->id},gid://shopify/Product/51,gid://shopify/MediaImage/501,LA0550,LA0550,1,https://cdn.shopify.com/1.jpg,
CSV, true);

    expect($result['updated'])->toBe(1)
        ->and($result['skipped_blank'])->toBe(1)
        ->and($first->fresh()->alt_text)->toBe('The Art of Temptation Necklace')
        ->and($first->fresh()->position)->toBe(1)
        ->and($first->fresh()->local_dirty)->toBeFalse()
        ->and($first->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($first->fresh()->shopify_alt_pending)->toBeFalse()
        ->and($second->fresh()->alt_text)->toBe('Keep me');
});

it('previews an import without changing alt text', function (): void {
    $gateway = altTextGateway();
    $product = altTextProduct('LA0551');
    $image = altTextImage($product, 1, 'Old');

    $result = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$image->id},New description\n", false);

    expect($result['applied'])->toBeFalse()
        ->and($result['updated'])->toBe(1)
        ->and($image->fresh()->alt_text)->toBe('Old')
        ->and($gateway->updates)->toBe(0);
});

it('updates a stack owned image and rejects an inherited stack image', function (): void {
    altTextGateway();
    $component = altTextProduct('COMP-2');
    $source = altTextImage($component, 1, 'From component');
    $stack = altTextProduct('STACK-2', 'gid://shopify/Product/61', true);
    $owned = altTextImage($stack, 1, 'Old lifestyle', 'gid://shopify/MediaImage/601');
    $copy = altTextImage($stack, 2, 'Copied', 'gid://shopify/MediaImage/602');
    $copy->update(['source_image_id' => $source->id]);

    $result = app(ImageAltTextBackfillService::class)->import(<<<CSV
image_id,alt_text
{$owned->id},Approved stack lifestyle
{$copy->id},Should not stick
CSV, true);

    expect($result['updated'])->toBe(1)
        ->and($result['excluded'])->toBe(1)
        ->and($owned->fresh()->alt_text)->toBe('Approved stack lifestyle')
        ->and($copy->fresh()->alt_text)->toBe('Copied')
        ->and($copy->fresh()->position)->toBe(2);
});

it('propagates component alt text to verified stack copies without reuploading', function (): void {
    $gateway = altTextGateway();
    $component = altTextProduct('COMP-3', 'gid://shopify/Product/71');
    $source = altTextImage($component, 1, 'Old component', 'gid://shopify/MediaImage/701');
    $stack = altTextProduct('STACK-3', 'gid://shopify/Product/72', true);
    $copy = altTextImage($stack, 2, 'Old copy', 'gid://shopify/MediaImage/702');
    $copy->update(['source_image_id' => $source->id]);
    altTextDraft($stack, [$component->id]);

    $imported = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$source->id},Approved component alt\n", true);

    expect($imported['propagated'])->toBe(0)
        ->and($imported['shopify_failed'])->toBe([])
        ->and($source->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($copy->fresh()->alt_text)->toBe('Old copy')
        ->and($gateway->updates)->toBe(0)
        ->and($gateway->queries)->toBe([]);

    altTextApprove($source);
    expect($source->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($copy->fresh()->alt_text)->toBe('Old copy')
        ->and($gateway->updates)->toBe(0);

    $result = app(ImageAltTextBackfillService::class)->publishIds([$source->id]);

    expect($result['propagated'])->toBe(1)
        ->and($result['published'])->toBe(2)
        ->and($source->fresh()->alt_text)->toBe('Approved component alt')
        ->and($source->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($copy->fresh()->alt_text)->toBe('Approved component alt')
        ->and($copy->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($copy->fresh()->position)->toBe(2)
        ->and($gateway->updates)->toBe(2)
        ->and(collect($gateway->queries)->filter(fn (string $query): bool => str_contains($query, 'fileUpdate')))->toHaveCount(2)
        ->and(collect($gateway->queries)->first(fn (string $query): bool => str_contains($query, 'fileUpdate')))->not->toContain('productCreateMedia')
        ->and(collect($gateway->queries)->first(fn (string $query): bool => str_contains($query, 'fileUpdate')))->not->toContain('originalSource');
});

it('is idempotent and retries a failed shopify alt update', function (): void {
    $gateway = altTextGateway(fail: true);
    $product = altTextProduct('LA0552', 'gid://shopify/Product/81');
    $image = altTextImage($product, 1, null, 'gid://shopify/MediaImage/801');
    $csv = "image_id,alt_text\n{$image->id},Stable description\n";
    $service = app(ImageAltTextBackfillService::class);

    $first = $service->import($csv, true);
    expect($first['shopify_failed'])->toBe([])
        ->and($gateway->updates)->toBe(0)
        ->and($image->fresh()->alt_text)->toBe('Stable description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    altTextApprove($image);
    $failedPush = app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($failedPush['shopify_failed'])->toHaveCount(1)
        ->and($image->fresh()->alt_text)->toBe('Stable description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_FAILED)
        ->and($image->fresh()->shopify_alt_pending)->toBeTrue();

    app()->instance(ShopifyGraphqlGateway::class, altTextGateway());
    $second = app(ImageAltTextBackfillService::class)->retryIds([$image->id]);

    expect($second['shopify_failed'])->toBe([])
        ->and($image->fresh()->alt_text)->toBe('Stable description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($image->fresh()->shopify_alt_pending)->toBeFalse();

    $third = app(ImageAltTextBackfillService::class)->import($csv, true);
    expect($third['unchanged'])->toBe(1)
        ->and($third['updated'])->toBe(0);
});

it('reports stale and ambiguous stack image mappings without linking them', function (): void {
    $component = altTextProduct('COMP-4');
    $source = altTextImage($component, 1, 'Component');
    $asset = ImageAsset::query()->create([
        'sha256' => str_repeat('a', 64),
        'storage_disk' => 'public',
        'storage_path' => 'missing.jpg',
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
    $source->update(['image_asset_id' => $asset->id]);
    $stack = altTextProduct('STACK-4', 'gid://shopify/Product/91', true);
    altTextImage($stack, 1, 'Lifestyle');
    $ambiguous = altTextImage($stack, 2, 'Maybe copied');
    $ambiguous->update(['image_asset_id' => $asset->id]);
    $unrelated = altTextProduct('OTHER-4');
    $unrelatedImage = altTextImage($unrelated, 1, 'Not a component');
    $stale = altTextImage($stack, 3, 'Broken link');
    $stale->update(['source_image_id' => $unrelatedImage->id]);
    altTextDraft($stack, [$component->id]);

    $exceptions = app(ImageAltTextBackfillService::class)->export()['exceptions'];
    $kinds = collect($exceptions)->pluck('kind')->all();

    expect($kinds)->toContain('ambiguous_legacy', 'stale_source')
        ->and($ambiguous->fresh()->source_image_id)->toBeNull()
        ->and($stale->fresh()->alt_text)->toBe('Broken link');
});

it('does not let a later shopify product import replace approved alt text', function (): void {
    altTextGateway();
    $product = altTextProduct('LA0553', 'gid://shopify/Product/101');
    $image = altTextImage($product, 1, 'Shopify old', 'gid://shopify/MediaImage/1001');
    app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$image->id},Approved locally\n", true);

    $method = new ReflectionMethod(Normalizer::class, 'syncInboundImage');
    $method->invoke(app(Normalizer::class), $image->fresh(), [
        'product_id' => $product->id,
        'shopify_id' => $image->shopify_id,
        'src' => $image->src,
        'position' => 1,
        'alt_text' => 'Shopify old',
    ], now());

    expect($image->fresh()->alt_text)->toBe('Approved locally');
});

it('copies component image alt text onto a rebuilt stack image and keeps the stack image', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('component.jpg', 'image-bytes');
    $asset = ImageAsset::query()->create([
        'sha256' => str_repeat('b', 64),
        'storage_disk' => 'public',
        'storage_path' => 'component.jpg',
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
    $component = altTextProduct('COMP-REBUILD', 'gid://shopify/Product/111');
    $component->update(['title' => 'Component product title']);
    $source = altTextImage($component, 1, 'Approved component description', 'gid://shopify/MediaImage/1101');
    $source->update(['image_asset_id' => $asset->id, 'approved_filename' => 'component.jpg']);
    $stack = altTextProduct('STACK-REBUILD', 'gid://shopify/Product/112', true);
    $owned = altTextImage($stack, 1, 'Keep this lifestyle');
    altTextDraft($stack, [$component->id]);

    $method = new ReflectionMethod(ShopifyImageImportService::class, 'rebuildStackImages');
    $result = $method->invoke(app(ShopifyImageImportService::class), $stack->fresh());
    $copy = Image::query()->where('product_id', $stack->id)->where('source_image_id', $source->id)->first();

    expect($result['rebuilt'])->toBeTrue()
        ->and($owned->fresh()->alt_text)->toBe('Keep this lifestyle')
        ->and($owned->fresh()->position)->toBe(1)
        ->and($copy)->not->toBeNull()
        ->and($copy->alt_text)->toBe('Approved component description')
        ->and($copy->alt_text)->not->toBe('Component product title');
});

it('clears alt text when a photograph is replaced', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('replacement.jpg', 'new-bytes');
    $old = ImageAsset::query()->create([
        'sha256' => str_repeat('c', 64),
        'storage_disk' => 'public',
        'storage_path' => 'old.jpg',
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
    $new = ImageAsset::query()->create([
        'sha256' => str_repeat('d', 64),
        'storage_disk' => 'public',
        'storage_path' => 'replacement.jpg',
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
    $product = altTextProduct('LA0554');
    $image = altTextImage($product, 1, 'Describes the old photograph');
    $image->update(['image_asset_id' => $old->id]);

    $method = new ReflectionMethod(ShopifyImageImportService::class, 'prepareImageForImport');
    $method->invoke(app(ShopifyImageImportService::class), $product, $new, 'replacement.jpg', 1);

    expect($image->fresh()->alt_text)->toBeNull()
        ->and($image->fresh()->position)->toBe(1)
        ->and($image->fresh()->image_asset_id)->toBe($new->id);
});

function altTextGateway(bool $fail = false): object
{
    $gateway = new class($fail) implements ShopifyGraphqlGateway
    {
        public int $updates = 0;

        public array $queries = [];

        public array $variables = [];

        public array $resolvedMedia = [];

        public array $extraMedia = [];

        public function __construct(private bool $fail) {}

        public function graphql(string $query, array $variables = []): array
        {
            $this->queries[] = $query;
            $this->variables[] = $variables;
            if (str_contains($query, 'ProductAltMedia')) {
                $product = Product::query()->where('shopify_id', $variables['id'] ?? '')->first();
                $media = [];
                $legacy = [];
                if ($product instanceof Product) {
                    foreach ($product->allImages()->get() as $image) {
                        $id = trim((string) $image->shopify_id);
                        $url = (string) $image->src;
                        if (preg_match('~^gid://shopify/MediaImage/\d+$~', $id) === 1) {
                            $media[] = ['id' => $id, 'image' => ['url' => $url]];
                        }
                        if (preg_match('~^gid://shopify/ProductImage/\d+$~', $id) === 1) {
                            $legacy[] = ['id' => $id, 'url' => $url];
                            if (isset($this->resolvedMedia[$id])) {
                                $media[] = ['id' => $this->resolvedMedia[$id], 'image' => ['url' => $url]];
                            }
                        }
                    }
                }
                foreach ($this->extraMedia as $node) {
                    $media[] = $node;
                }

                return ['product' => ['media' => ['nodes' => $media], 'images' => ['nodes' => $legacy]]];
            }

            $this->updates++;
            if (! str_contains($query, 'mutation')) {
                throw new RuntimeException('Alt text import must not call Shopify.');
            }
            if ($this->fail) {
                return ['fileUpdate' => ['userErrors' => [['message' => 'Shopify was busy']]]];
            }

            $file = $variables['files'][0] ?? [];

            return ['fileUpdate' => [
                'files' => [[
                    'id' => $file['id'] ?? null,
                    'alt' => $file['alt'] ?? null,
                ]],
                'userErrors' => [],
            ]];
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    return $gateway;
}

function altTextImageIds(string $csv): array
{
    $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($csv)) ?: []));
    $header = str_getcsv(array_shift($lines));
    $index = array_search('image_id', $header, true);

    return array_map(fn (string $line): int => (int) (str_getcsv($line)[$index] ?? 0), $lines);
}
function altTextCsv($response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

function altTextProduct(string $sku, ?string $shopifyId = null, bool $stack = false): Product
{
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => 'alt-text.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'handle' => strtolower($sku).'-'.$user->id,
        'title' => $sku,
        'status' => 'active',
        'shopify_id' => $shopifyId,
        'is_bundle' => $stack,
        'approval_version' => 1,
    ]));
    Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));

    return $product->fresh('variants');
}

function altTextImage(Product $product, int $position, ?string $altText, ?string $mediaId = null): Image
{
    return Image::withoutEvents(fn (): Image => Image::query()->create([
        'product_id' => $product->id,
        'position' => $position,
        'alt_text' => $altText,
        'shopify_id' => $mediaId,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'src' => 'https://cdn.shopify.com/'.$product->id.'-'.$position.'.jpg',
    ]));
}

function altTextApprove(Image $image): ProductPartialApprovalRequest
{
    $product = $image->product()->first();
    $requester = User::factory()->create();
    $approver = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $approver->assignRole(RolesEnum::Admin->value);
    Bus::fake([SendProductPartialApprovalSlackReminderJob::class]);
    $service = app(ProductPartialApprovalService::class);
    $service->request(
        collect([$product]),
        $requester->id,
        [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT],
        [],
        $approver->id,
    );
    $request = ProductPartialApprovalRequest::query()
        ->where('product_id', $product->id)
        ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
        ->latest('id')
        ->first();
    expect($request)->not->toBeNull()
        ->and((int) $request->target_approver_id)->toBe($approver->id);
    $service->approveRequests(collect([$request]), $approver->id);

    return $request->fresh();
}

function altTextDraft(Product $stack, array $componentIds): void
{
    NewProductDraft::withoutEvents(fn () => NewProductDraft::query()->create([
        'title' => $stack->title,
        'handle' => $stack->handle,
        'shopify_id' => $stack->shopify_id,
        'status' => 'active',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'bundle_product_ids' => $componentIds,
    ]));
}

it('exports using applied collection filters and ignores deferred changes', function (): void {
    Queue::fake([GenerateImageAltTextExport::class]);
    $this->actingAs($user = User::factory()->create());
    Permission::findOrCreate(PermissionEnum::ProductEdit->value);
    $user->givePermissionTo(PermissionEnum::ProductEdit->value);
    $gateway = altTextGateway();
    $a = altTextProduct('APPLIED-A', 'gid://shopify/Product/510');
    $b = altTextProduct('DEFERRED-B', 'gid://shopify/Product/520');
    $ia = altTextImage($a, 1, 'A');
    altTextImage($b, 1, 'B');
    foreach (['Collection A' => 51, 'Collection B' => 52] as $title => $id) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $a->import_id, 'title' => $title, 'handle' => Str::slug($title),
            'shopify_id' => 'gid://shopify/Collection/'.$id,
        ]));
    }
    $membership = new class($gateway) extends ShopifyCollectionMembership
    {
        public array $selected = [];

        public function activeProductGids(array $collectionGids): array
        {
            $this->selected = $collectionGids;

            return ['gid://shopify/Product/510'];
        }
    };
    app()->instance(ShopifyCollectionMembership::class, $membership);
    $page = new ListProducts;
    $page->tableFilters = ['collection' => ['values' => ['Collection A']]];
    $page->tableDeferredFilters = ['collection' => ['values' => ['Collection B']]];
    $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
    expect($actions[0])->toBeInstanceOf(ActionGroup::class);
    $flat = $actions[0]->getFlatActions();
    expect(array_keys($flat))->toBe(['downloadImageAltTextTemplate', 'backfillImageAltText']);
    $flat['downloadImageAltTextTemplate']->call(['data' => ['include_images' => false]]);
    $export = ImageAltTextExport::firstOrFail();
    expect($export->criteria)->toBe(['collections' => ['Collection A']])
        ->and($export->status)->toBe('queued')
        ->and($membership->selected)->toBe([]);
    $page->tableFilters = ['collection' => ['values' => ['Collection A', 'Collection B']]];
    expect($page->appliedAltTextCollectionGids())->toBe(['gid://shopify/Collection/51', 'gid://shopify/Collection/52']);
    $page->tableFilters = [];
    expect($page->appliedAltTextCollectionGids())->toBe([]);
    $page->tableFilters = ['collection' => ['values' => ['Unknown collection']]];
    expect(fn () => $page->appliedAltTextCollectionGids())->toThrow(RuntimeException::class);
});

it('requires CSV preview of the same file before CMS save and never calls Shopify', function (): void {
    Storage::fake('local');
    $gateway = altTextGateway();
    $product = altTextProduct('PREVIEW-SAVE');
    $image = altTextImage($product, 1, 'Old');
    $this->actingAs(User::factory()->create());
    Storage::disk('local')->put('alt.csv', "image_id,alt_text\n{$image->id},Pending description\n");
    $page = new ListProducts;
    $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
    $action = $actions[0]->getFlatActions()['backfillImageAltText'];
    $call = fn (bool $save) => $action->call(['data' => ['file' => 'alt.csv'], 'arguments' => ['save' => $save],
        'action' => $action, 'backfill' => app(ImageAltTextBackfillService::class)]);
    expect(fn () => $call(true))->toThrow(Halt::class);
    expect($image->fresh()->alt_text)->toBe('Old');
    expect(fn () => $call(false))->toThrow(Halt::class);
    expect($image->fresh()->alt_text)->toBe('Old');
    Storage::disk('local')->put('alt.csv', "image_id,alt_text\n{$image->id},Changed file\n");
    expect(fn () => $call(true))->toThrow(Halt::class);
    expect(fn () => $call(false))->toThrow(Halt::class);
    $call(true);
    expect($image->fresh()->alt_text)->toBe('Changed file')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($gateway->queries)->toBe([]);
});

it('publishes approved alt through normal product sync without unrelated mutations or approval changes', function (): void {
    $gateway = altTextGateway();
    $product = altTextProduct('INTEGRATED-SYNC', 'gid://shopify/Product/530');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/MediaImage/5301');
    app(ImageAltTextBackfillService::class)->markPendingReview($image, 'Approved description');
    $updater = app(ProductShopifyUpdater::class);
    $blocked = $updater->updateApprovedProducts(collect([$product]), [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], []);
    expect($blocked['updated'])->toBe(0)->and($gateway->queries)->toBe([]);
    $approval = altTextApprove($image->fresh());
    $before = $product->fresh()->getAttributes();
    $result = $updater->updateApprovedProducts(collect([$product->fresh()]));
    expect($result['updated'])->toBe(1)->and($result['failed'])->toBe(0)
        ->and($product->fresh()->approval_version)->toBe($before['approval_version'])
        ->and($approval->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED);
    expect($gateway->updates)->toBe(1);
    $mutation = collect($gateway->variables)->last();
    expect($mutation)->toBe(['files' => [['id' => $image->shopify_id, 'alt' => 'Approved description']]]);
    $updater->updateApprovedProducts(collect([$product->fresh()]));
    expect($gateway->updates)->toBe(1);
});

it('reports approved alt failures through existing product sync and retries the saved description', function (): void {
    altTextGateway(fail: true);
    $product = altTextProduct('INTEGRATED-RETRY', 'gid://shopify/Product/540');
    $image = altTextImage($product, 1, 'Retry this', 'gid://shopify/MediaImage/5401');
    app(ImageAltTextBackfillService::class)->markPendingReview($image, 'Retry this');
    altTextApprove($image);
    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$product]), [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], []);
    expect($result['failed'])->toBe(1)->and($result['failures'][0]['details'])->toContain('Shopify was busy')
        ->and($image->fresh()->alt_text)->toBe('Retry this')->and($image->fresh()->alt_text_status)->toBe(Image::ALT_FAILED);
    $gateway = altTextGateway();
    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$product->fresh()]), [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], []);
    expect($result['updated'])->toBe(1)->and($gateway->updates)->toBe(1)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED);
});

it('omits unapproved CMS alt text from ordinary photograph uploads', function (): void {
    Storage::fake('public');
    $product = altTextProduct('PHOTO-APPROVAL-GUARD', 'gid://shopify/Product/560');
    $image = verificationManagedSource($product, 1, 'Unreviewed description');
    Image::withoutEvents(fn () => $image->update(['shopify_id' => null, 'alt_text_status' => Image::ALT_PENDING_REVIEW,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now()]));
    config()->set('services.shopify.shop', 'verification.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    $uploads = [];
    Http::fake(function ($request) use (&$uploads) {
        $query = $request->data()['query'];
        if (str_contains($query, 'productCreateMedia')) {
            $uploads[] = $request->data()['variables']['media'][0];

            return Http::response(['data' => ['productCreateMedia' => [
                'media' => [['id' => 'gid://shopify/MediaImage/5601']], 'mediaUserErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'query ProductByHandleDetails')) {
            return Http::response(['data' => ['productByHandle' => ['media' => ['nodes' => []]]]]);
        }
        throw new RuntimeException('Unexpected photograph upload request.');
    });
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(
        app(ProductShopifyUpdater::class), $product, $product->shopify_id, [], [], [$image->id],
    );
    expect($uploads)->toHaveCount(1)->and($uploads[0])->not->toHaveKey('alt')
        ->and($image->fresh()->alt_text)->toBe('Unreviewed description')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);
});

it('repairs only selected stack source links through the existing image workflow and records failures', function (): void {
    $gateway = altTextGateway();
    $this->actingAs($user = User::factory()->create());
    Permission::findOrCreate(PermissionEnum::ShopifyImageImportAccess->value);
    $user->givePermissionTo(PermissionEnum::ShopifyImageImportAccess->value);
    Bus::fake();
    $component = altTextProduct('WORKFLOW-SOURCE', 'gid://shopify/Product/550');
    $source = altTextImage($component, 1, 'Current', 'gid://shopify/MediaImage/5501');
    $stack = altTextProduct('WORKFLOW-STACK', 'gid://shopify/Product/551', true);
    $first = altTextImage($stack, 1, 'Keep first');
    $copy = altTextImage($stack, 2, 'Keep copy alt');
    $copy->update(['src' => $source->src]);
    altTextDraft($stack, [$component->id]);
    $blocked = altTextProduct('WORKFLOW-BLOCKED', 'gid://shopify/Product/552', true);
    altTextImage($blocked, 1, 'No components');
    $before = $stack->fresh()->getAttributes();
    $firstBefore = $first->fresh()->getAttributes();
    $page = new ImportShopifyProductImages;
    $page->runStackComponentRebuild(app(ShopifyImageImportService::class), ['mode' => 'source_links', 'stack_ids' => [$stack->id, $blocked->id]]);
    $batch = ShopifyImageImportBatch::latest('id')->first();
    expect($copy->fresh()->source_image_id)->toBe($source->id)
        ->and($copy->fresh()->alt_text)->toBe('Keep copy alt')
        ->and($stack->fresh()->getAttributes())->toBe($before)
        ->and($first->fresh()->getAttributes())->toBe($firstBefore)
        ->and($batch->updated_count)->toBe(1)->and($batch->failed_count)->toBe(1)
        ->and($batch->error_message)->toContain('#'.$blocked->id)
        ->and($gateway->queries)->toBe([]);
    Bus::assertNotDispatched(RebuildShopifyStackImagesJob::class);
});

// Final verification: real membership service, with all external requests replaced.
it('verifies collection A B union pagination status and ZIP parity end to end', function (): void {
    Http::fake(['*' => Http::response('photo', 200)]);
    $a = altTextProduct('VERIFY-A', 'gid://shopify/Product/201');
    $b = altTextProduct('VERIFY-B', 'gid://shopify/Product/202');
    $both = altTextProduct('VERIFY-BOTH', 'gid://shopify/Product/203', true);
    $outside = altTextProduct('VERIFY-OUTSIDE', 'gid://shopify/Product/204');
    $inactive = altTextProduct('VERIFY-INACTIVE', 'gid://shopify/Product/205');
    Product::withoutEvents(fn () => $inactive->update(['status' => 'draft']));
    $ia = altTextImage($a, 1, 'A');
    $ia2 = altTextImage($a, 2, 'A side');
    $ib = altTextImage($b, 1, 'B');
    $is = altTextImage($both, 1, 'Stack');
    altTextImage($both, 2, 'Legacy copy');
    altTextImage($both, 3, 'Verified copy')->update(['source_image_id' => $ib->id]);
    altTextDraft($both, [$a->id, $b->id]);
    $io = altTextImage($outside, 1, 'Outside');
    altTextImage($inactive, 1, 'Inactive');
    Product::withoutEvents(fn () => $outside->update(['tags' => 'Collection A,Collection B']));
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public array $calls = [];

        public function graphql(string $query, array $variables = []): array
        {
            $this->calls[] = $variables;
            $ids = $variables['id'] === 'gid://shopify/Collection/1'
                ? ($variables['cursor'] === null ? array_merge([201, 205, 206], range(400, 496)) : [203]) : [202, 203];
            $next = $variables['id'] === 'gid://shopify/Collection/1' && $variables['cursor'] === null;

            return ['collection' => ['products' => [
                'nodes' => array_map(fn ($id) => ['id' => 'gid://shopify/Product/'.$id, 'status' => $id === 206 ? 'ARCHIVED' : 'ACTIVE'], $ids),
                'pageInfo' => ['hasNextPage' => $next, 'endCursor' => $next ? 'page-two' : null],
            ]]];
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $gateway);
    $service = app(ImageAltTextBackfillService::class);
    foreach ([
        [[], [$ia->id, $ia2->id, $ib->id, $is->id, $io->id]],
        [['gid://shopify/Collection/1'], [$ia->id, $ia2->id, $is->id]],
        [['gid://shopify/Collection/2'], [$ib->id, $is->id]],
        [['gid://shopify/Collection/1', 'gid://shopify/Collection/2'], [$ia->id, $ia2->id, $ib->id, $is->id]],
    ] as [$collections, $expected]) {
        $csv = altTextCsv($service->export($collections)['response']);
        expect(altTextImageIds($csv))->toBe($expected);
        $bytes = altTextCsv($service->export($collections, true)['response']);
        $path = tempnam(sys_get_temp_dir(), 'verify-alt-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        try {
            expect($zip->open($path))->toBeTrue();
            expect($zip->getFromName('alt-text.csv'))->toBe($csv);
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_starts_with($zip->getNameIndex($i), 'images/')) {
                    $names[] = $zip->getNameIndex($i);
                }
            }
            expect($names)->toBe(array_map(fn ($id) => 'images/image-'.$id.'.jpg', $expected));
        } finally {
            $zip->close();
            unlink($path);
        }
    }
    expect(collect($gateway->calls)->contains(fn ($call) => $call['cursor'] === 'page-two'))->toBeTrue();
});

it('records a photograph the ZIP could not download', function (): void {
    Http::fake([
        'https://cdn.shopify.com/missing-photo.jpg' => Http::response('missing', 404),
        '*' => Http::response('photo', 200),
    ]);
    $product = altTextProduct('ZIP-FAIL');
    $kept = altTextImage($product, 1, 'Kept');
    $missing = altTextImage($product, 2, 'Missing');
    $missing->update(['src' => 'https://cdn.shopify.com/missing-photo.jpg']);

    $export = app(ImageAltTextBackfillService::class)->export([], true);
    $path = tempnam(sys_get_temp_dir(), 'alt-fail-');
    file_put_contents($path, altTextCsv($export['response']));
    $zip = new ZipArchive;
    try {
        expect($zip->open($path))->toBeTrue();
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $failures = (string) $zip->getFromName('download-failures.csv');
        $manifest = (string) $zip->getFromName('manifest.csv');
        expect($names)->toContain('images/image-'.$kept->id.'.jpg')
            ->and($names)->not->toContain('images/image-'.$missing->id.'.jpg')
            ->and($failures)->toContain((string) $missing->id)
            ->and($failures)->toContain('HTTP 404')
            ->and($manifest)->toContain('failed')
            ->and(collect($export['exceptions'])->pluck('kind'))->toContain('photograph_download');
    } finally {
        $zip->close();
        unlink($path);
    }
});

function verificationManagedSource(Product $product, int $position, ?string $alt): Image
{
    $image = altTextImage($product, $position, $alt, 'gid://shopify/MediaImage/'.($product->id * 100 + $position));
    $name = 'verify-'.$image->id.'.jpg';
    Storage::disk('public')->put($name, 'photo-'.$image->id);
    $asset = ImageAsset::query()->create([
        'sha256' => hash('sha256', $name), 'storage_disk' => 'public',
        'storage_path' => $name, 'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
    $image->update(['image_asset_id' => $asset->id, 'approved_filename' => $name]);

    return $image;
}

it('verifies legacy rebuild source image order exact alt and description preservation', function (): void {
    Storage::fake('public');
    Http::preventStrayRequests();
    $a = altTextProduct('VERIFY-COMP-A');
    $a1 = verificationManagedSource($a, 1, 'A front');
    verificationManagedSource($a, 2, 'A side must not be copied');
    app(ImageAltTextBackfillService::class)->markPendingReview($a1, 'A front');
    altTextApprove($a1);
    $b = altTextProduct('VERIFY-COMP-B');
    $b1 = verificationManagedSource($b, 1, null);
    verificationManagedSource($b, 2, 'B side must not be copied');
    $stack = altTextProduct('VERIFY-LEGACY', 'gid://shopify/Product/301', true);
    $owned = altTextImage($stack, 1, 'Approved lifestyle', 'gid://shopify/MediaImage/3011');
    $legacy = altTextImage($stack, 2, 'Old legacy', 'gid://shopify/MediaImage/3012');
    $before = $stack->getAttributes();
    altTextDraft($stack, [$b->id, $a->id]);
    $result = (new ReflectionMethod(ShopifyImageImportService::class, 'rebuildStackImages'))
        ->invoke(app(ShopifyImageImportService::class), $stack);
    $images = $stack->allImages()->active()->orderBy('position')->get();
    expect($result['rebuilt'])->toBeTrue()
        ->and($images->pluck('source_image_id')->all())->toBe([null, $b1->id, $a1->id])
        ->and($images->pluck('alt_text')->all())->toBe(['Approved lifestyle', null, 'A front'])
        ->and($images->pluck('position')->all())->toBe([1, 2, 3])
        ->and($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/3011')
        ->and($legacy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($stack->fresh()->getAttributes())->toBe($before);

    config()->set('services.shopify.shop', 'verification.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    $uploads = [];
    Http::fake(function ($request) use (&$uploads) {
        $query = $request->data()['query'];
        if (str_contains($query, 'productCreateMedia')) {
            $uploads[] = $request->data()['variables']['media'][0];

            return Http::response(['data' => ['productCreateMedia' => [
                'media' => [['id' => 'gid://shopify/MediaImage/'.(9000 + count($uploads))]], 'mediaUserErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'productReorderMedia')) {
            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }
        if (str_contains($query, 'query ProductByHandleDetails')) {
            return Http::response(['data' => ['productByHandle' => ['media' => ['nodes' => []]]]]);
        }
        throw new RuntimeException('Unexpected verification request: '.$query);
    });
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(
        app(ProductShopifyUpdater::class), $stack->fresh(), $stack->shopify_id, [], [],
        $images->skip(1)->pluck('id')->all(),
    );
    expect($uploads)->toHaveCount(2)
        ->and($uploads[0])->not->toHaveKey('alt')
        ->and($uploads[1]['alt'])->toBe('A front');
    $gateway = altTextGateway();
    app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$a1->id},New exact front\n", true);
    altTextApprove($a1);
    $result = app(ImageAltTextBackfillService::class)->publishIds([$a1->id]);
    $mutations = collect($gateway->variables)->filter(fn (array $variables): bool => isset($variables['files']))->values();
    expect($result['propagated'])->toBe(1)
        ->and($images[2]->fresh()->alt_text)->toBe('New exact front')
        ->and($mutations->last())->toBe(['files' => [['id' => 'gid://shopify/MediaImage/9002', 'alt' => 'New exact front']]]);
});

it('rejects legacy stack CSV rows without source ids and skips unchanged repeated imports', function (): void {
    $gateway = altTextGateway();
    $stack = altTextProduct('VERIFY-LEGACY-IMPORT', 'gid://shopify/Product/305', true);
    $image = altTextImage($stack, 2, 'Legacy', 'gid://shopify/MediaImage/3052');
    $service = app(ImageAltTextBackfillService::class);
    $result = $service->import("image_id,alt_text\n{$image->id},Forbidden\n", true);
    expect($result['excluded'])->toBe(1)->and($image->fresh()->alt_text)->toBe('Legacy')->and($gateway->updates)->toBe(0);
    $product = altTextProduct('VERIFY-REPEAT', 'gid://shopify/Product/3059');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/MediaImage/3053');
    $csv = "image_id,alt_text\n{$image->id},New\n";
    $service->import($csv, true);
    $service->import($csv, true);
    expect($gateway->updates)->toBe(0)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);
    altTextApprove($image);
    app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($gateway->updates)->toBe(1)
        ->and(collect($gateway->variables)->first(fn (array $variables): bool => isset($variables['files'])))
        ->toBe(['files' => [['id' => $image->shopify_id, 'alt' => 'New']]]);
});

it('refuses a legacy rebuild when the stack has no position one image', function (): void {
    Storage::fake('public');
    Http::preventStrayRequests();
    $component = altTextProduct('VERIFY-NO-FIRST-COMP');
    verificationManagedSource($component, 1, 'Component');
    $stack = altTextProduct('VERIFY-NO-FIRST', 'gid://shopify/Product/302', true);
    altTextImage($stack, 2, 'Legacy copy only');
    altTextDraft($stack, [$component->id]);
    $result = (new ReflectionMethod(ShopifyImageImportService::class, 'rebuildStackImages'))
        ->invoke(app(ShopifyImageImportService::class), $stack);
    expect($result['rebuilt'])->toBeFalse();
});

it('refuses a partial legacy rebuild when a linked component has no images', function (): void {
    Storage::fake('public');
    Http::preventStrayRequests();
    $component = altTextProduct('VERIFY-AVAILABLE');
    verificationManagedSource($component, 1, 'Available');
    $missing = altTextProduct('VERIFY-MISSING');
    $stack = altTextProduct('VERIFY-PARTIAL', 'gid://shopify/Product/303', true);
    altTextImage($stack, 1, 'Lifestyle');
    altTextImage($stack, 2, 'Legacy');
    altTextDraft($stack, [$component->id, $missing->id]);
    $result = (new ReflectionMethod(ShopifyImageImportService::class, 'rebuildStackImages'))
        ->invoke(app(ShopifyImageImportService::class), $stack);
    expect($result['rebuilt'])->toBeFalse();
});

it('does not propagate to a stale source link outside the stack components', function (): void {
    $gateway = altTextGateway();
    $component = altTextProduct('VERIFY-STALE-SOURCE', 'gid://shopify/Product/3040');
    $source = altTextImage($component, 1, 'Old', 'gid://shopify/MediaImage/3041');
    $actual = altTextProduct('VERIFY-ACTUAL-COMPONENT');
    $stack = altTextProduct('VERIFY-STALE-STACK', 'gid://shopify/Product/304', true);
    $copy = altTextImage($stack, 2, 'Do not change', 'gid://shopify/MediaImage/3042');
    $copy->update(['source_image_id' => $source->id]);
    altTextDraft($stack, [$actual->id]);
    $imported = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$source->id},New\n", true);
    expect($imported['propagated'])->toBe(0)
        ->and($copy->fresh()->alt_text)->toBe('Do not change')
        ->and($gateway->updates)->toBe(0);
    altTextApprove($source);
    $result = app(ImageAltTextBackfillService::class)->publishIds([$source->id]);
    expect($result['propagated'])->toBe(0)
        ->and($copy->fresh()->alt_text)->toBe('Do not change')
        ->and($gateway->updates)->toBe(1);
});

it('fails closed when Shopify says there are more collection pages but omits the cursor', function (): void {
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            return ['collection' => ['products' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]];
        }
    };
    expect(fn () => (new ShopifyCollectionMembership($gateway))->activeProductGids(['gid://shopify/Collection/1']))
        ->toThrow(RuntimeException::class);
});

it('fails closed when a collection page repeats its cursor', function (): void {
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            return ['collection' => ['products' => [
                'nodes' => [['id' => 'gid://shopify/Product/1', 'status' => 'ACTIVE']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'same'],
            ]]];
        }
    };
    expect(fn () => (new ShopifyCollectionMembership($gateway))->activeProductGids(['gid://shopify/Collection/1']))
        ->toThrow(RuntimeException::class);
});

it('fails closed when a collection product page is malformed', function (): void {
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            return ['collection' => ['products' => ['nodes' => 'nope', 'pageInfo' => ['hasNextPage' => false]]]];
        }
    };
    expect(fn () => (new ShopifyCollectionMembership($gateway))->activeProductGids(['gid://shopify/Collection/1']))
        ->toThrow(RuntimeException::class);
});

it('retries pending alt metadata through the dedicated retry action', function (): void {
    altTextGateway(fail: true);
    $product = altTextProduct('VERIFY-RETRY', 'gid://shopify/Product/3060');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/MediaImage/3061');
    app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$image->id},Approved\n", true);
    altTextApprove($image);
    app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($image->fresh()->alt_text)->toBe('Approved')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_FAILED);
    $gateway = altTextGateway();
    $result = app(ImageAltTextBackfillService::class)->retryIds([$image->id]);
    expect($result['retried'])->toBe(1)
        ->and($result['shopify_failed'])->toBe([])
        ->and($image->fresh()->alt_text)->toBe('Approved')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($image->fresh()->shopify_alt_pending)->toBeFalse()
        ->and(collect($gateway->variables)->first(fn (array $variables): bool => isset($variables['files'])))
        ->toBe(['files' => [['id' => $image->shopify_id, 'alt' => 'Approved']]]);
});

it('does not confirm an alt update when Shopify returns no mutation payload', function (): void {
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            return [];
        }
    };
    expect(fn () => (new ImageAltTextShopifyWriter($gateway))->update('gid://shopify/MediaImage/3062', 'Approved'))
        ->toThrow(RuntimeException::class);
});

it('keeps a quoted alt text newline together', function (): void {
    altTextGateway();
    $product = altTextProduct('MULTILINE');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/MediaImage/8801');
    $csv = "image_id,alt_text\n{$image->id},\"first line\nsecond line\"\n";

    $result = app(ImageAltTextBackfillService::class)->import($csv, true);

    expect($result['updated'])->toBe(1)
        ->and($result['failed'])->toBe([])
        ->and($image->fresh()->alt_text)->toBe("first line\nsecond line");
});

it('previews a legacy product image id without writing', function (): void {
    $product = altTextProduct('LEGACY-MEDIA');
    $image = altTextImage($product, 1, 'Keep', 'gid://shopify/ProductImage/55');

    $gateway = altTextGateway();
    $result = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$image->id},New alt\n", false);

    expect($result['applied'])->toBeFalse()
        ->and($result['shopify_failed'])->toBe([])
        ->and($gateway->queries)->toBe([])
        ->and($image->fresh()->alt_text)->toBe('Keep');

    $saved = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$image->id},New alt\n", true);
    expect($saved['updated'])->toBe(1)
        ->and($gateway->updates)->toBe(0)
        ->and($image->fresh()->alt_text)->toBe('New alt')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/ProductImage/55');
});

it('does not propagate alt text onto the stack owned first image', function (): void {
    altTextGateway();
    $component = altTextProduct('OWNED-SOURCE');
    $source = altTextImage($component, 1, 'Old', 'gid://shopify/MediaImage/8811');
    $stack = altTextProduct('OWNED-STACK', 'gid://shopify/Product/881', true);
    $owned = altTextImage($stack, 1, 'Lifestyle', 'gid://shopify/MediaImage/8812');
    $owned->update(['source_image_id' => $source->id]);
    altTextDraft($stack, [$component->id]);

    $result = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$source->id},Copied alt\n", true);

    expect($result['propagated'])->toBe(0)
        ->and($owned->fresh()->alt_text)->toBe('Lifestyle')
        ->and($owned->fresh()->position)->toBe(1)
        ->and($source->fresh()->alt_text)->toBe('Copied alt');
});

it('previews a stack source repair and blocks a stack with no component list', function (): void {
    $component = altTextProduct('REPAIR-COMP');
    $componentImage = altTextImage($component, 1, 'Component alt', 'gid://shopify/MediaImage/8821');
    $componentImage->update(['src' => 'https://cdn.shopify.com/files/front.jpg']);
    $stack = altTextProduct('REPAIR-STACK', 'gid://shopify/Product/882', true);
    $stack->variants()->update(['price' => '40.00', 'compare_at_price' => '80.00']);
    $owned = altTextImage($stack, 1, 'Lifestyle', 'gid://shopify/MediaImage/8822');
    $copy = altTextImage($stack, 2, 'Old copy', 'gid://shopify/MediaImage/8823');
    $copy->update(['src' => 'https://cdn.shopify.com/files/front.jpg?v=1']);
    altTextDraft($stack, [$component->id]);
    $repair = app(StackImageSourceRepairService::class);

    $preview = $repair->review([$stack->id], false, false);

    expect($preview['applied'])->toBeFalse()
        ->and($preview['stacks'][0]['mode'])->toBe('metadata')
        ->and($preview['stacks'][0]['links'][0]['stack_image_id'])->toBe($copy->id)
        ->and($copy->fresh()->source_image_id)->toBeNull()
        ->and($stack->variants()->first()->price)->toEqual('40.00')
        ->and($stack->variants()->first()->compare_at_price)->toEqual('80.00');

    $empty = altTextProduct('REPAIR-EMPTY', 'gid://shopify/Product/883', true);
    altTextImage($empty, 1, 'Lifestyle', 'gid://shopify/MediaImage/8831');
    $blocked = $repair->review([$empty->id], true, true);
    expect($blocked['stacks'][0]['mode'])->toBe('blocked')
        ->and($blocked['stacks'][0]['blocked'][0])->toContain('no component list');

    $gateway = altTextGateway();
    $saved = app(StackImageSourceRepairService::class)->review([$stack->id], true, true);
    expect($saved['stacks'][0]['applied'])->toBeTrue()
        ->and($copy->fresh()->source_image_id)->toBe($componentImage->id)
        ->and($copy->fresh()->alt_text)->toBe('Old copy')
        ->and($copy->fresh()->position)->toBe(2)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/8823')
        ->and($owned->fresh()->alt_text)->toBe('Lifestyle')
        ->and($owned->fresh()->position)->toBe(1)
        ->and($stack->fresh()->variants()->first()->price)->toEqual('40.00')
        ->and($stack->fresh()->variants()->first()->compare_at_price)->toEqual('80.00')
        ->and($gateway->queries)->toBe([]);
});

it('resolves a product image id only while pushing approved alt text', function (): void {
    $gateway = altTextGateway();
    $gateway->resolvedMedia['gid://shopify/ProductImage/55'] = 'gid://shopify/MediaImage/7700';
    $product = altTextProduct('RESOLVE', 'gid://shopify/Product/77');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/ProductImage/55');
    $service = app(ImageAltTextBackfillService::class);

    $service->import("image_id,alt_text\n{$image->id},Resolved alt\n", true);
    expect($gateway->updates)->toBe(0)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/ProductImage/55');

    $service->publishIds([$image->id]);
    expect($gateway->updates)->toBe(0)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    altTextApprove($image);
    $pushed = app(ImageAltTextBackfillService::class)->publishIds([$image->id]);

    expect($pushed['published'])->toBe(1)
        ->and($pushed['shopify_failed'])->toBe([])
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/7700')
        ->and($image->fresh()->alt_text)->toBe('Resolved alt')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($image->fresh()->position)->toBe(1)
        ->and(collect($gateway->variables)->first(fn (array $variables): bool => isset($variables['files'])))
        ->toBe(['files' => [['id' => 'gid://shopify/MediaImage/7700', 'alt' => 'Resolved alt']]]);
});

it('keeps approved alt text when the shopify media match is ambiguous', function (): void {
    $gateway = altTextGateway();
    $product = altTextProduct('AMBIGUOUS', 'gid://shopify/Product/78');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/ProductImage/78');
    $gateway->extraMedia = [
        ['id' => 'gid://shopify/MediaImage/781', 'image' => ['url' => $image->src]],
        ['id' => 'gid://shopify/MediaImage/782', 'image' => ['url' => $image->src.'?v=2']],
    ];
    $service = app(ImageAltTextBackfillService::class);
    $service->import("image_id,alt_text\n{$image->id},Keep this alt\n", true);
    altTextApprove($image);

    $pushed = app(ImageAltTextBackfillService::class)->publishIds([$image->id]);

    expect($pushed['published'])->toBe(0)
        ->and($pushed['shopify_failed'])->toHaveCount(1)
        ->and($gateway->updates)->toBe(0)
        ->and($image->fresh()->alt_text)->toBe('Keep this alt')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_FAILED)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/ProductImage/78');
});

it('reviews alt text through product partial approval and does not publish unapproved text', function (): void {
    $gateway = altTextGateway();
    $product = altTextProduct('PARTIAL-ALT', 'gid://shopify/Product/91');
    $changed = altTextImage($product, 1, 'Old front', 'gid://shopify/MediaImage/9101');
    $unchanged = altTextImage($product, 2, 'Side stays', 'gid://shopify/MediaImage/9102');
    $stack = altTextProduct('PARTIAL-STACK', 'gid://shopify/Product/92', true);
    $owned = altTextImage($stack, 1, 'Lifestyle', 'gid://shopify/MediaImage/9201');
    $copy = altTextImage($stack, 2, 'Old front', 'gid://shopify/MediaImage/9202');
    $copy->update(['source_image_id' => $changed->id]);
    altTextDraft($stack, [$product->id]);

    $imported = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$changed->id},Reviewed front\n{$owned->id},Reviewed lifestyle\n", true);
    expect($imported['applied'])->toBeTrue()
        ->and($gateway->updates)->toBe(0)
        ->and($changed->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($unchanged->fresh()->alt_text)->toBe('Side stays');

    $requester = User::factory()->create();
    $approver = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $approver->assignRole(RolesEnum::Admin->value);
    Bus::fake([SendProductPartialApprovalSlackReminderJob::class]);
    $partial = app(ProductPartialApprovalService::class);
    $partial->request(collect([$product, $stack]), $requester->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], [], $approver->id);
    Bus::assertDispatched(SendProductPartialApprovalSlackReminderJob::class);

    $productRequest = ProductPartialApprovalRequest::query()->where('product_id', $product->id)->first();
    $stackRequest = ProductPartialApprovalRequest::query()->where('product_id', $stack->id)->first();
    $productImages = collect($productRequest->image_alt_snapshot['images'])->keyBy('image_id');
    $stackImages = collect($stackRequest->image_alt_snapshot['images'])->keyBy('image_id');
    expect($productRequest->target_approver_id)->toBe($approver->id)
        ->and($productRequest->scopes)->toBe([ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT])
        ->and($productImages->keys()->all())->toEqualCanonicalizing([$changed->id, $unchanged->id])
        ->and($productImages[$changed->id]['proposed_alt'])->toBe('Reviewed front')
        ->and($productImages[$changed->id]['changed'])->toBeTrue()
        ->and($productImages[$unchanged->id]['changed'])->toBeFalse()
        ->and($stackImages[$owned->id]['inherited'])->toBeFalse()
        ->and($stackImages[$copy->id]['inherited'])->toBeTrue()
        ->and($partial->canApproveRequest($productRequest->fresh('product'), $requester->id))->toBeFalse()
        ->and($partial->canApproveRequest($productRequest->fresh('product'), $approver->id))->toBeTrue();

    app(ImageAltTextBackfillService::class)->markPendingReview($changed->fresh(), 'Changed while waiting');
    expect($productRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_REJECTED)
        ->and($partial->approveRequests(collect([$productRequest->fresh()]), $approver->id)['approved'])->toBe(0)
        ->and($changed->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);

    $partial->request(collect([$product->fresh()]), $requester->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], [], $approver->id);
    $freshRequest = ProductPartialApprovalRequest::query()
        ->where('product_id', $product->id)
        ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
        ->latest('id')
        ->first();
    expect($freshRequest->image_alt_snapshot['images'][0]['proposed_alt'])->toBe('Changed while waiting');
    $rejected = $partial->rejectRequests(collect([$freshRequest]), $approver->id, 'Please shorten it');
    expect($rejected['rejected'])->toBe(1)
        ->and($freshRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_REJECTED)
        ->and($requester->notifications()->count())->toBe(1);
    app(ImageAltTextBackfillService::class)->publishIds([$changed->id]);
    expect($gateway->updates)->toBe(0)
        ->and($changed->fresh()->alt_text)->toBe('Changed while waiting');

    $partial->request(collect([$product->fresh(), $stack->fresh()]), $requester->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], [], $approver->id);
    $approvedProduct = ProductPartialApprovalRequest::query()->where('product_id', $product->id)->where('status', ProductPartialApprovalRequest::STATUS_PENDING)->latest('id')->first();
    $approvedStack = ProductPartialApprovalRequest::query()->where('product_id', $stack->id)->where('status', ProductPartialApprovalRequest::STATUS_PENDING)->latest('id')->first();
    $summary = $partial->approveRequests(collect([$approvedProduct, $approvedStack]), $approver->id);
    expect($summary['approved'])->toBe(2)
        ->and($changed->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($owned->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($partial->approvedAlt($owned->fresh()))->toBe('Reviewed lifestyle')
        ->and($copy->fresh()->alt_text)->toBe('Old front')
        ->and($requester->notifications()->count())->toBe(3);

    $pushed = app(ImageAltTextBackfillService::class)->publishIds([$changed->id, $unchanged->id, $owned->id, $copy->id]);
    expect($pushed['published'])->toBe(3)
        ->and($pushed['propagated'])->toBe(1)
        ->and($changed->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($changed->fresh()->shopify_alt_text)->toBe('Changed while waiting')
        ->and($copy->fresh()->alt_text)->toBe('Changed while waiting')
        ->and($copy->fresh()->position)->toBe(2)
        ->and($owned->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($unchanged->fresh()->alt_text_status)->toBeNull()
        ->and(collect($gateway->queries)->filter(fn (string $query): bool => str_contains($query, 'fileUpdate')))->toHaveCount(3)
        ->and(collect($gateway->queries)->first(fn (string $query): bool => str_contains($query, 'fileUpdate')))->not->toContain('originalSource');

    app(ImageAltTextBackfillService::class)->markPendingReview($changed->fresh(), 'Needs approval again');
    app(ImageAltTextBackfillService::class)->publishIds([$changed->id]);
    expect($changed->fresh()->alt_text)->toBe('Needs approval again')
        ->and($changed->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and(collect($gateway->queries)->filter(fn (string $query): bool => str_contains($query, 'fileUpdate')))->toHaveCount(3);
});

it('keeps price approval independent from image alt text approval', function (): void {
    $gateway = altTextGateway();
    $product = altTextProduct('COLUMN-SPLIT', 'gid://shopify/Product/93');
    $image = altTextImage($product, 1, 'Old', 'gid://shopify/MediaImage/9301');
    $imported = app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$image->id},Column alt\n", true);
    expect($imported['applied'])->toBeTrue()
        ->and($gateway->updates)->toBe(0);

    $requester = User::factory()->create();
    $approver = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $approver->assignRole(RolesEnum::Admin->value);
    Bus::fake([SendProductPartialApprovalSlackReminderJob::class]);
    $partial = app(ProductPartialApprovalService::class);
    $partial->request(collect([$product]), $requester->id, [ProductShopifyUpdater::SYNC_SCOPE_PRICE], [], $approver->id);
    $partial->request(collect([$product]), $requester->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], [], $approver->id);

    $columnRequests = ProductPartialApprovalRequest::query()->where('product_id', $product->id)->get();
    $priceRequest = $columnRequests->first(fn (ProductPartialApprovalRequest $request): bool => $request->scopes === [ProductShopifyUpdater::SYNC_SCOPE_PRICE]);
    $altRequest = $columnRequests->first(fn (ProductPartialApprovalRequest $request): bool => $request->scopes === [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT]);

    expect($priceRequest)->not->toBeNull()
        ->and($altRequest)->not->toBeNull()
        ->and($priceRequest->image_alt_snapshot)->toBeNull()
        ->and($altRequest->image_alt_snapshot['images'][0]['proposed_alt'])->toBe('Column alt');

    Product::withoutEvents(fn () => $product->forceFill(['approval_version' => 9])->save());
    $visibleIds = $partial->visiblePendingRequestsQuery()->pluck('id')->all();
    expect($visibleIds)->toContain($altRequest->id)
        ->and($visibleIds)->not->toContain($priceRequest->id)
        ->and($partial->canApproveRequest($priceRequest->fresh('product'), $approver->id))->toBeFalse()
        ->and($partial->canApproveRequest($altRequest->fresh('product'), $approver->id))->toBeTrue();
    Product::withoutEvents(fn () => $product->forceFill(['approval_version' => 1])->save());

    expect($partial->approveRequests(collect([$priceRequest->fresh('product')]), $approver->id)['approved'])->toBe(1)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($altRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_PENDING);
    app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($gateway->updates)->toBe(0);

    expect($partial->approveRequests(collect([$altRequest->fresh('product')]), $approver->id)['approved'])->toBe(1)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($priceRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED)
        ->and($partial->allowedSelections($product->fresh(), [ProductShopifyUpdater::SYNC_SCOPE_PRICE, ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT])['scopes'])
        ->toBe([ProductShopifyUpdater::SYNC_SCOPE_PRICE, ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT]);

    Product::withoutEvents(fn () => $product->forceFill(['approval_version' => 4])->save());
    $pushed = app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($pushed['published'])->toBe(1)
        ->and($gateway->updates)->toBe(1)
        ->and($image->fresh()->shopify_alt_text)->toBe('Column alt')
        ->and($priceRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED)
        ->and($partial->allowedSelections($product->fresh(), [ProductShopifyUpdater::SYNC_SCOPE_PRICE, ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT])['scopes'])
        ->toBe([ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT]);

    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Edited after approval');
    expect($altRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_REJECTED)
        ->and($altRequest->fresh()->scopes)->toBe([])
        ->and($priceRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED)
        ->and($priceRequest->fresh()->scopes)->toBe([ProductShopifyUpdater::SYNC_SCOPE_PRICE])
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);
    app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($gateway->updates)->toBe(1);

    $partial->request(collect([$product->fresh()]), $requester->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], [], $approver->id);
    $replacement = ProductPartialApprovalRequest::query()
        ->where('product_id', $product->id)
        ->where('status', ProductPartialApprovalRequest::STATUS_PENDING)
        ->latest('id')
        ->first();
    $rejected = $partial->rejectRequests(collect([$replacement]), $approver->id, 'Alt only');
    expect($rejected['rejected'])->toBe(1)
        ->and($replacement->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_REJECTED)
        ->and($priceRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED);

    $combined = altTextProduct('COLUMN-BOTH', 'gid://shopify/Product/94');
    $combinedImage = altTextImage($combined, 1, 'Before', 'gid://shopify/MediaImage/9401');
    app(ImageAltTextBackfillService::class)->import("image_id,alt_text\n{$combinedImage->id},Both columns\n", true);
    $combinedRequest = ProductPartialApprovalRequest::query()->create([
        'product_id' => $combined->id,
        'approval_version' => $combined->approval_version,
        'requested_by' => $requester->id,
        'target_approver_id' => $approver->id,
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => [ProductShopifyUpdater::SYNC_SCOPE_PRICE, ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT],
        'core_fields' => [],
        'image_alt_snapshot' => $partial->imageAltSnapshot($combined),
    ]);
    expect($partial->approveRequests(collect([$combinedRequest]), $approver->id)['approved'])->toBe(1)
        ->and($combinedImage->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);

    app(ImageAltTextBackfillService::class)->markPendingReview($combinedImage->fresh(), 'Alt changed, price stays');
    expect($combinedRequest->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED)
        ->and($combinedRequest->fresh()->scopes)->toBe([ProductShopifyUpdater::SYNC_SCOPE_PRICE])
        ->and($combinedRequest->fresh()->image_alt_snapshot)->toBeNull()
        ->and($combinedImage->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($partial->approvedAlt($combinedImage->fresh()))->toBeNull();
});
