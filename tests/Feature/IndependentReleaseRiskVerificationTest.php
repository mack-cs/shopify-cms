<?php

// Original investigation scenarios converted to safe-behavior regression assertions.
use App\Models\{Import, Product, Variant, User, InventoryAdjustmentRequest, ManualStockTransaction, ShopYourVibeDraft, SyvNotification};
use App\Services\{InventoryAdjustmentApprovalService, ProductInventorySyncService, ShopYourVibeWorkflow};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Http, Cache, Mail};

uses(RefreshDatabase::class);

function riskInventoryUser(): User {
    $user=User::factory()->create(['is_active'=>true]);
    Spatie\Permission\Models\Permission::findOrCreate(App\Enums\PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(App\Enums\PermissionEnum::InventoryUpdate->value);
    return $user;
}

function riskVariant(): Variant
{
    $import = Import::create(['filename'=>'risk.csv','mode'=>'overwrite','status'=>'ready','created_by'=>User::factory()->create()->id]);
    $product = Product::withoutEvents(fn()=>Product::create(['import_id'=>$import->id,'shopify_id'=>'gid://shopify/Product/9001','handle'=>'risk','title'=>'Risk','status'=>'active']));
    return Variant::withoutEvents(fn()=>Variant::create(['product_id'=>$product->id,'shopify_id'=>'gid://shopify/ProductVariant/9001','shopify_inventory_item_id'=>'gid://shopify/InventoryItem/9001','sku'=>'RISK','inventory_tracked'=>true,'inventory_qty'=>4,'current_on_hand_quantity'=>4,'current_available_quantity'=>4,'inventory_local_dirty'=>false]));
}

function riskInventoryFake(int $quantity, bool $missingMutationPayload, &$input): void
{
    config(['services.shopify.shop'=>'test-shop.myshopify.com','services.shopify.admin_access_token'=>'fake','services.shopify.inventory_location_id'=>'gid://shopify/Location/1']);
    Http::fake(function($request) use(&$quantity,$missingMutationPayload,&$input) {
        $query=$request->data()['query']??'';
        if(str_contains($query,'query ProductInventoryById')) {
            $level=['location'=>['id'=>'gid://shopify/Location/1'],'quantities'=>[['name'=>'available','quantity'=>$quantity],['name'=>'committed','quantity'=>0],['name'=>'on_hand','quantity'=>$quantity]]];
            $item=['id'=>'gid://shopify/InventoryItem/9001','tracked'=>true,'inventoryLevels'=>['nodes'=>[$level]]];
            $variant=['id'=>'gid://shopify/ProductVariant/9001','sku'=>'RISK','inventoryQuantity'=>$quantity,'inventoryItem'=>$item];
            return Http::response(['data'=>['product'=>['id'=>'gid://shopify/Product/9001','status'=>'ACTIVE','variants'=>['nodes'=>[$variant]]]]]);
        }
        if(str_contains($query,'InventorySetQuantities')) { $input=$request->data()['variables']['input']; if(!$missingMutationPayload) $quantity=$input['quantities'][0]['quantity']; return Http::response(['data'=>$missingMutationPayload?[]:['inventorySetQuantities'=>['inventoryAdjustmentGroup'=>['createdAt'=>now()->toISOString()],'userErrors'=>[]]]]); }
        if(str_contains($query,'InventoryItemTrackingUpdate')) return Http::response(['data'=>['inventoryItemUpdate'=>['userErrors'=>[]]]]);
        if(str_contains($query,'query Locations')) return Http::response(['data'=>['locations'=>['nodes'=>[['id'=>'gid://shopify/Location/1']]]]]);
        throw new RuntimeException('Unexpected fake query: '.$query);
    });
}

it('publishes and confirms the approved bundle target instead of its old quantity', function() {
    Bus::fake(); $variant=riskVariant(); Product::withoutEvents(fn()=>$variant->product->update(['is_bundle'=>true]));
    $service=app(InventoryAdjustmentApprovalService::class); $request=$service->submit([['variant'=>$variant,'inventory_tracked'=>true,'on_hand_quantity'=>9,'reason'=>'Count']],riskInventoryUser()->id,$approver=riskInventoryUser()->id);
    $approved=$service->approve($request,$approver); $input=null; riskInventoryFake(4,false,$input);
    (new App\Jobs\InventorySyncJob([$variant->id],'push',$approver,$approved->sync_batch_id))->handle(app(ProductInventorySyncService::class),$service);
    expect($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPLIED)->and($input['quantities'][0]['quantity'])->toBe(9)->and($request->items()->first()->requested_on_hand_quantity)->toBe(9);
});

it('rejects missing adjustment confirmation and preserves dirty intent without replay', function() {
    $variant=riskVariant(); Variant::withoutEvents(fn()=>$variant->update(['current_on_hand_quantity'=>9,'inventory_local_dirty'=>true]));
    $input=null; riskInventoryFake(4,true,$input);
    $result=app(ProductInventorySyncService::class)->syncVariants(collect([$variant->fresh()]), riskInventoryUser()->id);
    expect($result['failed'])->toBe(1)->and($input['quantities'][0]['changeFromQuantity'])->toBe(4)->and($input['quantities'][0]['quantity'])->toBe(9)->and($variant->fresh()->inventory_local_dirty)->toBeTrue()->and($variant->fresh()->current_on_hand_quantity)->toBe(9);
    $input=null; $retry=app(ProductInventorySyncService::class)->syncVariants(collect([$variant->fresh()]), riskInventoryUser()->id);
    expect($retry['failed'])->toBe(1)->and($input)->toBeNull();
});

it('blocks a status-only reviewer at the UI and inventory approval service', function() {
    Bus::fake(); $variant=riskVariant(); $reviewer=riskInventoryUser();
    $permission=App\Enums\PermissionEnum::InventoryStatusUpdate->value;
    Spatie\Permission\Models\Permission::findOrCreate($permission); $reviewer->givePermissionTo($permission);
    $request=app(InventoryAdjustmentApprovalService::class)->submit([['variant'=>$variant,'inventory_tracked'=>true,'on_hand_quantity'=>9,'reason'=>'Count']],riskInventoryUser()->id,$reviewer->id);
    $reviewer->revokePermissionTo(App\Enums\PermissionEnum::InventoryUpdate->value);
    $this->actingAs($reviewer);
    Livewire\Livewire::test(App\Filament\Resources\InventoryAdjustmentRequestResource\Pages\ListInventoryAdjustmentRequests::class)->assertTableActionHidden('approve',$request);
    expect(fn()=>app(InventoryAdjustmentApprovalService::class)->approve($request,$reviewer->id))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL);
});

