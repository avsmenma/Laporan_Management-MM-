<?php

use App\Http\Controllers\Api\MasterController;
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

// Dropdown data (units, batches) - accessible without auth for easier usage
Route::get('/units', [MasterController::class, 'units']);
Route::get('/batches', [MasterController::class, 'batches']);
