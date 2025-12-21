<?php

namespace App\Providers;

use App\Models\Image;
use App\Models\Product;
use App\Models\Variant;
use App\Observers\ImageObserver;
use App\Observers\ProductObserver;
use App\Observers\VariantObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        Variant::observe(VariantObserver::class);
        Image::observe(ImageObserver::class);
    }
}