it('blocks unprivileged manual stock access at UI service and worker boundaries', function() {
    Bus::fake(); $creator=riskInventoryUser(); $this->actingAs($creator);
    $user=User::factory()->create(['email'=>'risk@leighavenue.co.za','is_active'=>true]);
    $record=ManualStockTransaction::create(['recipient_name'=>'Risk test','transaction_date'=>now(),'transaction_type'=>'other','processing_mode'=>ManualStockTransaction::MODE_PROCESS,'status'=>'draft','created_by'=>$user->id]);
    $variant=riskVariant(); $record->items()->create(['product_id'=>$variant->product_id,'product_title'=>'Risk','quantity'=>1]);
    $shopify=Mockery::mock(App\Services\Shopify\ShopifyInventoryAdjustmentService::class);
    $shopify->shouldReceive('currentQuantities')->once()->andReturn(
        ['location_id'=>'gid://shopify/Location/1','available'=>4,'on_hand'=>4,'committed'=>0],
        ['location_id'=>'gid://shopify/Location/1','available'=>4,'on_hand'=>4,'committed'=>0],
        ['location_id'=>'gid://shopify/Location/1','available'=>3,'on_hand'=>3,'committed'=>0],
    );
    $shopify->shouldNotReceive('decreaseOnHand');
    $service=new App\Services\ManualStockTransactionService($shopify); $record=$service->prepare($record);
    $this->actingAs($user);
    expect($user->getAllPermissions())->toHaveCount(0);
    Livewire\Livewire::test(App\Filament\Resources\ManualStockTransactionResource\Pages\ViewManualStockTransaction::class,['record'=>$record->id])->assertForbidden();
    Bus::assertNotDispatched(App\Jobs\ProcessManualStockTransactionJob::class);
    expect(fn()=>$service->process($record,$user->id))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn()=>(new App\Jobs\ProcessManualStockTransactionJob($record->id,$user->id))->handle($service))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($record->fresh()->status)->toBe('ready')->and($variant->fresh()->current_on_hand_quantity)->toBe(4);
});

