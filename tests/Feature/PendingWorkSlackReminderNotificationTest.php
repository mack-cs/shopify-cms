<?php

use App\Models\Import;
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
