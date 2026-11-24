<?php

use App\Mail\ImagePublicationReminderMail;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\ComponentStackPropagation;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ImagePublicationReminderService;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function phase6Stack(string $sku, array $componentIds): Product
{
    $stack = createImageImportProduct($sku, ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $sku,
        'status' => 'active',
        'bundle_product_ids' => $componentIds,
    ]));

    return $stack;
}

function phase6ReleaseTestingTransaction(): void
{
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

function phase6Image(Product $product, string $bytes, int $position, ?string $mediaId = null): Image
{
    $asset = createImportTestAsset($bytes, 'jpg', $product->handle.'-'.$position.'.jpg');

    return Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => $position,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => $mediaId,
        'needs_shopify_image_sync' => false,
        'alt_text' => 'Sentence '.$position,
        'alt_text_status' => Image::ALT_APPROVED,
    ]))->fresh('imageAsset');
}

it('swaps inherited photographs when component order is exchanged', function (): void {
    Storage::fake('public');
    $component = createImageImportProduct('SWAP');
    $first = phase6Image($component, 'swap-a', 1);
    $second = phase6Image(createImageImportProduct('SWAP-B'), 'swap-b', 1);
    $stack = phase6Stack('SWAP-STACK', [$component->id, $second->product_id]);

    app(ComponentStackPropagation::class)->stageStack($stack);
    $before = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->orderBy('position')->get();
    expect($before->pluck('position')->map(fn ($position): int => (int) $position)->all())->toBe([1, 2]);
    $ids = $before->pluck('id', 'image_asset_id');

    phase6ReverseComponents($stack, $first, $second);
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());

    $after = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get()->keyBy('image_asset_id');
    expect($after)->toHaveCount(2)
        ->and((int) $after[$first->image_asset_id]->id)->toBe((int) $ids[$first->image_asset_id])
        ->and((int) $after[$second->image_asset_id]->id)->toBe((int) $ids[$second->image_asset_id])
        ->and((int) $after[$first->image_asset_id]->position)->toBe(2)
        ->and((int) $after[$second->image_asset_id]->position)->toBe(1);
});