it('keeps a stack unavailable when component tracking or quantity is unknown', function($tracked) {
    $records=riskStackRecords(0,true,0);
    Variant::withoutEvents(fn()=>$records['component_variant']->update(['inventory_qty'=>null,'inventory_tracked'=>$tracked]));
    app(App\Services\StackBundleSellabilityService::class)->enforceForAffectedStacks([$records['draft']->id]);
    expect($records['stack_variant']->fresh()->inventory_tracked)->toBeTrue()->and($records['stack_variant']->fresh()->inventory_qty)->toBe(0);
})->with(['unknown tracking'=>[null],'tracked unknown quantity'=>[true]]);

it('verifies daily refresh recovers stack stock when no webhook was received', function() {
    Bus::fake(); $records=riskStackRecords(5,false,null);
    config(['services.shopify.shop'=>'test-shop.myshopify.com','services.shopify.admin_access_token'=>'fake']);
    Http::fake(function($request) use($records) {
        $id=$request->data()['variables']['id']??'';
        $product=$id===$records['component']->shopify_id?$records['component']:$records['stack'];
        $variant=$product->id===$records['component']->id?$records['component_variant']:$records['stack_variant'];
        return Http::response(['data'=>['product'=>['id'=>$product->shopify_id,'status'=>'ACTIVE','variants'=>['nodes'=>[['id'=>$variant->shopify_id,'sku'=>$variant->sku,'inventoryQuantity'=>0,'inventoryItem'=>['id'=>$variant->shopify_inventory_item_id,'tracked'=>$product->id===$records['component']->id]]]]]]]);
    });
    Illuminate\Support\Facades\Notification::fake();
    $notifier=app(App\Services\StackSellabilitySlackNotifier::class);
    (new App\Jobs\DailyShopifyInventoryRefreshJob)->handle(app(ProductInventorySyncService::class),app(App\Services\StackBundleSellabilityService::class),app(App\Services\StackSellabilityShopifyPushService::class),$notifier);
    expect($records['component_variant']->fresh()->inventory_qty)->toBe(0)->and($records['stack_variant']->fresh()->inventory_tracked)->toBeTrue()->and($records['stack_variant']->fresh()->inventory_qty)->toBe(0);
    Bus::assertDispatched(App\Jobs\InventorySyncJob::class);
});

it('blocks replacement receipts while the earlier Shopify outcome is uncertain', function() {
    Bus::fake(); $variant=riskVariant();
    $line=app(App\Services\Procurement\SupplierOrderService::class)->createForVariant($variant,'PO-RISK',5,'2026-11-10');
    $service=app(App\Services\Procurement\SupplierReceiptService::class);
    $first=$service->create($line,5,'risk-first',dispatch:false);
    $first->update(['status'=>'manual_review','shopify_adjustment_started_at'=>now()]);
    expect(fn()=>$service->create($line,5,'risk-second',dispatch:false))->toThrow(Illuminate\Validation\ValidationException::class,'uncertain Shopify outcome');
    expect($line->receipts()->sum('quantity_received'))->toBe(5);
});

