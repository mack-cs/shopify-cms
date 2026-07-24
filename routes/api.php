<?php

use App\Http\Controllers\ShopifyAnalyticsExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['analytics.token', 'throttle:10,1'])->prefix('analytics')->group(function (): void {
    Route::get('/order-lines.csv', [ShopifyAnalyticsExportController::class, 'orderLines']);
    Route::get('/products.csv', [ShopifyAnalyticsExportController::class, 'products']);
    Route::get('/inventory-snapshots.csv', [ShopifyAnalyticsExportController::class, 'inventorySnapshots']);
    Route::get('/inventory-events.csv', [ShopifyAnalyticsExportController::class, 'inventoryEvents']);
    Route::get('/stack-components.csv', [ShopifyAnalyticsExportController::class, 'stackComponents']);
});