it('keeps a merged gallery stable and does not reorder it on every synchronization', function (): void {
    Storage::fake('public');
    $component = createImageImportProduct('MERGE');
    $first = phase6Image($component, 'merge-a', 1, 'gid://shopify/MediaImage/11');
    $second = phase6Image(createImageImportProduct('MERGE-B'), 'merge-b', 1, 'gid://shopify/MediaImage/12');
    $stack = phase6Stack('MERGE-STACK', [$component->id, $second->product_id]);
    foreach ([1, 2] as $position) {
        Image::withoutEvents(fn () => Image::create([
            'product_id' => $stack->id,
            'position' => $position,
            'ownership' => ImageOwnership::STACK_OWNED,
            'alt_text' => 'Owned '.$position,
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'shopify_id' => 'gid://shopify/MediaImage/8'.$position,
        ]));
    }
    app(ComponentStackPropagation::class)->stageStack($stack);
    $owned = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::STACK_OWNED)->orderBy('position')->pluck('position')->map(fn ($position): int => (int) $position)->all();
    $inherited = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->orderBy('position')->get();
    expect($owned)->toBe([1, 2])
        ->and($inherited->pluck('image_asset_id')->all())->toBe([$first->image_asset_id, $second->image_asset_id])
        ->and($inherited->pluck('position')->map(fn ($position): int => (int) $position)->all())->toBe([3, 4]);

    foreach ($inherited as $copy) {
        Image::withoutEvents(fn () => $copy->forceFill([
            'shopify_id' => 'gid://shopify/MediaImage/70'.$copy->position,
            'needs_shopify_image_sync' => false,
            'sync_state' => Image::SYNC_STATE_SYNCED,
        ])->save());
        DB::table('stack_image_sync_ledger')->insert([
            'stack_product_id' => $stack->id,
            'image_id' => $copy->id,
            'source_image_id' => $copy->source_image_id,
            'verified_photograph_version' => 1,
            'outcome' => 'verified',
            'shopify_media_id' => $copy->fresh()->shopify_id,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    Product::withoutEvents(fn () => $stack->forceFill(['approval_version' => 1])->save());
    foreach (range(1, 2) as $ignored) {
        DB::table('approvals')->insert([
            'product_id' => $stack->id,
            'user_id' => \App\Models\User::factory()->create()->id,
            'approval_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $reorders = 0;
    config([
        'services.shopify.shop' => 'stack-order.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.shopify.api_version' => '2026-01',
    ]);
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$reorders) {
        $query = (string) data_get($request->data(), 'query');
        if (str_contains($query, 'productReorderMedia')) {
            $reorders++;

            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => [
                ['id' => 'gid://shopify/MediaImage/81', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/s/files/owned-a.jpg']],
                ['id' => 'gid://shopify/MediaImage/82', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/s/files/owned-b.jpg']],
                ['id' => 'gid://shopify/MediaImage/703', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/s/files/inherited-a.jpg']],
                ['id' => 'gid://shopify/MediaImage/704', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/s/files/inherited-b.jpg']],
            ], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]);
    });

    expect(app(StackImageSync::class)->plan($stack->fresh())['reorder'])->toBeFalse();
    app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($reorders)->toBe(0)
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::STACK_OWNED)->orderBy('id')->pluck('position')->map(fn ($position): int => (int) $position)->all())->toBe([1, 2]);
});

it('starts a reminder episode from a real edit and again after that episode is resolved', function (): void {
    phase6ReleaseTestingTransaction();
    Mail::fake();
    Storage::fake('public');
    config(['services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za']);
    $product = createImageImportProduct('EPISODE');
    $image = phase6Image($product, 'episode-bytes', 1);

    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Needs review');
    expect($image->fresh()->image_attention_since)->not->toBeNull();
    $this->travel(2)->hours();
    $first = app(ImagePublicationReminderService::class)->sendDue();
    expect($first['sent'])->toBeTrue()->and($first['kind'])->toBe('initial');
    $this->travel(25)->hours();
    expect(app(ImagePublicationReminderService::class)->sendDue()['kind'])->toBe('follow_up');

    Image::withoutEvents(fn () => $image->fresh()->forceFill([
        'alt_text_status' => Image::ALT_PUBLISHED,
        'shopify_alt_pending' => false,
        'needs_shopify_image_sync' => false,
        'shopify_media_create_uncertain' => false,
    ])->save());
    expect($image->fresh()->image_attention_since)->toBeNull();

    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Needs review again');
    $reopenedAt = $image->fresh()->image_attention_since;
    expect($reopenedAt)->not->toBeNull();
    $this->travel(2)->hours();
    $again = app(ImagePublicationReminderService::class)->sendDue();

    expect($again['sent'])->toBeTrue()
        ->and($again['kind'])->toBe('initial')
        ->and(DB::table('image_publication_reminders')->where('kind', 'initial')->where('status', 'accepted')->count())->toBe(2);
});

it('reminds for a failed synchronization cursor and the latest uncertain ledger only', function (): void {
    Mail::fake();
    config(['services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za']);
    $stack = phase6Stack('CURSOR-REMIND', []);
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => 1,
        'working_generation' => 1,
        'status' => 'failed',
        'approval_request_id' => 44,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    $rows = app(StackImageSync::class)->outstandingReminderRows();
    expect(collect($rows)->pluck('product_id')->all())->toContain($stack->id)
        ->and(collect($rows)->firstWhere('product_id', $stack->id)['actions'])->toContain('Recover the stack image synchronization');

    $other = phase6Stack('LEDGER-REMIND', []);
    DB::table('stack_image_sync_ledger')->insert([
        ['stack_product_id' => $other->id, 'outcome' => 'uncertain', 'detail' => 'Shopify order was not read back.', 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)],
    ]);
    expect(collect(app(StackImageSync::class)->outstandingReminderRows())->pluck('product_id')->all())->toContain($other->id);
    DB::table('stack_image_sync_ledger')->insert([
        ['stack_product_id' => $other->id, 'outcome' => 'verified', 'detail' => 'Recovered.', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
    ]);
    expect(collect(app(StackImageSync::class)->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($other->id);
});

it('keeps an unresolved image failure when a newer image on the stack succeeds', function (): void {
    Storage::fake('public');
    $stack = phase6Stack('LEDGER-IMAGE', []);
    $first = phase6Image($stack, 'ledger-a', 1);
    $second = phase6Image($stack, 'ledger-b', 2);
    $episode = now()->subHours(5);
    DB::table('stack_image_sync_ledger')->insert([
        ['stack_product_id' => $stack->id, 'image_id' => $first->id, 'outcome' => 'failed', 'detail' => 'Image A failed.', 'created_at' => $episode, 'updated_at' => $episode],
        ['stack_product_id' => $stack->id, 'image_id' => $first->id, 'outcome' => 'failed', 'detail' => 'Image A failed again.', 'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4)],
        ['stack_product_id' => $stack->id, 'image_id' => $second->id, 'outcome' => 'verified', 'detail' => 'Image B verified.', 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)],
    ]);

    $rows = collect(app(StackImageSync::class)->outstandingReminderRows())->where('product_id', $stack->id);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['actions'])->toBe(['Recover the stack image synchronization'])
        ->and(\Illuminate\Support\Carbon::parse($rows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($episode->format('Y-m-d H:i:s'));

    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id,
        'image_id' => $first->id,
        'outcome' => 'verified',
        'detail' => 'Image A recovered.',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    expect(collect(app(StackImageSync::class)->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($stack->id);
});

it('keeps photograph and alt failures as separate reminder streams', function (): void {
    Storage::fake('public');
    $stack = phase6Stack('LEDGER-SCOPE', []);
    $image = phase6Image($stack, 'scope-a', 1);
    $other = phase6Image($stack, 'scope-b', 2);
    $sync = app(StackImageSync::class);

    $this->travel(-3)->hours();
    $sync->note($stack, $image, $image, 'failed', 'Photograph failed.', false, null, false, false, 'images');
    $episode = now()->format('Y-m-d H:i:s');
    $this->travel(30)->minutes();
    $sync->note($stack, $image, $image, 'failed', 'Photograph failed again.', false, null, false, false, 'images');
    $this->travel(30)->minutes();
    $sync->note($stack, $image, $image, 'verified', 'Alt text verified without a new photograph upload.', true, null, false, true, 'image_alt');
    $sync->note($stack, null, null, 'verified', 'Image order verified.', true);
    $sync->note($stack, $other, $other, 'verified', 'Photograph verified on Shopify.', true, null, true, false, 'images');
    $this->travel(3)->hours();

    $rows = collect($sync->outstandingReminderRows())->where('product_id', $stack->id);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['actions'])->toBe(['Recover the stack image synchronization'])
        ->and(\Illuminate\Support\Carbon::parse($rows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($episode);

    $sync->note($stack, $image, $image, 'verified', 'Photograph verified on Shopify.', true, null, true, false, 'images');
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($stack->id);

    $altStack = phase6Stack('LEDGER-ALT', []);
    $altImage = phase6Image($altStack, 'scope-alt', 1);
    $level = phase6Stack('LEDGER-LEVEL', []);
    $levelImage = phase6Image($level, 'scope-level', 1);
    $this->travel(-3)->hours();
    $sync->note($altStack, $altImage, $altImage, 'failed', 'Alt text was not verified.', false, null, false, false, 'image_alt');
    $altEpisode = now()->format('Y-m-d H:i:s');
    $this->travel(30)->minutes();
    $sync->note($altStack, $altImage, $altImage, 'failed', 'Alt text was not verified again.', false, null, false, false, 'image_alt');
    $this->travel(30)->minutes();
    $sync->note($altStack, $altImage, $altImage, 'verified', 'Photograph verified on Shopify.', true, null, true, false, 'images');
    $sync->note($level, null, null, 'uncertain', 'Shopify order was not read back.', false);
    $sync->note($level, $levelImage, $levelImage, 'verified', 'Photograph verified on Shopify.', true, null, true, false, 'images');
    $this->travel(3)->hours();

    $altRows = collect($sync->outstandingReminderRows())->where('product_id', $altStack->id);
    $eligible = collect($sync->outstandingReminderRows())->pluck('product_id')->all();
    expect($altRows)->toHaveCount(1)
        ->and($altRows->first()['actions'])->toBe(['Recover the stack image synchronization'])
        ->and(\Illuminate\Support\Carbon::parse($altRows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($altEpisode)
        ->and($eligible)->toContain($level->id)
        ->and($eligible)->not->toContain($stack->id);

    $sync->note($altStack, $altImage, $altImage, 'verified', 'Alt text verified without a new photograph upload.', true, null, false, true, 'image_alt');
    $sync->note($level, null, null, 'verified', 'Image order verified.', true);
    $remaining = collect($sync->outstandingReminderRows())->pluck('product_id')->all();
    expect($remaining)->not->toContain($altStack->id)
        ->and($remaining)->not->toContain($level->id);
});

it('keeps queue dispatch and media order reminders on separate operations', function (): void {
    Storage::fake('public');
    Bus::fake();
    $sync = app(StackImageSync::class);
    $queue = phase6Stack('LEDGER-QUEUE', []);

    $this->travel(-4)->hours();
    $sync->note($queue, null, null, 'failed', 'Queue dispatch failed: connection refused.', false, null, false, false, StackImageSync::OPERATION_QUEUE_DISPATCH);
    $queueEpisode = now()->format('Y-m-d H:i:s');
    $this->travel(30)->minutes();
    $sync->note($queue, null, null, 'failed', 'Queue dispatch failed: connection refused again.', false, null, false, false, StackImageSync::OPERATION_QUEUE_DISPATCH);
    $this->travel(30)->minutes();
    $sync->note($queue, null, null, 'verified', 'Image order verified.', true, null, false, false, StackImageSync::OPERATION_MEDIA_ORDER);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $queue->id,
        'outcome' => 'verified',
        'detail' => 'Recovered.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->travel(4)->hours();

    $queueRows = collect($sync->outstandingReminderRows())->where('product_id', $queue->id);
    expect($queueRows)->toHaveCount(1)
        ->and($queueRows->first()['actions'])->toBe(['Recover the stack image synchronization'])
        ->and(\Illuminate\Support\Carbon::parse($queueRows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($queueEpisode)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $queue->id)->where('outcome', 'failed')->orderBy('id')->value('operation'))->toBe(StackImageSync::OPERATION_QUEUE_DISPATCH);

    $sync->queueStack((int) $queue->id);
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($queue->id)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $queue->id)->where('outcome', 'dispatched')->value('operation'))->toBe(StackImageSync::OPERATION_QUEUE_DISPATCH);

    $order = phase6Stack('LEDGER-ORDER', []);
    $this->travel(-4)->hours();
    $sync->note($order, null, null, 'uncertain', 'Shopify media order was not confirmed.', false, null, false, false, StackImageSync::OPERATION_MEDIA_ORDER);
    $orderEpisode = now()->format('Y-m-d H:i:s');
    $this->travel(30)->minutes();
    $sync->note($order, null, null, 'uncertain', 'Shopify media order was not confirmed again.', false, null, false, false, StackImageSync::OPERATION_MEDIA_ORDER);
    $sync->queueStack((int) $order->id);
    $this->travel(4)->hours();

    $orderRows = collect($sync->outstandingReminderRows())->where('product_id', $order->id);
    expect($orderRows)->toHaveCount(1)
        ->and(\Illuminate\Support\Carbon::parse($orderRows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($orderEpisode);

    $sync->note($order, null, null, 'verified', 'Image order verified.', true, null, false, false, StackImageSync::OPERATION_MEDIA_ORDER);
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($order->id);

    $images = phase6Stack('LEDGER-BOTH', []);
    $image = phase6Image($images, 'both-a', 1);
    $this->travel(-3)->hours();
    $sync->note($images, $image, $image, 'failed', 'Photograph failed.', false, null, false, false, 'images');
    $sync->note($images, $image, $image, 'verified', 'Alt text verified without a new photograph upload.', true, null, false, true, 'image_alt');
    $this->travel(3)->hours();
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->toContain($images->id);

    $sync->note($images, $image, $image, 'verified', 'Photograph verified on Shopify.', true, null, true, false, 'images');
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($images->id);

    $this->travel(-3)->hours();
    $sync->note($images, $image, $image, 'failed', 'Photograph failed.', false, null, false, false, 'images');
    $sync->note($images, $image, $image, 'failed', 'Alt text was not verified.', false, null, false, false, 'image_alt');
    $this->travel(3)->hours();
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->toContain($images->id);

    $sync->note($images, $image, $image, 'verified', 'Shopify read-back matched the authorized photograph and alt text.', true, null, true, true);
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($images->id);
});

/**
 * @return array{stack: Product, first: Image, second: Image, media: array<int, string>}
 */
function phase6PublishedInheritedPair(string $sku): array
{
    Storage::fake('public');
    $component = createImageImportProduct($sku);
    $first = phase6Image($component, $sku.'-a', 1);
    $second = phase6Image(createImageImportProduct($sku.'-B'), $sku.'-b', 1);
    $stack = phase6Stack($sku.'-STACK', [$component->id, $second->product_id]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copies = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get()->keyBy('image_asset_id');
    $media = [
        (int) $first->image_asset_id => 'gid://shopify/MediaImage/501',
        (int) $second->image_asset_id => 'gid://shopify/MediaImage/502',
    ];
    foreach ($copies as $copy) {
        Image::withoutEvents(fn () => $copy->forceFill([
            'shopify_id' => $media[(int) $copy->image_asset_id],
            'needs_shopify_image_sync' => false,
            'sync_state' => Image::SYNC_STATE_SYNCED,
        ])->save());
    }
    Product::withoutEvents(fn () => $stack->forceFill(['approval_version' => 1])->save());
    foreach (range(1, 2) as $ignored) {
        DB::table('approvals')->insert([
            'product_id' => $stack->id,
            'user_id' => \App\Models\User::factory()->create()->id,
            'approval_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    config([
        'services.shopify.shop' => 'stack-order.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.shopify.api_version' => '2026-01',
    ]);

    return ['stack' => $stack->fresh(), 'first' => $first->fresh(), 'second' => $second->fresh(), 'media' => $media];
}

function phase6ReverseComponents(Product $stack, Image $first, Image $second): void
{
    $draft = NewProductDraft::query()->where('shopify_id', $stack->shopify_id)->firstOrFail();
    NewProductDraft::withoutEvents(fn () => $draft->forceFill([
        'bundle_product_ids' => [$second->product_id, $first->product_id],
    ])->save());
}

it('reorders published Shopify media once when inherited photographs exchange positions', function (): void {
    $fixture = phase6PublishedInheritedPair('ORDER');
    $stack = $fixture['stack'];
    $first = $fixture['first'];
    $second = $fixture['second'];
    $copies = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get()->keyBy('image_asset_id');
    $identity = [];
    foreach ($copies as $copy) {
        $identity[(int) $copy->id] = [
            'shopify_id' => (string) $copy->shopify_id,
            'photograph_version' => (int) $copy->photograph_version,
            'alt_text_version' => (int) $copy->alt_text_version,
        ];
    }
    $authorizations = DB::table('component_image_authorizations')->count();
    phase6ReverseComponents($stack, $first, $second);
    $remote = [$fixture['media'][(int) $first->image_asset_id], $fixture['media'][(int) $second->image_asset_id]];
    $reorders = 0;
    Http::fake(function ($request) use (&$remote, &$reorders) {
        $query = (string) data_get($request->data(), 'query');
        if (str_contains($query, 'productReorderMedia')) {
            $reorders++;
            $remote = array_reverse($remote);

            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }
        $nodes = array_map(fn (string $id): array => [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'image' => ['url' => 'https://cdn.shopify.com/s/files/'.md5($id).'.jpg'],
        ], $remote);

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]);
    });

    app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    $desired = Image::query()->where('product_id', $stack->id)->orderBy('position')->orderBy('id')->pluck('shopify_id')->map(fn ($id): string => (string) $id)->all();
    expect($reorders)->toBe(1)
        ->and($remote)->toBe($desired)
        ->and($desired)->toBe([$fixture['media'][(int) $second->image_asset_id], $fixture['media'][(int) $first->image_asset_id]]);

    app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    expect($reorders)->toBe(1)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('detail', 'Image order verified.')->count())->toBe(1);

    $after = Image::query()->whereIn('id', array_keys($identity))->get()->keyBy('id');
    foreach ($identity as $id => $row) {
        expect((string) $after[$id]->shopify_id)->toBe($row['shopify_id'])
            ->and((int) $after[$id]->photograph_version)->toBe($row['photograph_version'])
            ->and((int) $after[$id]->alt_text_version)->toBe($row['alt_text_version']);
    }
    expect(DB::table('component_image_authorizations')->count())->toBe($authorizations);
});

it('records an incomplete Shopify media read as uncertain and does not reorder', function (): void {
    $fixture = phase6PublishedInheritedPair('UNREAD');
    $first = $fixture['first'];
    $second = $fixture['second'];
    phase6ReverseComponents($fixture['stack'], $first, $second);
    $reorders = 0;
    Http::fake(function ($request) use (&$reorders) {
        $query = (string) data_get($request->data(), 'query');
        if (str_contains($query, 'productReorderMedia')) {
            $reorders++;

            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => null]],
        ]]]);
    });

    $result = app(StackImageSync::class)->synchronizeStack((int) $fixture['stack']->id);

    expect($result['outcome'])->toBe('uncertain')
        ->and($reorders)->toBe(0)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $fixture['stack']->id)->where('outcome', 'verified')->where('detail', 'Image order verified.')->count())->toBe(0)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $fixture['stack']->id)->where('outcome', 'uncertain')->where('detail', 'like', '%incomplete%')->count())->toBe(1);
});

final class Phase6ReenterTransport extends AbstractTransport
{
    public static int $sends = 0;

    protected function doSend(SentMessage $message): void
    {
        self::$sends++;
        if (self::$sends === 1) {
            app(ImagePublicationReminderService::class)->sendDue();
        }
    }

    public function __toString(): string
    {
        return 'reenter';
    }
}

it('does not send a second reminder while the first delivery is still unrecorded', function (): void {
    phase6ReleaseTestingTransaction();
    Phase6ReenterTransport::$sends = 0;
    Mail::extend('reenter', fn () => new Phase6ReenterTransport);
    config([
        'mail.default' => 'reenter',
        'mail.mailers.reenter' => ['transport' => 'reenter'],
        'services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za',
    ]);
    $product = createImageImportProduct('RACE');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'alt_text' => 'Waiting',
        'needs_shopify_image_sync' => true,
        'image_attention_since' => now()->subHours(2),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));

    $result = app(ImagePublicationReminderService::class)->sendDue();

    expect($result['sent'])->toBeTrue()
        ->and(Phase6ReenterTransport::$sends)->toBe(1)
        ->and(DB::table('image_publication_reminders')->where('product_id', $product->id)->where('kind', 'initial')->where('status', 'accepted')->count())->toBe(1);
});

