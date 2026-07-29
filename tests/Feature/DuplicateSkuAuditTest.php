<?php

use App\Mail\DuplicateSkuReminderMail;
use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\DuplicateSkuSlackNotification;
use App\Services\DuplicateSkuAuditService;
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
