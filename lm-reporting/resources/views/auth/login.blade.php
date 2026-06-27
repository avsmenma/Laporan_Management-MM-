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
    <style>
        /* kartu akun seed yang bisa diklik untuk mengisi email otomatis */
        .da-card[data-email] { cursor: pointer; transition: transform .08s ease, box-shadow .12s ease, border-color .12s ease; }
        .da-card[data-email]:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.12); }
        .da-card[data-email]:active { transform: translateY(0); }
    </style>
</head>
<body>
    <div class="login-stage">
        <aside class="login-brandpane">
            <div class="lb-slideshow" aria-hidden="true">
                <div class="lb-slide is-active" style="background-image:url('{{ asset('images/login/1.png') }}')"></div>
                <div class="lb-slide" style="background-image:url('{{ asset('images/login/2.png') }}')"></div>
                <div class="lb-slide" style="background-image:url('{{ asset('images/login/3.png') }}')"></div>
            </div>
            <div class="lb-scrim" aria-hidden="true"></div>
            <div class="lb-top">
                <div class="lb-mark"><img src="{{ asset('images/logo-ptpn4.png') }}" alt="Logo PTPN IV"></div>
                <div>
                    <div class="brand-name">Sistem Pelaporan LM</div>
                    <div class="brand-sub">PTPN IV Regional V - Report Viewer</div>
                </div>
            </div>
            <div class="lb-mid">
                <div class="lb-kicker">PT Perkebunan Nusantara IV</div>
                <h1 class="lb-title">Report Viewer Biaya Produksi Kebun &amp; Pabrik</h1>
                <p class="lb-desc">Laporan Manajemen biaya produksi Kebun &amp; Pabrik dalam satu tempat — angka selalu terkini, rinciannya bisa ditelusuri, dan siap diekspor kapan saja.</p>
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
                            <input id="email" name="email" type="email" value="{{ old('email', 'admin@lm.test') }}" required autofocus>
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
                        <div class="da-card" data-email="viewer@lm.test" role="button" tabindex="0" title="Isi email dengan viewer@lm.test">
                            <div class="da-av" style="background:var(--ink-500)">V</div>
                            <div>
                                <div class="da-name">Viewer</div>
                                <div class="da-role">viewer@lm.test</div>
                            </div>
                        </div>
                        <div class="da-card" data-email="operator@lm.test" role="button" tabindex="0" title="Isi email dengan operator@lm.test">
                            <div class="da-av" style="background:var(--g-600)">O</div>
                            <div>
                                <div class="da-name">Operator</div>
                                <div class="da-role">operator@lm.test</div>
                            </div>
                        </div>
                        <div class="da-card" data-email="admin@lm.test" role="button" tabindex="0" title="Isi email dengan admin@lm.test">
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

    <script>
        // Slideshow background panel kiri: ganti gambar tiap 3 detik, berurutan sesuai nomor file.
        (function () {
            var slides = document.querySelectorAll('.lb-slideshow .lb-slide');
            if (slides.length < 2) return;
            var i = 0;
            setInterval(function () {
                slides[i].classList.remove('is-active');
                i = (i + 1) % slides.length;
                slides[i].classList.add('is-active');
            }, 3000);
        })();
    </script>

    <script>
        // Klik kartu "Akun seed" → isi otomatis field email sesuai akun yang dipilih.
        (function () {
            var emailInput = document.getElementById('email');
            document.querySelectorAll('.da-card[data-email]').forEach(function (card) {
                var fill = function () {
                    if (!emailInput) return;
                    emailInput.value = card.getAttribute('data-email');
                    emailInput.focus();
                };
                card.addEventListener('click', fill);
                card.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fill(); }
                });
            });
        })();
    </script>
</body>
</html>
