<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\PermissionEnum;
use App\Filament\Pages\InventoryAuditHistory;
use App\Jobs\ReportDirectShopifyInventoryCorrectionsJob;
use App\Mail\DirectShopifyInventoryCorrectionMail;
use App\Models\ShopifyInventoryAdjustment;
use App\Models\ShopifyInventoryHistorySync;
use App\Models\User;
use App\Services\Inventory\DirectShopifyInventoryCorrectionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('emails direct shopify corrections and copies every active user', function (): void {
    Mail::fake();
    coverCorrectionDays();
    User::factory()->create(['name' => 'Copied Clerk', 'email' => 'copied.clerk@leighavenue.co.za', 'is_active' => true]);
    User::factory()->create(['name' => 'Former Clerk', 'email' => 'former.clerk@leighavenue.co.za', 'is_active' => false]);
    ShopifyInventoryAdjustment::query()->create([
        'adjustment_id' => 'direct-1',
        'occurred_at' => now(),
        'sku' => 'LA0544',
        'product_title' => 'Pearls by the Baltic Necklace',
        'inventory_state' => 'available',
        'delta' => -2,
        'reason' => 'correction',
        'app_name' => 'Shopify',
        'staff_name' => 'Avery Clerk',
        'synced_at' => now(),
    ]);
    ShopifyInventoryAdjustment::query()->create([
        'adjustment_id' => 'cms-1',
        'occurred_at' => now(),
        'sku' => 'CMSONLY',
        'product_title' => 'CMS correction',
        'inventory_state' => 'available',
        'delta' => -8,
        'reason' => 'correction',
        'app_name' => 'la-cms-access',
        'staff_name' => 'CMS Worker',
        'synced_at' => now(),
    ]);
    ShopifyInventoryAdjustment::query()->create([
        'adjustment_id' => 'sale-1',
        'occurred_at' => now(),
        'sku' => 'SALEONLY',
        'reason' => 'purchase',
        'app_name' => 'Online Store',
        'staff_name' => 'Online Store',
        'delta' => -1,
        'synced_at' => now(),
    ]);

    $result = app(DirectShopifyInventoryCorrectionReport::class)->send();

    expect($result['email_sent'])->toBeTrue()
        ->and($result['corrections'])->toBe(1)
        ->and($result['copied_users'])->toBe(1);
    Mail::assertSent(DirectShopifyInventoryCorrectionMail::class, function (DirectShopifyInventoryCorrectionMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('shonaymack@gmail.com')
            && $mail->hasCc('copied.clerk@leighavenue.co.za')
            && ! $mail->hasCc('former.clerk@leighavenue.co.za')
            && str_contains($html, 'Avery Clerk')
            && str_contains($html, 'LA0544')
            && ! str_contains($html, 'CMSONLY')
            && ! str_contains($html, 'SALEONLY');
    });
});

it('loads a missing day from shopify on the queue path and still skips cms corrections', function (): void {
    Mail::fake();
    Cache::flush();
    coverCorrectionDays(exceptToday: true);
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [[
                'second' => now()->toIso8601String(),
                'inventory_adjustment_id' => 'shopify-9',
                'inventory_adjustment_change' => '-3',
                'inventory_state' => 'available',
                'inventory_change_reason' => 'correction',
                'inventory_app_name' => 'Shopify',
                'staff_member_name' => 'Riley Buyer',
                'product_variant_sku' => 'LRB0138',
                'product_variant_title' => 'Evening Protea Bracelet',
            ], [
                'second' => now()->toIso8601String(),
                'inventory_adjustment_id' => 'cms-9',
                'inventory_adjustment_change' => '-8',
                'inventory_state' => 'available',
                'inventory_change_reason' => 'correction',
                'inventory_app_name' => 'la-cms-access',
                'staff_member_name' => 'CMS Worker',
                'product_variant_sku' => 'CMSONLY',
                'reference_document_uri' => 'logistics://shopify-editor/inventory/1',
            ]]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $result = app(DirectShopifyInventoryCorrectionReport::class)->send();

    expect($result['corrections'])->toBe(1)
        ->and(ShopifyInventoryAdjustment::query()->where('adjustment_id', 'shopify-9')->exists())->toBeTrue()
        ->and(ShopifyInventoryAdjustment::query()->where('adjustment_id', 'cms-9')->exists())->toBeTrue();
    Mail::assertSent(DirectShopifyInventoryCorrectionMail::class, function (DirectShopifyInventoryCorrectionMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'Riley Buyer')
            && str_contains($html, 'LRB0138')
            && ! str_contains($html, 'CMSONLY');
    });
});

it('shows the stored 7-day report from the audit page without calling shopify', function (): void {
    Queue::fake();
    Cache::flush();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->never();
    app()->instance(ShopifyGraphqlGateway::class, $gateway);
    $user = User::factory()->create(['email' => 'avery@leighavenue.co.za']);
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    ShopifyInventoryAdjustment::query()->create([
        'adjustment_id' => 'direct-button',
        'occurred_at' => now(),
        'sku' => 'LA0544',
        'product_title' => 'Pearls by the Baltic Necklace',
        'inventory_state' => 'available',
        'delta' => -2,
        'reason' => 'correction',
        'app_name' => 'Shopify',
        'staff_name' => 'Riley Buyer',
        'synced_at' => now(),
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryAuditHistory::class)
        ->call('emailDirectCorrectionReport')
        ->assertSee('Riley Buyer')
        ->assertSee('LA0544')
        ->assertSee('Pearls by the Baltic Necklace')
        ->assertSee('shonaymack@gmail.com');

    Queue::assertPushed(ReportDirectShopifyInventoryCorrectionsJob::class);
});

it('sends nothing when the last 7 days have no direct correction', function (): void {
    Mail::fake();
    coverCorrectionDays();

    $result = app(DirectShopifyInventoryCorrectionReport::class)->send();

    expect($result['email_sent'])->toBeFalse()
        ->and($result['corrections'])->toBe(0);
    Mail::assertNothingSent();
});

function coverCorrectionDays(bool $exceptToday = false): void
{
    for ($day = 0; $day < 7; $day++) {
        if ($exceptToday && $day === 0) {
            continue;
        }
        ShopifyInventoryHistorySync::query()->create([
            'history_date' => now()->subDays($day)->toDateString(),
            'status' => 'succeeded',
            'row_count' => 0,
            'finished_at' => now(),
        ]);
    }
}