it('verifies an accepted receipt with a lost response is quarantined without automatic replay', function() {
    Bus::fake(); $variant=riskVariant();
    $line=app(App\Services\Procurement\SupplierOrderService::class)->createForVariant($variant,'PO-UNCERTAIN',5,'2026-11-10');
    $receipt=app(App\Services\Procurement\SupplierReceiptService::class)->create($line,5,'risk-uncertain',dispatch:false);
    $shopify=Mockery::mock(App\Services\Shopify\ShopifyInventoryAdjustmentService::class);
    $shopify->shouldReceive('resolveLocationId')->once()->andReturn('gid://shopify/Location/1');
    $shopify->shouldReceive('increaseAvailable')->once()->andThrow(new RuntimeException('Accepted delta but response lost'));
    $job=new App\Jobs\ProcessSupplierReceiptJob($receipt->id);
    $run=fn()=>$job->handle($shopify,app(ProductInventorySyncService::class),app(App\Services\Procurement\SupplierOrderSummaryService::class),app(App\Services\GoogleSheets\ProcurementSheetSyncService::class));
    expect($run)->toThrow(RuntimeException::class,'response lost'); $run();
    expect($receipt->fresh()->status)->toBe('manual_review')->and($receipt->fresh()->shopify_adjustment_started_at)->not->toBeNull();
});

function riskSyvFixture($test): array
{
    $fake=new Tests\Support\VibeShopifyFake;
    app()->instance(App\Contracts\ShopifyGraphqlGateway::class,$fake);
    Spatie\Permission\Models\Role::findOrCreate(App\Enums\RolesEnum::Admin->value);
    $actor=User::factory()->create(['is_active'=>true]); $actor->assignRole(App\Enums\RolesEnum::Admin->value); $test->actingAs($actor);
    $workflow=app(ShopYourVibeWorkflow::class); $editing=app(App\Services\ShopYourVibeEditing::class);
    $gid='gid://shopify/Collection/1'; $draft=$workflow->open($gid);
    return compact('fake','actor','workflow','editing','gid','draft');
}

it('quarantines parent async uncertainty despite a matching Shopify snapshot', function() {
    extract(riskSyvFixture($this));
    $fake=new class extends Tests\Support\VibeShopifyFake {
        public bool $acceptedPending=false;
        public function graphql(string $query,array $variables=[]): array {
            $result=parent::graphql($query,$variables);
            if(str_contains($query,'mutation VibeReorderProducts')) { $this->acceptedPending=true; throw new RuntimeException('Accepted async operation but response lost'); }
            return $result;
        }
    };
    app()->instance(App\Contracts\ShopifyGraphqlGateway::class,$fake);
    $workflow=app(ShopYourVibeWorkflow::class);
    // A persisted legacy order has no per-product explicit sort rows. The parent still accepts it.
    $card=$draft->desired['cards'][0];
    $draft=$workflow->edit($draft->id,$draft->revision,'edit_card',['key'=>$card['key'],'name'=>'Parent uncertainty','image'=>$card['image'],'link'=>$card['link']]);
    $desired=$draft->desired; $desired['collections'][$gid]['products']=array_reverse($desired['collections'][$gid]['products']);
    $draft->update(['desired'=>$desired,'pending'=>true,'status'=>'pending']);
    $draft=$workflow->queue($draft->id,$draft->revision); $workflow->push($draft->id,$draft->editing_state['push']);
    expect($draft->fresh()->status)->toBe('failed')->and($draft->fresh()->remote_jobs[$gid])->toStartWith('unconfirmed:')->and($fake->acceptedPending)->toBeTrue();
    $draft=$workflow->queue($draft->id,$draft->fresh()->revision); $workflow->push($draft->id,$draft->editing_state['push']);
    expect($draft->fresh()->status)->toBe('failed')->and($fake->acceptedPending)->toBeTrue();
    expect(collect($fake->calls)->filter(fn($call)=>str_contains($call[0],'mutation VibeReorderProducts')))->toHaveCount(1);
    expect(collect($fake->calls)->filter(fn($call)=>str_contains($call[0],'query VibeJob')))->toHaveCount(0);
});

