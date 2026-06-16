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

        // Logout dikecualikan dari verifikasi CSRF agar SELALU berhasil walau token
        // sesi sudah kedaluwarsa — mencegah "419 PAGE EXPIRED" saat user menekan Keluar.
        // Risiko CSRF pada logout minim (paling jauh user di-logout paksa).
        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Token/sesi kedaluwarsa (419) pada form lain (mis. login) → arahkan ke halaman
        // login dengan pesan ramah, bukan halaman "PAGE EXPIRED" yang membingungkan.
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sesi berakhir. Silakan muat ulang.'], 419);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
            ]);
        });
    })->create();
