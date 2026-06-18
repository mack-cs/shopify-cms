<?php

use App\Models\Import;
use App\Models\Image;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Models\User;
use App\Notifications\PendingWorkSlackReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists complementary product Shopify gaps in the scheduled Slack reminder', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-products',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/900',
        'handle' => 'gap-report-main',
        'title' => 'Gap Report Main',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    ShopifyAudit::create([
        'product_id' => $product->id,
        'audit_type' => ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS,
        'status' => ShopifyAudit::STATUS_FLAGGED,
        'needs_attention' => true,
        'local_saved_count' => 4,
        'local_valid_count' => 4,
        'shopify_current_count' => 3,
        'shopify_valid_count' => 2,
        'details' => [
            'shopify_ineligible' => [
                [
                    'gid' => 'gid://shopify/Product/901',
                    'handle' => 'inactive-comp',
                    'title' => 'Inactive Complementary',
                    'status' => 'draft',
                    'available' => false,
                    'reason' => 'Shopify status: DRAFT',
                ],
            ],
        ],
        'last_checked_at' => now(),
    ]);

    $payload = (new PendingWorkSlackReminderNotification())
        ->toSlack(new stdClass())
        ->toArray();

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('Complementary gaps')
        ->and($json)->toContain('Products below Shopify complementary target (3 active/sellable)')
        ->and($json)->toContain('Gap Report Main')
        ->and($json)->toContain('gap-report-main')
        ->and($json)->toContain('Shopify 2/3 active/sellable')
        ->and($json)->toContain('local 4/4 eligible backups')
        ->and($json)->toContain('needs 1 more active/sellable Shopify ref')
        ->and($json)->toContain('invalid Shopify ref: Inactive Complementary (Shopify status: DRAFT)');
});

it('lists only active products with missing image alt text in the scheduled Slack reminder', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-products',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $activeMissing = Product::create([
        'import_id' => $import->id,
        'handle' => 'active-missing-alt',
        'title' => 'Active Missing Alt',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $activeComplete = Product::create([
        'import_id' => $import->id,
        'handle' => 'active-complete-alt',
        'title' => 'Active Complete Alt',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $archivedMissing = Product::create([
        'import_id' => $import->id,
        'handle' => 'archived-missing-alt',
        'title' => 'Archived Missing Alt',
        'status' => 'archived',
        'approval_version' => 1,
    ]);

    $unlistedMissing = Product::create([
        'import_id' => $import->id,
        'handle' => 'unlisted-missing-alt',
        'title' => 'Unlisted Missing Alt',
        'status' => 'unlisted',
        'approval_version' => 1,
    ]);

    Image::create([
        'product_id' => $activeMissing->id,
        'src' => 'https://example.com/active-missing-1.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => null,
    ]);

    Image::create([
        'product_id' => $activeMissing->id,
        'src' => 'https://example.com/active-missing-2.jpg',
        'position' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => '   ',
    ]);

    Image::create([
        'product_id' => $activeComplete->id,
        'src' => 'https://example.com/active-complete.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Complete alt text',
    ]);

    Image::create([
        'product_id' => $archivedMissing->id,
        'src' => 'https://example.com/archived-missing.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => null,
    ]);

    Image::create([
        'product_id' => $unlistedMissing->id,
        'src' => 'https://example.com/unlisted-missing.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => null,
    ]);

    $payload = (new PendingWorkSlackReminderNotification())
        ->toSlack(new stdClass())
        ->toArray();

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('Missing alt text')
        ->and($json)->toContain('Active products missing image alt text')
        ->and($json)->toContain('Active Missing Alt')
        ->and($json)->toContain('2 active images missing alt text')
        ->and($json)->not->toContain('Active Complete Alt')
        ->and($json)->not->toContain('Archived Missing Alt')
        ->and($json)->not->toContain('Unlisted Missing Alt');
});
