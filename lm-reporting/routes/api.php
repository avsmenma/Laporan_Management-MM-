<?php

use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Endpoint API untuk Report Viewer LM.
| Prefix: /api
|
*/

// Dropdown data (units, batches)
Route::get('/units', [MasterController::class, 'units']);
Route::get('/batches', [MasterController::class, 'batches']);

// Report endpoints (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/report/lm14', [ReportController::class, 'lm14']);
    Route::get('/report/lm13', [ReportController::class, 'lm13']);
    Route::get('/report/lm16', [ReportController::class, 'lm16']);
});
