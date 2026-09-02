<?php

use App\Http\Controllers\ProductImageBackupController;
use App\Http\Controllers\ShopifyInventoryLevelWebhookController;
use App\Http\Controllers\ShopifyFulfillmentWebhookController;
use App\Http\Controllers\ShopifyProductUpdateWebhookController;
use App\Http\Controllers\ShopifyStackOrderWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/product-image-backups/{image}/{filename?}', ProductImageBackupController::class)
    ->where('filename', '.*')
    ->name('product-image-backups.show');

Route::post('/webhooks/shopify/inventory-levels-update', ShopifyInventoryLevelWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.shopify.inventory-levels-update');

Route::post('/webhooks/shopify/products-update', ShopifyProductUpdateWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.shopify.products-update');

Route::post('/webhooks/shopify/fulfillments', ShopifyFulfillmentWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.shopify.fulfillments');

Route::post('/webhooks/shopify/stack-orders', ShopifyStackOrderWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.shopify.stack-orders');

require __DIR__ . '/settings.php';
