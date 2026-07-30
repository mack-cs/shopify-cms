<?php

use App\Mail\DuplicateSkuReminderMail;
use App\Models\DeletionRequest;
use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\DuplicateSkuSlackNotification;
use App\Services\DuplicateSkuAuditService;
use App\Services\DuplicateSkuCsvExporter;
use App\Services\DuplicateSkuDeletionRequestService;
use App\Services\DuplicateSkuReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('detects a normalized SKU only when it belongs to more than one product', function (): void {
    $import = duplicateSkuTestImport();
    $first = duplicateSkuTestProduct($import, 'first-product', 'active');
    $second = duplicateSkuTestProduct($import, 'second-product', 'draft');
    $third = duplicateSkuTestProduct($import, 'third-product', 'archived');

    duplicateSkuTestVariant($first, ' shared-100 ');
    duplicateSkuTestVariant($second, 'SHARED-100');

    // Repeated variants under the same product are not a cross-product conflict.
    duplicateSkuTestVariant($third, 'SAME-PRODUCT');
    duplicateSkuTestVariant($third, 'same-product');

    // Blank and deleted variants must not create false alerts.
    duplicateSkuTestVariant($first, ' ');
    duplicateSkuTestVariant($first, 'DELETED-200', Variant::SYNC_STATE_REMOTE_DELETED);
    duplicateSkuTestVariant($second, 'deleted-200');

    $conflicts = app(DuplicateSkuAuditService::class)->findConflicts();

    expect(array_column($conflicts, 'sku'))->toBe(['SHARED-100'])
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['sku'])->toBe('SHARED-100')
        ->and($conflicts[0]['product_count'])->toBe(2)
        ->and($conflicts[0]['variant_count'])->toBe(2)
        ->and(array_column($conflicts[0]['products'], 'title'))->toBe([
            'First Product',
            'Second Product',
        ])
        ->and(array_column($conflicts[0]['products'], 'status'))->toBe([
            'active',
            'draft',
        ]);
});

it('emails Nick and posts a Slack reminder that mentions him when conflicts exist', function (): void {
    Mail::fake();
    Notification::fake();
    config([
        'services.slack.channels.duplicate_skus' => '#product-data-alerts',
        'services.slack.duplicate_sku_recipient_email' => 'nick@leighavenue.co.za',
        'services.slack.duplicate_sku_recipient_user_id' => 'U0B5DM894DS',
    ]);

    $import = duplicateSkuTestImport();
    $first = duplicateSkuTestProduct($import, 'red-necklace', 'active');
    $second = duplicateSkuTestProduct($import, 'red-necklace-copy', 'draft');
    duplicateSkuTestVariant($first, 'LA-RED-01');
    duplicateSkuTestVariant($second, 'la-red-01');

    $result = app(DuplicateSkuReminderService::class)->sendDailyReminder();

    expect($result)->toMatchArray([
        'conflict_count' => 1,
        'product_count' => 2,
        'email_sent' => true,
        'slack_sent' => true,
        'errors' => [],
    ]);

    Mail::assertSent(
        DuplicateSkuReminderMail::class,
        fn (DuplicateSkuReminderMail $mail): bool => $mail->hasTo('nick@leighavenue.co.za')
            && $mail->conflicts[0]['sku'] === 'LA-RED-01'
    );

    Notification::assertSentOnDemand(
        DuplicateSkuSlackNotification::class,
        function (
            DuplicateSkuSlackNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable
        ): bool {
            $payload = $notification->toSlack($notifiable)->toArray();
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

            return $channels === ['slack']
                && $notifiable->routeNotificationFor('slack') === '#product-data-alerts'
                && is_string($json)
                && str_contains($json, '<@U0B5DM894DS>')
                && str_contains($json, 'LA-RED-01')
                && str_contains($json, 'Red Necklace')
                && str_contains($json, 'Red Necklace Copy');
        }
    );
});

