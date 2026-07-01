<?php

use App\Http\Controllers\ProductImageBackupController;
use App\Http\Controllers\ShopifyInventoryLevelWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/product-image-backups/{image}/{filename?}', ProductImageBackupController::class)
    ->where('filename', '.*')
    ->name('product-image-backups.show');

Route::post('/webhooks/shopify/inventory-levels-update', ShopifyInventoryLevelWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.shopify.inventory-levels-update');

require __DIR__ . '/settings.php';
