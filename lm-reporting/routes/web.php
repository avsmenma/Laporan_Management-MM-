<?php

use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Import\ImportController;
use App\Http\Controllers\Master\BatchController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('kebun');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'role:Viewer,Operator,Admin'])->group(function () {
    Route::redirect('/report', '/kebun');
    Route::redirect('/reports', '/kebun')->name('reports.index');

    // Halaman Kebun & Pabrik — submenu LM Investasi (halaman saat ini) & LM Eksploitasi (segera hadir)
    Route::get('/kebun', function () {
        return view('kebun.index');
    })->name('kebun');

    Route::view('/kebun/eksploitasi', 'coming-soon', [
        'judul' => 'LM Eksploitasi — Kebun',
        'subjudul' => 'Laporan LM Eksploitasi Kebun sedang disiapkan dan akan segera tersedia.',
    ])->name('kebun.eksploitasi');

    Route::get('/pabrik', function () {
        return view('pabrik.index');
    })->name('pabrik');

    Route::view('/pabrik/eksploitasi', 'coming-soon', [
        'judul' => 'LM Eksploitasi — Pabrik',
        'subjudul' => 'Laporan LM Eksploitasi Pabrik sedang disiapkan dan akan segera tersedia.',
    ])->name('pabrik.eksploitasi');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('api/report')->group(function () {
        Route::get('/lm14', [ReportController::class, 'lm14']);
        Route::get('/lm13', [ReportController::class, 'lm13']);
        Route::get('/lm16', [ReportController::class, 'lm16']);
        Route::get('/drilldown', [ReportController::class, 'drilldown']);
    });
});

Route::prefix('report-data')->group(function () {
    Route::get('/lm14', [ReportController::class, 'lm14']);
    Route::get('/lm13', [ReportController::class, 'lm13']);
    Route::get('/lm16', [ReportController::class, 'lm16']);
    Route::get('/drilldown', [ReportController::class, 'drilldown']);
});

Route::middleware(['auth', 'role:Operator,Admin'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::get('/import', [ImportController::class, 'index'])->name('import.index');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');
    Route::post('/import/confirm', [ImportController::class, 'confirm'])->name('import.confirm');
    Route::post('/import/cancel', [ImportController::class, 'cancel'])->name('import.cancel');
});
