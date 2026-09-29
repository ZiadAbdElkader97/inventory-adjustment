<?php

use App\Http\Controllers\InventoryAdjustmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('inventories/{inventory}/adjustments', [InventoryAdjustmentController::class, 'store'])
        ->name('inventories.adjustments.store');
});
