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
        $this->app->bind(ShopifyGraphqlGateway::class, ShopifyApiClient::class);
        $this->app->scoped(StackInventoryAuditService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
