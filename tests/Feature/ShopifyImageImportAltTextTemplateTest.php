<?php

use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Filament\Pages\ImportShopifyProductImages;
use App\Models\Image;
use App\Models\ShopifyImageImportBatch;
use App\Models\User;
use App\Services\ShopifyImageImportService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Support/shopify-image-import.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();

    Permission::findOrCreate(PermissionEnum::ShopifyImageImportAccess->value);
    Role::findOrCreate(RolesEnum::Admin->value);
    $user = User::factory()->create();
    $user->assignRole(RolesEnum::Admin->value);
    $user->givePermissionTo(PermissionEnum::ShopifyImageImportAccess->value);
    $this->actingAs($user);
});

it('builds a utf-8 template from the original filenames and skips unsupported files', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('files')->once()->with('incoming/2026-11-10')->andReturn([
        'incoming/2026-11-10/LA0550-2.jpg',
        'incoming/2026-11-10/café, "gold".jpg',
        'incoming/2026-11-10/LA0550.jpg',
        'incoming/2026-11-10/Photo.JPEG',
        'incoming/2026-11-10/readme.txt',
        'incoming/2026-11-10/skip.gif',
        'incoming/2026-11-10/alt-text.csv',
        'incoming/2026-11-10/nested/LA0999.png',
    ]);
    Storage::shouldReceive('disk')->once()->andReturn($disk);

    $template = app(ShopifyImageImportService::class)->altTextTemplate('2026-11-10');

    expect($template['ok'])->toBeTrue()
        ->and($template['filenames'])->toBe([
            'café, "gold".jpg',
            'LA0550.jpg',
            'LA0550-2.jpg',
            'Photo.JPEG',
        ])
        ->and($template['csv'])->toStartWith("\xEF\xBB\xBFfilename,alt_text\n")
        ->and($template['csv'])->toContain('"café, ""gold"".jpg",'."\n")
        ->and($template['csv'])->not->toContain('readme.txt')
        ->and($template['csv'])->not->toContain('skip.gif')
        ->and($template['csv'])->not->toContain('LA0999')
        ->and($template['csv'])->not->toContain('alt-text.csv,');

    [$headers, $rows] = altTemplateRows($template['csv']);

    expect($headers)->toBe(['filename', 'alt_text'])
        ->and(array_column($rows, 0))->toBe($template['filenames'])
        ->and(array_column($rows, 1))->toBe(['', '', '', '']);
});

it('downloads a header-only template when no folder is selected', function (): void {
    $template = app(ShopifyImageImportService::class)->altTextTemplate('   ');

    expect($template['ok'])->toBeTrue()
        ->and($template['filenames'])->toBe([])
        ->and($template['message'])->toBeNull();

    [$headers, $rows] = altTemplateRows($template['csv']);
    expect($headers)->toBe(['filename', 'alt_text'])
        ->and($rows)->toBe([]);

    $page = Livewire::test(ImportShopifyProductImages::class)
        ->set('data.s3_prefix', '')
        ->assertSee('Download Alt Text Template')
        ->assertSee('Select the AWS S3 folder containing the product images.')
        ->assertSee('Upload the completed file as')
        ->assertSee('café detailing');

    $response = $page->instance()->downloadAltTextTemplate(app(ShopifyImageImportService::class));
    expect($response)->not->toBeNull()
        ->and((string) $response->headers->get('content-disposition'))->toContain('alt-text.csv')
        ->and((string) $response->headers->get('content-type'))->toContain('text/csv');

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();
    [$headers, $rows] = altTemplateRows((string) $body);

    expect($headers)->toBe(['filename', 'alt_text'])
        ->and($rows)->toBe([])
        ->and(Storage::disk('shopify_product_images')->allFiles())->toBe([]);
});

it('lists supported images from the selected folder without writing the template', function (): void {
    $disk = Storage::disk('shopify_product_images');
    $disk->put('incoming/2026-11-10/LA0550-2.jpg', 'second');
    $disk->put('incoming/2026-11-10/café.jpg', 'accent');
    $disk->put('incoming/2026-11-10/LA0550.jpg', 'first');
    $disk->put('incoming/2026-11-10/Photo.JPEG', 'jpeg');
    $disk->put('incoming/2026-11-10/readme.txt', 'notes');
    $disk->put('incoming/2026-11-10/skip.gif', 'unsupported');
    $disk->put('incoming/2026-11-10/alt-text.csv', "filename,alt_text\n");
    $disk->put('incoming/2026-11-10/nested/LA0999.png', 'nested');
    $before = $disk->allFiles();

    $template = app(ShopifyImageImportService::class)->altTextTemplate('incoming/2026-11-10');

    expect($disk->allFiles())->toBe($before)
        ->and($template['filenames'])->toBe([
            'café.jpg',
            'LA0550.jpg',
            'LA0550-2.jpg',
            'Photo.JPEG',
        ]);

    [$headers, $rows] = altTemplateRows($template['csv']);
    expect($headers)->toBe(['filename', 'alt_text'])
        ->and(array_column($rows, 1))->toBe(['', '', '', '']);
});

it('returns a header-only template for an empty folder and ignores unsupported files', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/empty-day/readme.txt', 'notes');
    Storage::disk('shopify_product_images')->put('incoming/empty-day/nested/LA1.png', 'nested');

    $template = app(ShopifyImageImportService::class)->altTextTemplate('empty-day');

    expect($template['ok'])->toBeTrue()
        ->and($template['filenames'])->toBe([])
        ->and($template['message'])->toContain('no supported product images');

    [$headers, $rows] = altTemplateRows($template['csv']);
    expect($headers)->toBe(['filename', 'alt_text'])
        ->and($rows)->toBe([]);
});

