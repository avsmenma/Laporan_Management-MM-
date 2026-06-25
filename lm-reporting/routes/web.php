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

    // Halaman Kebun & Pabrik — submenu LM Eksploitasi (halaman saat ini) & LM Investasi (segera hadir)
    Route::get('/kebun', function () {
        return view('kebun.index');
    })->name('kebun');

    Route::view('/kebun/investasi', 'coming-soon', [
        'judul' => 'LM Investasi — Kebun',
        'subjudul' => 'Laporan LM Investasi Kebun sedang disiapkan dan akan segera tersedia.',
    ])->name('kebun.investasi');

    Route::get('/pabrik', function () {
        return view('pabrik.index');
    })->name('pabrik');

    Route::view('/pabrik/investasi', 'coming-soon', [
        'judul' => 'LM Investasi — Pabrik',
        'subjudul' => 'Laporan LM Investasi Pabrik sedang disiapkan dan akan segera tersedia.',
    ])->name('pabrik.investasi');

    Route::view('/areal', 'areal.index')->name('areal');

    // Produksi punya submenu: PKS (halaman saat ini) & Kebun (akan datang).
    Route::redirect('/produksi', '/produksi/pks');
    Route::view('/produksi/pks', 'produksi.index')->name('produksi.pks');
    Route::view('/produksi/kebun', 'coming-soon', [
        'judul' => 'Produksi Kebun',
        'subjudul' => 'Laporan Produksi Kebun sedang disiapkan dan akan segera tersedia.',
    ])->name('produksi.kebun');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('api/report')->group(function () {
        Route::get('/lm14', [ReportController::class, 'lm14']);
        Route::get('/lm13', [ReportController::class, 'lm13']);
        Route::get('/lm16', [ReportController::class, 'lm16']);
        Route::get('/drilldown', [ReportController::class, 'drilldown']);
        Route::get('/drilldown-deep', [ReportController::class, 'drilldownDeep']);
    });
});

Route::prefix('report-data')->group(function () {
    Route::get('/lm14', [ReportController::class, 'lm14']);
    Route::get('/lm13', [ReportController::class, 'lm13']);
    Route::get('/lm16', [ReportController::class, 'lm16']);
    Route::get('/drilldown', [ReportController::class, 'drilldown']);
    Route::get('/drilldown-deep', [ReportController::class, 'drilldownDeep']);
    Route::get('/areal', [\App\Http\Controllers\Api\ArealController::class, 'index']);
    Route::get('/areal/ringkasan', [\App\Http\Controllers\Api\ArealController::class, 'ringkasan']);
    Route::get('/produksi', [\App\Http\Controllers\Api\ProduksiController::class, 'index']);
});

Route::middleware(['auth', 'role:Operator,Admin'])->group(function () {
    Route::get('/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::post('/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::get('/import', [ImportController::class, 'index'])->name('import.index');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');
    Route::post('/import/confirm', [ImportController::class, 'confirm'])->name('import.confirm');
    Route::post('/import/cancel', [ImportController::class, 'cancel'])->name('import.cancel');
    Route::get('/import/status/{importJob}', [ImportController::class, 'status'])->name('import.status');

    Route::get('/database', [\App\Http\Controllers\Admin\DatabaseViewerController::class, 'index'])->name('database.index');
    Route::get('/database/data', [\App\Http\Controllers\Admin\DatabaseViewerController::class, 'data'])->name('database.data');

    Route::post('/proses-laporan', [\App\Http\Controllers\Report\ProsesLaporanController::class, 'store'])->name('proses-laporan.store');
});

Route::middleware(['auth', 'role:Admin'])->group(function () {
    Route::get('/data', [\App\Http\Controllers\Admin\DataPurgeController::class, 'index'])->name('data.index');
    Route::post('/data/purge', [\App\Http\Controllers\Admin\DataPurgeController::class, 'purge'])->name('data.purge');
});