it('keeps a coalesced inventory event durable and dispatches pending reconciliation', function() {
    $service=app(App\Services\InventoryLevelReconciliation::class); $service->record('9001');
    $lock=new Illuminate\Bus\UniqueLock(Cache::store());
    $first=new App\Jobs\HandleShopifyInventoryLevelUpdatedJob('9001',['available'=>1],'old-event');
    $second=new App\Jobs\HandleShopifyInventoryLevelUpdatedJob('9001',['available'=>0],'new-event');
    expect($lock->acquire($first))->toBeTrue()->and($lock->acquire($second))->toBeFalse();
    $service->record('9001');
    $lock->release($first);
    Bus::fake(); $service->dispatchPending();
    Bus::assertDispatched(App\Jobs\HandleShopifyInventoryLevelUpdatedJob::class,fn($job)=>$job->inventoryItemId==='9001');
    expect(Illuminate\Support\Facades\DB::table('inventory_reconciliations')->first()->version)->toBe(2);
});

it('recovers a crashed scheduler mutex before the next five minute retry', function() {
    $event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='stack-sellability-retries');
    expect($event)->not->toBeNull()->and($event->expiresAt)->toBe(2)->and($event->onOneServer)->toBeTrue();
    expect($event->mutex->create($event))->toBeTrue();
    $this->travel(121)->seconds(); expect($event->mutex->exists($event))->toBeFalse();
    expect($event->shouldSkipDueToOverlapping())->toBeFalse();
    $event->mutex->forget($event);
});

it('executes the actual registered reconciliation scheduler callback', function($name,$class) {
    Bus::fake();
    $event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description===$name);
    $event->run(app());
    expect($event->exitCode)->toBe(0);
})->with([
    ['stack-sellability-retries','StackSellabilityShopifyPushService'],
    ['engraved-bead-reconciliation','EngravedBeadRecalculation'],
]);

function riskSort($draft,$workflow,$editing,string $gid): ShopYourVibeDraft
{
    $editing->token=null; $scope=$editing::sortScope($gid); $draft=$editing->acquireScope($draft->id,0,$scope);
    return $workflow->edit($draft->id,$draft->revision,'reorder_products',['collection_gid'=>$gid,'scope_revision'=>0,'ids'=>array_reverse(array_column($draft->desired['collections'][$gid]['products'],'id')),'explicit_ids'=>['gid://shopify/Product/101']]);
}

it('publishes layout successfully while preserving an independently held sort', function() {
    extract(riskSyvFixture($this)); $draft=riskSort($draft,$workflow,$editing,$gid);
    $editing->token=null; $card=$draft->desired['cards'][0];
    $draft=$workflow->edit($draft->id,$draft->revision,'edit_card',['key'=>$card['key'],'name'=>'Risk renamed card','image'=>$card['image']??'','link'=>$card['link']]);
    $draft=$workflow->queue($draft->id,$draft->revision); $workflow->push($draft->id,$draft->editing_state['push']);
    expect($draft->fresh()->status)->toBe('pending')->and($draft->fresh()->last_error)->toBeNull();
    expect(array_column($draft->fresh()->desired['collections'][$gid]['products'],'id'))->not->toBe(array_column($draft->fresh()->snapshot['collections'][$gid]['products'],'id'));
    expect(collect(app(App\Services\ShopYourVibeShopify::class)->parent($gid)['cards'])->pluck('name')->all())->toContain('Risk renamed card');
    expect(collect($fake->calls)->filter(fn($call)=>str_contains($call[0],'VibeReorderProducts')))->toHaveCount(0);
});

it('creates a distinct approval notification for each independently submitted collection', function() {
    Bus::fake(); extract(riskSyvFixture($this)); $other='gid://shopify/Collection/2';
    $desired=$draft->desired; $snapshot=$draft->snapshot;
    $desired['collections'][$other]=$snapshot['collections'][$other]=app(App\Services\ShopYourVibeShopify::class)->collection($other);
    $draft->update(['desired'=>$desired,'snapshot'=>$snapshot]);
    $reviewer=User::factory()->create(['is_active'=>true]); $reviewer->assignRole(App\Enums\RolesEnum::Admin->value);
    foreach([$gid,$other] as $collection) {
        $draft=riskSort($draft->fresh(),$workflow,$editing,$collection);
        $draft=app(App\Services\ShopYourVibeSorting::class)->submit($draft->id,$draft->revision,$reviewer->id,$collection,1);
    }
    expect(App\Models\SyvCollectionSortState::where('status',App\Models\SyvCollectionSortState::AWAITING)->count())->toBe(2)->and(SyvNotification::count())->toBe(2);
});

