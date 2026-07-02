<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Snawbar\SelfUpdater\Http\Controllers\SystemUpdateController;

if (config('self-updater.enabled', true)) {
    Route::middleware(['web', 'auth'])->group(function () {
        Route::post('/system/update', [SystemUpdateController::class, 'triggerUpdate'])->name('system.update');

        Route::get('/system/update-progress', function () {
            return response()->json([
                'progress' => (int) Cache::get('update_current_progress', 0),
                'status' => Cache::get('update_current_progress_status', 'idle'),
                'error' => Cache::get('update_current_progress_error', ''),
            ]);
        })->name('system.update-progress');

        Route::post('/system/set-price', [SystemUpdateController::class, 'savePrice'])->name('set_price');
    });
}
