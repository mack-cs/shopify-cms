<?php

use App\Http\Controllers\ProcurementPredictionController;
use App\Http\Controllers\ShopifyAnalyticsExportController;
use Illuminate\Support\Facades\Route;

// A procurement run downloads six feeds and then posts its predictions. Keep
// enough authenticated request headroom for retries and closely spaced reruns.
Route::middleware(['analytics.token', 'throttle:60,1'])->prefix('analytics')->group(function (): void {
    Route::get('/order-lines.csv', [ShopifyAnalyticsExportController::class, 'orderLines']);
    Route::get('/products.csv', [ShopifyAnalyticsExportController::class, 'products']);
    Route::get('/inventory-snapshots.csv', [ShopifyAnalyticsExportController::class, 'inventorySnapshots']);
    Route::get('/inventory-events.csv', [ShopifyAnalyticsExportController::class, 'inventoryEvents']);
    Route::get('/stack-components.csv', [ShopifyAnalyticsExportController::class, 'stackComponents']);
    Route::get('/product-movement.csv', [ShopifyAnalyticsExportController::class, 'productMovement']);
    Route::get('/incoming-stock.csv', [ShopifyAnalyticsExportController::class, 'incomingStock']);
    Route::post('/procurement-predictions', [ProcurementPredictionController::class, 'store']);
});