it('recovers a terminally failed parent after its worker lease expires without discarding work', function() {
    $ticket=['id'=>'test-ticket','revision'=>1];
    $draft=ShopYourVibeDraft::create(['collection_gid'=>'gid://shopify/Collection/1','snapshot'=>[],'desired'=>[],'pending'=>true,'status'=>'pushing','revision'=>1,'editing_state'=>['push'=>$ticket]]);
    Cache::put('shop-your-vibe-op:'.$draft->collection_gid,'dead-worker',180);
    (new App\Jobs\PushShopYourVibe($draft->id,$ticket))->failed(new RuntimeException('Final attempt worker died'));
    $this->travel(181)->seconds();
    $event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='syv-parent-recovery');
    $event->run(app());
    expect(Cache::has('shop-your-vibe-op:'.$draft->collection_gid))->toBeFalse()->and($draft->fresh()->status)->toBe('failed')->and($draft->fresh()->pending)->toBeTrue()->and($draft->fresh()->snapshot)->toBe([])->and($draft->fresh()->desired)->toBe([]);
});

it('claims notification delivery before sending so a concurrent handler cannot send twice', function() {
    config(['shop_your_vibe.sort_approval_emails'=>true]);
    $reviewer=User::factory()->create();
    $notification=SyvNotification::create(['idempotency_key'=>'risk-mail','type'=>SyvNotification::TYPE_SORT_APPROVAL,'status'=>SyvNotification::PENDING,'payload'=>['reviewer_id'=>$reviewer->id,'submitter_name'=>'Staff','collection_name'=>'Test','submitted_at'=>now()->toISOString(),'moved_count'=>1,'status'=>'Awaiting approval','review_url'=>'/shop-your-vibe']]);
    $deliveries=0;
    Mail::shouldReceive('to')->once()->andReturnUsing(function() use(&$deliveries,$notification) { return new class($deliveries,$notification->id) {
        public function __construct(public int &$deliveries,public int $id) {}
        public function send($mail): void { if(++$this->deliveries===1) (new App\Jobs\SendSyvSortApprovalEmailJob($this->id))->handle(); }
    }; });
    (new App\Jobs\SendSyvSortApprovalEmailJob($notification->id))->handle();
    expect($deliveries)->toBe(1)->and($notification->fresh()->attempts)->toBe(1);
});

