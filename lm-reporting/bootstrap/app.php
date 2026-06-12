<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CATATAN: JANGAN menambahkan StartSession ke grup API tanpa EncryptCookies +
        // AddQueuedCookiesToResponse. Endpoint /api/* (units, batches) publik & stateless.
        // Bila StartSession jalan tanpa enkripsi cookie, ia gagal membaca cookie sesi
        // terenkripsi, membuat sesi tamu baru, lalu MENIMPA cookie sesi pengguna dengan
        // versi tak-terenkripsi → request web berikutnya gagal didekripsi → selalu terlempar
        // ke halaman login setiap ganti menu. Jadi grup API dibiarkan tanpa sesi.

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