it('sends no reminder when every SKU belongs to only one product', function (): void {
    Mail::fake();
    Notification::fake();
    config(['services.slack.channels.duplicate_skus' => '#product-data-alerts']);

    $import = duplicateSkuTestImport();
    $product = duplicateSkuTestProduct($import, 'unique-product', 'active');
    duplicateSkuTestVariant($product, 'UNIQUE-001');

    $result = app(DuplicateSkuReminderService::class)->sendDailyReminder();

    expect($result['conflict_count'])->toBe(0)
        ->and($result['email_sent'])->toBeFalse()
        ->and($result['slack_sent'])->toBeFalse()
        ->and($result['errors'])->toBe([]);

    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('exports one CSV row for every variant affected by a cross-product SKU conflict', function (): void {
    $import = duplicateSkuTestImport();
    $first = duplicateSkuTestProduct($import, 'first-export-product', 'active');
    $second = duplicateSkuTestProduct($import, 'second-export-product', 'draft');
    $unaffected = duplicateSkuTestProduct($import, 'unaffected-product', 'active');

    $first->update(['vendor' => 'Leigh Avenue', 'shopify_id' => 'gid://shopify/Product/100']);
    $second->update(['vendor' => 'Supplier Two', 'shopify_id' => 'gid://shopify/Product/200']);

    $firstVariant = duplicateSkuTestVariant($first, ' shared-export ');
    $firstVariant->update([
        'shopify_id' => 'gid://shopify/ProductVariant/101',
        'option1_name' => 'Colour',
        'option1_value' => 'Gold',
        'price' => 499.95,
        'inventory_tracked' => true,
        'inventory_qty' => 4,
    ]);
    duplicateSkuTestVariant($second, 'SHARED-EXPORT');
    duplicateSkuTestVariant($unaffected, 'UNIQUE-EXPORT');

    $response = app(DuplicateSkuCsvExporter::class)->download();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();
    $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($csv)) ?: []));

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('duplicate_sku_products_')
        ->and($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('duplicate_sku,products_sharing_sku')
        ->and($csv)->toContain('SHARED-EXPORT,2')
        ->and($csv)->toContain('gid://shopify/Product/100')
        ->and($csv)->toContain('gid://shopify/ProductVariant/101')
        ->and($csv)->toContain('Colour: Gold')
        ->and($csv)->toContain('Leigh Avenue')
        ->and($csv)->not->toContain('UNIQUE-EXPORT');
});

it('requests deletion only for selected archived products that have duplicate SKUs', function (): void {
    $import = duplicateSkuTestImport();
    $active = duplicateSkuTestProduct($import, 'active-sku-owner', 'active');
    $archivedDuplicate = duplicateSkuTestProduct($import, 'archived-duplicate', 'archived');
    $archivedUnique = duplicateSkuTestProduct($import, 'archived-unique', 'archived');

    duplicateSkuTestVariant($active, 'DELETE-CANDIDATE');
    duplicateSkuTestVariant($archivedDuplicate, 'delete-candidate');
    duplicateSkuTestVariant($archivedUnique, 'ARCHIVED-BUT-UNIQUE');

    $audit = app(DuplicateSkuAuditService::class);
    expect($audit->conflictingProductIds('archived'))->toBe([$archivedDuplicate->id]);

    $summary = app(DuplicateSkuDeletionRequestService::class)
        ->requestArchivedDuplicates(
            collect([$archivedDuplicate, $active, $archivedUnique]),
            (int) $import->created_by,
            'Remove archived duplicate SKU product.',
        );

    expect($summary)->toBe([
        'selected' => 3,
        'requested' => 1,
        'skipped_ineligible' => 2,
        'skipped_existing' => 0,
        'failed' => 0,
    ]);

    $request = DeletionRequest::query()->sole();
    expect($request->deletable_type)->toBe(Product::class)
        ->and((int) $request->deletable_id)->toBe($archivedDuplicate->id)
        ->and($request->status)->toBe(DeletionRequest::STATUS_PENDING)
        ->and($request->reason)->toBe('Remove archived duplicate SKU product.')
        ->and($request->approvalCount())->toBe(1)
        ->and(Product::query()->whereKey($archivedDuplicate->id)->exists())->toBeTrue();

    $repeat = app(DuplicateSkuDeletionRequestService::class)
        ->requestArchivedDuplicates(
            collect([$archivedDuplicate]),
            (int) $import->created_by,
        );

    expect($repeat['requested'])->toBe(0)
        ->and($repeat['skipped_existing'])->toBe(1)
        ->and(DeletionRequest::query()->count())->toBe(1);
});

function duplicateSkuTestImport(): Import
{
    $user = User::factory()->create();

    return Import::query()->create([
        'filename' => 'duplicate-sku-audit.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
}

function duplicateSkuTestProduct(Import $import, string $handle, string $status): Product
{
    return Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'handle' => $handle,
        'title' => str($handle)->replace('-', ' ')->title()->toString(),
        'status' => $status,
        'approval_version' => 1,
    ]));
}

function duplicateSkuTestVariant(
    Product $product,
    string $sku,
    string $syncState = Variant::SYNC_STATE_SYNCED,
): Variant {
    return Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'sync_state' => $syncState,
    ]));
}