function riskStackRecords(int $componentQty, bool $stackTracked, ?int $stackQty): array
{
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'inventory-webhook-stack.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    $stack = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/900000001',
        'handle' => 'test-webhook-stack',
        'title' => 'Test Webhook Stack',
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));

    $stackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $stack->id,
        'shopify_id' => 'gid://shopify/ProductVariant/900000001',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/987654321',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'TEST-WEBHOOK-STACK',
        'inventory_tracked' => $stackTracked,
        'inventory_qty' => $stackQty,
        'inventory_local_dirty' => false,
    ]));

    $component = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/900000002',
        'handle' => 'test-webhook-component',
        'title' => 'Test Webhook Component',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => false,
        'approval_version' => 1,
    ]));

    $componentVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $component->id,
        'shopify_id' => 'gid://shopify/ProductVariant/900000002',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/123456789',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'TEST-WEBHOOK-COMPONENT',
        'inventory_tracked' => true,
        'inventory_qty' => $componentQty,
        'inventory_local_dirty' => false,
    ]));

    $draft = \App\Models\NewProductDraft::withoutEvents(fn (): \App\Models\NewProductDraft => \App\Models\NewProductDraft::create([
        'sku' => 'TEST-WEBHOOK-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => $stackTracked === false ? null : $stackQty,
        'bundle_product_ids' => [$component->id],
        'approval_version' => 1,
        'origin' => \App\Models\NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    return [
        'import' => $import,
        'draft' => $draft,
        'stack' => $stack,
        'stack_variant' => $stackVariant,
        'component' => $component,
        'component_variant' => $componentVariant,
    ];
}

it('drains an inventory change arriving after a running webhook worker read its snapshot', function() {
    Bus::fake(); Illuminate\Support\Facades\Notification::fake();
    $records=riskStackRecords(5,false,null); $id='123456789';
    $reconcile=app(App\Services\InventoryLevelReconciliation::class); $reconcile->record($id);
    $unique=new Illuminate\Bus\UniqueLock(Cache::store()); $job=new App\Jobs\HandleShopifyInventoryLevelUpdatedJob($id); $unique->acquire($job);
    config(['services.shopify.shop'=>'test.myshopify.com','services.shopify.admin_access_token'=>'fake']);
    $reads=0;
    Http::fake(function($request) use($records,$reconcile,$id,$unique,&$reads) {
        expect($request['query'])->toContain('query ProductInventoryById');
        if(++$reads===1) {
            $reconcile->record($id);
            expect($unique->acquire(new App\Jobs\HandleShopifyInventoryLevelUpdatedJob($id)))->toBeFalse();
        }
        $quantity=$reads===1?5:0; $component=$records['component']; $variant=$records['component_variant'];
        return Http::response(['data'=>['product'=>['id'=>$component->shopify_id,'status'=>'ACTIVE','variants'=>['nodes'=>[['id'=>$variant->shopify_id,'sku'=>$variant->sku,'inventoryQuantity'=>$quantity,'inventoryItem'=>['id'=>$variant->shopify_inventory_item_id,'tracked'=>true]]]]]]]);
    });
    $run=fn()=>$job->handle(app(App\Services\StackBundleSellabilityService::class),app(App\Services\StackSellabilityShopifyPushService::class),app(App\Services\StackSellabilitySlackNotifier::class));
    $run(); $unique->release($job);
    $row=Illuminate\Support\Facades\DB::table('inventory_reconciliations')->first();
    expect($row->version)->toBe(2)->and($row->processed_version)->toBe(1);
    $event=collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn($event)=>$event->description==='inventory-level-reconciliation'); $event->run(app());
    Bus::assertDispatched(App\Jobs\HandleShopifyInventoryLevelUpdatedJob::class);
    $run(); $row=Illuminate\Support\Facades\DB::table('inventory_reconciliations')->first();
    expect($row->processed_version)->toBe(2)->and($records['stack_variant']->fresh()->inventory_tracked)->toBeTrue()->and($records['stack_variant']->fresh()->inventory_qty)->toBe(0);
});

it('does not restore a stack from stale positive stock after the daily component refresh failed', function() {
    Bus::fake(); Illuminate\Support\Facades\Notification::fake(); $records=riskStackRecords(5,true,0);
    config(['services.shopify.shop'=>'test.myshopify.com','services.shopify.admin_access_token'=>'fake']);
    Http::fake(function($request) use($records) {
        if(($request['variables']['id']??'')===$records['component']->shopify_id) throw new RuntimeException('Component inventory read unavailable');
        $variant=$records['stack_variant'];
        return Http::response(['data'=>['product'=>['id'=>$records['stack']->shopify_id,'status'=>'ACTIVE','variants'=>['nodes'=>[['id'=>$variant->shopify_id,'sku'=>$variant->sku,'inventoryQuantity'=>0,'inventoryItem'=>['id'=>$variant->shopify_inventory_item_id,'tracked'=>true]]]]]]]);
    });
    (new App\Jobs\DailyShopifyInventoryRefreshJob)->handle(app(ProductInventorySyncService::class),app(App\Services\StackBundleSellabilityService::class),app(App\Services\StackSellabilityShopifyPushService::class),app(App\Services\StackSellabilitySlackNotifier::class));
    expect($records['stack_variant']->fresh()->inventory_tracked)->toBeTrue()->and($records['stack_variant']->fresh()->inventory_qty)->toBe(0);
    expect(Illuminate\Support\Facades\DB::table('inventory_reconciliations')->where('inventory_item_id',$records['component_variant']->shopify_inventory_item_id)->exists())->toBeTrue();
});

