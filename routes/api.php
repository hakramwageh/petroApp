<?php

use App\Http\Controllers\StationController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v0')->group(function (): void {
    Route::post('/transfers', [TransferController::class, 'ingest']);
    Route::get('/stations/{station_id}/summary', [StationController::class, 'summary']);
});
