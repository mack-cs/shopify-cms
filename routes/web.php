<?php

use App\Http\Controllers\ProductImageBackupController;
use Illuminate\Support\Facades\Route;

Route::get('/product-image-backups/{image}/{filename?}', ProductImageBackupController::class)
    ->where('filename', '.*')
    ->name('product-image-backups.show');

require __DIR__ . '/settings.php';
