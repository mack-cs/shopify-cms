<?php

namespace App\Providers;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Approval;
use App\Models\DropdownOption;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\RequiredField;
use App\Models\ShopifyCollection;
use App\Models\Variant;
use App\Observers\ApprovalObserver;
use App\Observers\DropdownOptionObserver;
use App\Observers\ImageObserver;
use App\Observers\NewProductDraftObserver;
use App\Observers\ProductObserver;
use App\Observers\RequiredFieldObserver;
use App\Observers\ShopifyCollectionObserver;
use App\Observers\VariantObserver;
use App\Services\ShopifyApiClient;
use App\Services\StackInventoryAuditService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\ShopYourVibeEditing::class);
        $this->app->scoped(\App\Services\Procurement\SupplierReceiptMutationAuthorization::class);
        $this->app->bind(ShopifyGraphqlGateway::class, ShopifyApiClient::class);
        $this->app->scoped(StackInventoryAuditService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\ShopifyStackOrderEvent::class, \App\Models\ShopifyFulfillment::class,
            \App\Models\ShopifyStackInventoryReservation::class, \App\Models\ShopifyStackInventoryMovement::class,
            \App\Models\InventoryOperationAudit::class, \App\Models\EngravedBeadFamily::class,
            \App\Models\EngravedBeadOrderLine::class, \App\Models\EngravedBeadSellabilityOperation::class] as $model) {
            $model::saved(function ($record): void {
                if (! config('inventory_monitor.enabled')) {
                    return;
                }
                // Capture this transition even if the next retry succeeds before the minute scan.
                $snapshot = clone $record;
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($snapshot): void {
                    try {
                        app(\App\Services\Shopify\InventoryPipelineHealthCheck::class)->recordChanged($snapshot);
                    } catch (\Throwable $error) {
                        logger()->error('Inventory record monitor failed; scheduler will rescan.', ['error' => $error->getMessage()]);
                    }
                });
            });
        }
        \Illuminate\Support\Facades\Queue::failing(function (\Illuminate\Queue\Events\JobFailed $event): void {
            try {
                app(\App\Services\Shopify\InventoryPipelineHealthCheck::class)->exhausted(
                    $event->job->payload(), $event->job->getQueue(), $event->exception->getMessage(), $event->job->attempts(),
                );
            } catch (\Throwable $error) {
                // Scheduler also inspects failed_jobs if this callback cannot persist an incident.
                logger()->error('Inventory failure monitor callback failed.', ['error' => $error->getMessage()]);
            }
        });
        \Illuminate\Support\Facades\Queue::after(function (\Illuminate\Queue\Events\JobProcessed $event): void {
            if ($event->job->isReleased()) {
                return;
            }
            try {
                app(\App\Services\Shopify\InventoryPipelineHealthCheck::class)->completedJob($event->job->payload(), $event->job->getQueue());
            } catch (\Throwable $error) {
                logger()->error('Inventory recovery monitor callback failed.', ['error' => $error->getMessage()]);
            }
        });
        Approval::observe(ApprovalObserver::class);
        Product::observe(ProductObserver::class);
        Variant::observe(VariantObserver::class);
        Image::observe(ImageObserver::class);
        RequiredField::observe(RequiredFieldObserver::class);
        ShopifyCollection::observe(ShopifyCollectionObserver::class);
        DropdownOption::observe(DropdownOptionObserver::class);
        NewProductDraft::observe(NewProductDraftObserver::class);
    }
}