final class Phase6UncertainTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new RuntimeException('The mailer accepted the message and then lost the result.');
    }

    public function __toString(): string
    {
        return 'uncertain';
    }
}

it('does not retry a reminder whose delivery outcome is uncertain', function (): void {
    phase6ReleaseTestingTransaction();
    Mail::extend('uncertain', fn () => new Phase6UncertainTransport);
    config([
        'mail.default' => 'uncertain',
        'mail.mailers.uncertain' => ['transport' => 'uncertain'],
        'services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za',
    ]);
    app('mail.manager')->purge('uncertain');
    $product = createImageImportProduct('UNSURE');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'alt_text' => 'Waiting',
        'needs_shopify_image_sync' => true,
        'image_attention_since' => now()->subHours(2),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));

    $first = app(ImagePublicationReminderService::class)->sendDue();
    Mail::fake();
    config(['mail.default' => 'array']);
    $second = app(ImagePublicationReminderService::class)->sendDue();

    expect($first['sent'])->toBeFalse()
        ->and(DB::table('image_publication_reminders')->where('product_id', $product->id)->where('kind', 'initial')->value('status'))->toBe('uncertain')
        ->and($second['sent'])->toBeFalse()
        ->and(Mail::sent(ImagePublicationReminderMail::class))->toHaveCount(0);
});

