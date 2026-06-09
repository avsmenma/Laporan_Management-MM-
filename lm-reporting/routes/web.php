<?php

use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Import\ImportController;
use App\Http\Controllers\Master\BatchController;
use App\Http\Controllers\Report\ReportViewerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('kebun');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'role:Viewer,Operator,Admin'])->group(function () {
    Route::get('/reports', [ReportViewerController::class, 'index'])->name('reports.index');

    // Halaman Kebun & Pabrik
    Route::get('/kebun', function () {
        return view('kebun.index');
    })->name('kebun');

    Route::get('/pabrik', function () {
        return view('pabrik.index');
    })->name('pabrik');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('api/report')->group(function () {
        Route::get('/lm14', [ReportController::class, 'lm14']);
        Route::get('/lm13', [ReportController::class, 'lm13']);
        Route::get('/lm16', [ReportController::class, 'lm16']);
        Route::get('/drilldown', [ReportController::class, 'drilldown']);
    });
});

Route::middleware(['auth', 'role:Operator,Admin'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::get('/import', [ImportController::class, 'index'])->name('import.index');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');
});
