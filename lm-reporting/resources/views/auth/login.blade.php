<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Sistem Pelaporan LM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="login-stage">
        <aside class="login-brandpane">
            <div class="lb-top">
                <div class="lb-mark">PN</div>
                <div>
                    <div class="brand-name">Sistem Pelaporan LM</div>
                    <div class="brand-sub">PTPN IV Regional V - Report Viewer</div>
                </div>
            </div>
            <div class="lb-mid">
                <div class="lb-kicker">PT Perkebunan Nusantara IV</div>
                <h1 class="lb-title">Report Viewer Biaya Produksi Kebun &amp; Pabrik</h1>
                <p class="lb-desc">Laporan Manajemen biaya produksi Kebun &amp; Pabrik, menggantikan workbook Excel LM dengan tampilan yang identik dan kalkulasi yang tepat.</p>
            </div>
            <div class="lb-copy">PTPN IV Regional V &middot; Sistem Pelaporan Kebun - MIS</div>
        </aside>

        <main class="login-formpane">
            <section class="login-card">
                <h1 class="login-h">Masuk ke akun Anda</h1>
                <p class="login-p">Sistem Pelaporan LM PTPN IV Regional V</p>

                @if ($errors->any())
                    <div class="login-err">
                        <span class="dot" style="width:8px;height:8px;border-radius:50%;background:var(--err)"></span>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}">
                    @csrf

                    <div class="field">
                        <label for="email">Email</label>
                        <div class="input">
                            <input id="email" name="email" type="email" value="{{ old('email', 'viewer@lm.test') }}" required autofocus>
                        </div>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input">
                            <input id="password" name="password" type="password" value="password" required>
                        </div>
                    </div>

                    <button class="btn btn-primary btn-block" type="submit">Masuk</button>
                </form>

                <div class="demo-accounts">
                    <div class="da-h">Akun seed</div>
                    <div class="da-grid">
                        <div class="da-card">
                            <div class="da-av" style="background:var(--ink-500)">V</div>
                            <div>
                                <div class="da-name">Viewer</div>
                                <div class="da-role">viewer@lm.test</div>
                            </div>
                        </div>
                        <div class="da-card">
                            <div class="da-av" style="background:var(--g-600)">O</div>
                            <div>
                                <div class="da-name">Operator</div>
                                <div class="da-role">operator@lm.test</div>
                            </div>
                        </div>
                        <div class="da-card">
                            <div class="da-av" style="background:var(--g-800)">A</div>
                            <div>
                                <div class="da-name">Admin</div>
                                <div class="da-role">admin@lm.test</div>
                            </div>
                        </div>
                        <div class="da-card">
                            <div class="da-av" style="background:var(--ink-400)">PW</div>
                            <div>
                                <div class="da-name">Password</div>
                                <div class="da-role">password</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
