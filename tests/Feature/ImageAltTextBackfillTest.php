<?php

use App\Models\Image;
use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ImageAltTextBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports every active image and its alt text beyond 1000 rows while excluding inactive images and products', function (): void {
    $product = altTextProduct('LA0550');
    altTextImage($product, 1, null);
    altTextImage($product, 2, 'Already described');
    $rows = [];
    for ($position = 3; $position <= 1005; $position++) {
        $rows[] = [
            'product_id' => $product->id,
            'position' => $position,
            'alt_text' => 'Description '.$position,
            'sync_state' => Image::SYNC_STATE_SYNCED,
        ];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        Image::query()->insert($chunk);
    }
    foreach ([Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED] as $state) {
        altTextImage($product, 1006, 'Excluded '.$state)->update(['sync_state' => $state]);
    }
    altTextImage($product, 1007, 'Excluded hidden')->update(['is_duplicate_hidden' => true]);
    foreach (['draft', 'archived'] as $status) {
        $inactive = altTextProduct('EXCLUDED-'.$status);
        Product::withoutEvents(fn () => $inactive->update(['status' => $status]));
        altTextImage($inactive, 1, 'Excluded '.$status);
    }
    $withoutSku = altTextProduct('');
    altTextImage($withoutSku, 1, 'Included without SKU');

    $response = app(ImageAltTextBackfillService::class)->downloadActive();
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'alt-template-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    expect($sheet)->toContain('SKU')
        ->and($sheet)->toContain('Shopify Image')
        ->and($sheet)->toContain('LA0550')
        ->and($sheet)->toContain('https://cdn.shopify.com/1.jpg')
        ->and($sheet)->toContain('IMAGE(E2)')
        ->and($sheet)->toContain('Already described')
        ->and($sheet)->toContain('Description 1005')
        ->and($sheet)->toContain('Included without SKU')
        ->and($sheet)->not->toContain('Excluded ')
        ->and(substr_count($sheet, '<row '))->toBe(1007);
});

it('reads alt text back from the excel template', function (): void {
    $product = altTextProduct('LA0551');
    $image = altTextImage($product, 1, null);
    $path = tempnam(sys_get_temp_dir(), 'alt-upload-');
    @unlink($path);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Position</t></is></c><c r="C1" t="inlineStr"><is><t>Alt Text</t></is></c><c r="D1" t="inlineStr"><is><t>Product</t></is></c><c r="E1" t="inlineStr"><is><t>Shopify Image</t></is></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>LA0551</t></is></c><c r="B2"><v>1</v></c><c r="C2" t="inlineStr"><is><t>The Best Kind of Trouble Necklace</t></is></c><c r="E2" t="inlineStr"><is><t>https://cdn.shopify.com/1.jpg</t></is></c><c r="F2"><f>IF(E2=&quot;&quot;,&quot;&quot;,IMAGE(E2))</f></c></row>'
        .'</sheetData></worksheet>';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    $result = app(ImageAltTextBackfillService::class)->importFromPath($path);
    @unlink($path);

    expect($result['updated'])->toBe(1)
        ->and($image->fresh()->alt_text)->toBe('The Best Kind of Trouble Necklace');
});

it('backfills alt text by sku and image position from a csv', function (): void {
    $product = altTextProduct('LA0550');
    $first = altTextImage($product, 1, null);
    $second = altTextImage($product, 2, 'Old detail');
    $literal = altTextProduct('KEEP-2');
    $literalImage = altTextImage($literal, 1, null);

    $result = app(ImageAltTextBackfillService::class)->import(<<<'CSV'
SKU,Position,Alt Text
LA0550,1,The Art of Temptation Necklace
LA0550-2,2,The Art of Temptation Necklace side view
KEEP-2,,Keep the literal sku
LA0550,3,Missing position
,," "
CSV);

    expect($result['updated'])->toBe(3)
        ->and($result['unchanged'])->toBe(0)
        ->and($result['skipped_blank'])->toBe(1)
        ->and($result['unmatched'])->toHaveCount(1)
        ->and($result['unmatched'][0]['reason'])->toContain('no image at position 3')
        ->and($first->fresh()->alt_text)->toBe('The Art of Temptation Necklace')
        ->and($second->fresh()->alt_text)->toBe('The Art of Temptation Necklace side view')
        ->and($literalImage->fresh()->alt_text)->toBe('Keep the literal sku')
        ->and($product->fresh()->approval_version)->toBe(1);
});

function altTextProduct(string $sku): Product
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
        'handle' => strtolower($sku),
        'title' => $sku,
        'status' => 'active',
        'approval_version' => 1,
    ]));
    Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));

    return $product;
}

function altTextImage(Product $product, int $position, ?string $altText): Image
{
    return Image::withoutEvents(fn (): Image => Image::query()->create([
        'product_id' => $product->id,
        'position' => $position,
        'alt_text' => $altText,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'src' => 'https://cdn.shopify.com/'.$position.'.jpg',
    ]));
}
