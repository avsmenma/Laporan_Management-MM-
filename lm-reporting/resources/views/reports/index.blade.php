<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistem Pelaporan LM PTPN IV Regional V</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div class="topbar-brand">
                <div class="brand-mark">PN</div>
                <div>
                    <div class="brand-name">PT. Perkebunan Nusantara - Sistem Pelaporan Kebun - MIS</div>
                    <div class="brand-sub">Regional V - fondasi prompt_00</div>
                </div>
            </div>

            <div class="topbar-spacer"></div>

            <div class="topbar-right">
                <span class="role-badge"><span class="dot"></span>{{ auth()->user()->role->name }}</span>
                <div class="user-chip">
                    <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</div>
                    <div class="user-meta">
                        <div class="user-name">{{ auth()->user()->name }}</div>
                        <div class="user-role">{{ auth()->user()->role->name }}</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="topbar-link solid" type="submit">Keluar</button>
                </form>
            </div>
        </header>

        <main class="app-main page">
            <div class="page-head">
                <div>
                    <p class="eyebrow">PT PERKEBUNAN NUSANTARA IV - RPC 5</p>
                    <h1 class="page-title">Report Viewer Biaya Produksi Kebun &amp; Pabrik</h1>
                    <p class="page-desc">Tahun 2026 - Kebun 5E01 - I. DETAIL</p>
                </div>
                <div class="head-actions">
                    <div class="days-strip">
                        <div class="d"><div class="n">31</div><div class="l">Jlh. Hari</div></div>
                        <div class="d"><div class="n">9</div><div class="l">Dijalani</div></div>
                        <div class="d"><div class="n">22</div><div class="l">Sisa</div></div>
                    </div>
                </div>
            </div>

            <div class="report-toolbar" style="margin-bottom:16px">
                <div class="tabs">
                    <a class="tab active" href="#">KEBUN</a>
                    <a class="tab" href="#">PABRIK</a>
                </div>
            </div>

            <div class="panel" x-data="{ komoditas: 'Sawit', periode: 5, unit: '5E01' }">
                <div class="panel-body">
                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="field" style="margin-bottom:0">
                            <label>Komoditas</label>
                            <select x-model="komoditas" class="field-control">
                                <option>Sawit</option>
                                <option>Karet</option>
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Periode</label>
                            <select x-model="periode" class="field-control">
                                @foreach (range(1, 12) as $month)
                                    <option value="{{ $month }}">{{ $month }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Unit</label>
                            <select x-model="unit" class="field-control">
                                <option value="5E01">5E01 - Kebun Gunung Meliau</option>
                                <option value="5E11">5E11 - Kebun Danau Salak</option>
                                <option value="5F01">5F01 - PKS Gunung Meliau</option>
                            </select>
                        </div>
                        <div class="meta-chip" style="height:44px;align-items:flex-start;flex-direction:column;justify-content:center;gap:2px">
                            <span class="tiny muted" style="font-weight:600">Batch</span>
                            <b>Batch #2026-05</b>
                        </div>
                    </div>
                </div>

                <div class="report-bar">
                    <div>
                        <div class="rb-title">LM 14 - Contoh Integrasi Tabulator</div>
                        <div class="rb-sub">Periode <span x-text="periode"></span> - <span x-text="komoditas"></span> - <span x-text="unit"></span></div>
                    </div>
                    <div class="toolspacer"></div>
                    <div class="report-toolbar">
                        <button class="btn btn-outline btn-sm">Excel</button>
                        <button class="btn btn-outline btn-sm">CSV</button>
                        <button class="btn btn-outline btn-sm">PDF</button>
                        <button class="btn btn-outline btn-sm">Cetak</button>
                    </div>
                </div>

                <div class="panel-body" x-data="lmDemoTable" x-init="initTable()">
                    <div x-ref="table"></div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