it('shows found, missing, and refreshes the status when the folder changes', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/folder-a/LA0550.jpg', 'front');
    Storage::disk('shopify_product_images')->put('incoming/folder-a/alt-text.csv', "filename,alt_text\nLA0550.jpg,Front\n");
    Storage::disk('shopify_product_images')->put('incoming/folder-b/LA0550.jpg', 'front');
    Storage::disk('shopify_product_images')->put('incoming/folder-b/nested/alt-text.csv', "filename,alt_text\n");

    $service = app(ShopifyImageImportService::class);

    expect($service->altTextCsvStatus('folder-a'))->toBe('found')
        ->and($service->altTextCsvStatus('folder-b'))->toBe('missing')
        ->and($service->altTextCsvStatus(''))->toBe('empty');

    Livewire::test(ImportShopifyProductImages::class)
        ->fillForm(['s3_prefix' => 'folder-a'])
        ->assertSeeHtml('data-alt-csv-status="found"')
        ->assertSee('The file exists in the selected S3 folder.')
        ->fillForm(['s3_prefix' => 'folder-b'])
        ->assertSeeHtml('data-alt-csv-status="missing"')
        ->assertSee('No CSV exists; image-only import is still supported.')
        ->assertDontSeeHtml('data-alt-csv-status="found"')
        ->assertDontSee('The file exists in the selected S3 folder.')
        ->fillForm(['s3_prefix' => 'folder-a'])
        ->assertSeeHtml('data-alt-csv-status="found"')
        ->set('data.s3_prefix', '')
        ->assertSeeHtml('data-alt-csv-status="empty"')
        ->assertDontSeeHtml('data-alt-csv-status="found"')
        ->assertDontSeeHtml('data-alt-csv-status="missing"');
});

it('does not report the csv as found unless s3 confirms that exact file', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->with('incoming/2026-11-10/alt-text.csv')->andReturn(true);
    Storage::shouldReceive('disk')->once()->andReturn($disk);

    expect(app(ShopifyImageImportService::class)->altTextCsvStatus('2026-11-10'))->toBe('found');
});

it('reports missing when the existence check does not confirm the file', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->andReturn(null);
    Storage::shouldReceive('disk')->once()->andReturn($disk);

    expect(app(ShopifyImageImportService::class)->altTextCsvStatus('2026-11-10'))->toBe('missing');
});

it('reports unable to check and does not download a folder template when s3 fails', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andThrow(new RuntimeException('S3 timed out'));
    $disk->shouldReceive('files')->andThrow(new RuntimeException('S3 timed out'));
    Storage::shouldReceive('disk')->andReturn($disk);

    $service = app(ShopifyImageImportService::class);

    expect($service->altTextCsvStatus('2026-11-10'))->toBe('unavailable');

    $template = $service->altTextTemplate('folder-a');
    expect($template['ok'])->toBeFalse()
        ->and($template['filenames'])->toBe([])
        ->and($template['csv'])->toBe('');

    $page = Livewire::test(ImportShopifyProductImages::class)
        ->assertSeeHtml('data-alt-csv-status="unavailable"')
        ->assertSee('Unable to Check CSV')
        ->assertSee('S3 could not be accessed or checked.')
        ->assertDontSeeHtml('data-alt-csv-status="found"')
        ->assertDontSeeHtml('data-alt-csv-status="missing"');

    expect($page->instance()->downloadAltTextTemplate($service))->toBeNull();
});

it('still imports images when the folder has no alt text csv', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    $product = createImageImportProduct('LA0550');
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/2026-11-10',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    $result = app(ShopifyImageImportService::class)->runBatch($batch);
    $image = $product->images()->first();

    expect($result['total_files'])->toBe(1)
        ->and($result['updated_count'])->toBe(1)
        ->and($result['alt_text_csv_found'])->toBeFalse()
        ->and($result['alt_text_applied'])->toBe(0)
        ->and($image)->not->toBeNull()
        ->and($image->alt_text)->toBeNull()
        ->and($image->alt_text_status)->toBeNull()
        ->and($image->approved_filename)->toBe('la0550-1.jpg');
});

it('still applies alt text from an existing csv without publishing it', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/2026-11-10/LA0550.jpg', 'front-photo');
    Storage::disk('shopify_product_images')->put(
        'incoming/2026-11-10/alt-text.csv',
        "filename,alt_text\nLA0550.jpg,\"Front view, café\"\n",
    );
    $product = createImageImportProduct('LA0550');
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/2026-11-10',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    $result = app(ShopifyImageImportService::class)->runBatch($batch);
    $image = $product->images()->first();

    expect($result['alt_text_csv_found'])->toBeTrue()
        ->and($result['alt_text_applied'])->toBe(1)
        ->and($result['alt_text_error_count'])->toBe(0)
        ->and($image->alt_text)->toBe('Front view, café')
        ->and($image->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->shopify_alt_text)->toBeNull();
});

/**
 * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
 */
function altTemplateRows(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, ltrim($csv, "\xEF\xBB\xBF"));
    rewind($handle);
    $headers = fgetcsv($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return [is_array($headers) ? $headers : [], $rows];
}