it('does not send or claim a reminder inside an open database transaction', function (): void {
    phase6ReleaseTestingTransaction();
    Mail::fake();
    config(['services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za']);
    $product = createImageImportProduct('OUTER');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'alt_text' => 'Waiting',
        'needs_shopify_image_sync' => true,
        'image_attention_since' => now()->subHours(2),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));

    DB::beginTransaction();
    $rejected = app(ImagePublicationReminderService::class)->sendDue();
    expect($rejected['sent'])->toBeFalse()
        ->and(DB::table('image_publication_reminders')->where('product_id', $product->id)->count())->toBe(0)
        ->and(Mail::sent(ImagePublicationReminderMail::class))->toHaveCount(0);
    DB::rollBack();

    expect(app(ImagePublicationReminderService::class)->sendDue()['sent'])->toBeTrue()
        ->and(app(ImagePublicationReminderService::class)->sendDue()['sent'])->toBeFalse()
        ->and(DB::table('image_publication_reminders')->where('product_id', $product->id)->where('kind', 'initial')->where('status', 'accepted')->count())->toBe(1)
        ->and(Mail::sent(ImagePublicationReminderMail::class))->toHaveCount(1);
});

it('keeps a gallery read failure after an authorization hold or local rebuild', function (): void {
    $stack = phase6Stack('LEDGER-GALLERY', []);
    $sync = app(StackImageSync::class);
    $this->travel(-4)->hours();
    $sync->note($stack, null, null, 'uncertain', 'Shopify gallery evidence is incomplete. Nothing was marked verified.', false, null, false, false, StackImageSync::OPERATION_GALLERY_READ);
    $episode = now()->format('Y-m-d H:i:s');
    $this->travel(30)->minutes();
    $sync->note($stack, null, null, 'uncertain', 'Shopify gallery evidence is incomplete. Nothing was marked verified. The connection was still incomplete.', false, null, false, false, StackImageSync::OPERATION_GALLERY_READ);
    $this->travel(30)->minutes();
    $sync->note($stack, null, null, 'shopify_authorization_pending', 'Local gallery is current. Shopify publication is not authorized.', false);
    $sync->note($stack, null, null, 'local_rebuilt', 'Local gallery rebuilt.', false);
    $this->travel(4)->hours();

    $rows = collect($sync->outstandingReminderRows())->where('product_id', $stack->id);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['actions'])->toBe(['Recover the stack image synchronization'])
        ->and(\Illuminate\Support\Carbon::parse($rows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($episode);

    $sync->note($stack, null, null, 'verified', 'Shopify read-back matched the authorized gallery.', true, null, false, false, StackImageSync::OPERATION_GALLERY_READ);
    expect(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->not->toContain($stack->id);
});

it('resolves an identifiable historical gallery failure only after a complete gallery read', function (): void {
    $stack = phase6Stack('LEDGER-HISTORICAL-GALLERY', []);
    $ambiguous = phase6Stack('LEDGER-AMBIGUOUS', []);
    $sync = app(StackImageSync::class);
    $historicalDetail = 'Shopify gallery evidence is incomplete. Nothing was marked verified. Shopify returned an incomplete media connection; no absence or identity decision is safe.';
    $this->travel(-4)->hours();
    $sync->note($stack, null, null, 'uncertain', $historicalDetail, false);
    $historicalId = (int) DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->value('id');
    $episode = now()->format('Y-m-d H:i:s');
    $sync->note($ambiguous, null, null, 'uncertain', 'Stack membership is unknown. Inherited photographs were left in place and Shopify was not changed.', false);
    $this->travel(4)->hours();

    $sync->note($stack, null, null, 'shopify_authorization_pending', 'Local gallery is current. Shopify publication is not authorized.', false);
    $held = collect($sync->outstandingReminderRows())->where('product_id', $stack->id);
    expect($held)->toHaveCount(1)
        ->and(\Illuminate\Support\Carbon::parse($held->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($episode);

    $sync->note($stack, null, null, 'uncertain', 'Shopify gallery evidence is incomplete. Nothing was marked verified. The connection was still incomplete.', false, null, false, false, StackImageSync::OPERATION_GALLERY_READ);
    $incomplete = collect($sync->outstandingReminderRows())->where('product_id', $stack->id);
    expect($incomplete)->toHaveCount(1)
        ->and(\Illuminate\Support\Carbon::parse($incomplete->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($episode);

    $resolve = new \ReflectionMethod(StackImageSync::class, 'resolveOutstandingOperation');
    $resolve->invoke($sync, $stack->fresh(), StackImageSync::OPERATION_GALLERY_READ, 'Shopify read-back matched the authorized gallery.');
    $resolve->invoke($sync, $ambiguous->fresh(), StackImageSync::OPERATION_GALLERY_READ, 'Shopify read-back matched the authorized gallery.');

    $historical = DB::table('stack_image_sync_ledger')->where('id', $historicalId)->first();
    $open = collect($sync->outstandingReminderRows())->pluck('product_id')->all();
    expect($open)->not->toContain($stack->id)
        ->and($open)->toContain($ambiguous->id)
        ->and($historical->operation)->toBeNull()
        ->and($historical->outcome)->toBe('uncertain')
        ->and($historical->detail)->toBe($historicalDetail)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('operation', StackImageSync::OPERATION_GALLERY_READ)->where('outcome', 'verified')->count())->toBe(1)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $ambiguous->id)->where('operation', StackImageSync::OPERATION_GALLERY_READ)->count())->toBe(0);
});

it('resolves a media order failure when a later read already matches', function (): void {
    $fixture = phase6PublishedInheritedPair('MATCH');
    $incomplete = phase6PublishedInheritedPair('UNCONFIRMED');
    $stack = $fixture['stack'];
    $sync = app(StackImageSync::class);
    $desired = [
        $fixture['media'][(int) $fixture['first']->image_asset_id],
        $fixture['media'][(int) $fixture['second']->image_asset_id],
    ];
    $this->travel(-4)->hours();
    $sync->note($stack, null, null, 'failed', 'Queue dispatch failed: connection refused.', false, null, false, false, StackImageSync::OPERATION_QUEUE_DISPATCH);
    $queueEpisode = now()->format('Y-m-d H:i:s');
    $this->travel(30)->minutes();
    $sync->note($stack, null, null, 'uncertain', 'Shopify media order could not be read. Nothing was marked verified.', false, null, false, false, StackImageSync::OPERATION_MEDIA_ORDER);
    $sync->note($incomplete['stack'], null, null, 'uncertain', 'Shopify media order could not be read. Nothing was marked verified.', false, null, false, false, StackImageSync::OPERATION_MEDIA_ORDER);
    $this->travel(4)->hours();

    $reorders = 0;
    Http::fake(function ($request) use (&$reorders, $desired, $incomplete) {
        $query = (string) data_get($request->data(), 'query');
        if (str_contains($query, 'productReorderMedia')) {
            $reorders++;

            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }
        if ((string) data_get($request->data(), 'variables.id') === (string) $incomplete['stack']->shopify_id) {
            return Http::response(['data' => ['product' => [
                'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => null]],
            ]]]);
        }
        $nodes = array_map(fn (string $id): array => [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'image' => ['url' => 'https://cdn.shopify.com/s/files/'.md5($id).'.jpg'],
        ], $desired);

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]);
    });

    $sync->synchronizeStack((int) $stack->id);
    $sync->synchronizeStack((int) $stack->id);
    $rows = collect($sync->outstandingReminderRows())->where('product_id', $stack->id);
    expect($reorders)->toBe(0)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('operation', StackImageSync::OPERATION_MEDIA_ORDER)->where('outcome', 'verified')->count())->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and(\Illuminate\Support\Carbon::parse($rows->first()['attention_since'])->format('Y-m-d H:i:s'))->toBe($queueEpisode);

    $sync->synchronizeStack((int) $incomplete['stack']->id);

    expect($reorders)->toBe(0)
        ->and(collect($sync->outstandingReminderRows())->pluck('product_id')->all())->toContain($incomplete['stack']->id)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $incomplete['stack']->id)->where('operation', StackImageSync::OPERATION_MEDIA_ORDER)->where('outcome', 'verified')->count())->toBe(0);
});
