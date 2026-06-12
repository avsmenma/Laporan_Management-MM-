<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Batch - Sistem Pelaporan LM</title>
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
                    <div class="brand-name">Batch Laporan</div>
                    <div class="brand-sub">Kelola periode 1-12 dan status report</div>
                </div>
            </div>
            <div class="topbar-spacer"></div>
            <div class="topbar-right">
                <a class="topbar-link solid" href="{{ route('reports.index') }}">Report Viewer</a>
            </div>
        </header>

        <main class="app-main page page-narrow">
            @if (session('status'))
                <div class="alert alert-ok" style="margin-bottom:18px">{{ session('status') }}</div>
            @endif

            <section class="panel" style="margin-bottom:20px">
                <div class="panel-head"><span class="panel-title">Buat / Update Batch</span></div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('batches.store') }}" class="grid gap-4 md:grid-cols-4">
                        @csrf
                        <div class="field" style="margin-bottom:0">
                            <label>Tahun</label>
                            <input name="year" type="number" value="2026" class="field-control">
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Bulan</label>
                            <select name="month" class="field-control">
                                @foreach (range(1, 12) as $month)
                                    <option value="{{ $month }}" @selected($month === 5)>{{ $month }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Status</label>
                            <select name="status" class="field-control">
                                <option value="draft">draft</option>
                                <option value="final">final</option>
                                <option value="locked">locked</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button class="btn btn-primary btn-block" type="submit">Simpan</button>
                        </div>
                    </form>
                </div>
            </section>

            @if (auth()->user()?->hasRole('Admin'))
                <section class="panel" style="margin-bottom:20px;border-color:#e6b8b3">
                    <div class="panel-head"><span class="panel-title" style="color:#b42318">⚠️ Hapus Data (Admin)</span></div>
                    <div class="panel-body" x-data="{ mode: 'month', konfirmasi: '' }">
                        <form method="POST" action="{{ route('data.purge') }}"
                              @submit="if (!confirm('Yakin menghapus data? Tindakan ini permanen dan tidak bisa dibatalkan.')) $event.preventDefault()">
                            @csrf
                            <div class="grid gap-4 md:grid-cols-4">
                                <div class="field" style="margin-bottom:0">
                                    <label>Cakupan</label>
                                    <select name="mode" x-model="mode" class="field-control">
                                        <option value="month">Per Bulan</option>
                                        <option value="year">Per Tahun</option>
                                        <option value="all">Semua Data</option>
                                    </select>
                                </div>
                                <div class="field" style="margin-bottom:0" x-show="mode !== 'all'">
                                    <label>Tahun</label>
                                    <select name="year" class="field-control">
                                        @forelse ($batches->pluck('year')->unique()->sortDesc() as $y)
                                            <option value="{{ $y }}">{{ $y }}</option>
                                        @empty
                                            <option value="{{ now()->year }}">{{ now()->year }}</option>
                                        @endforelse
                                    </select>
                                </div>
                                <div class="field" style="margin-bottom:0" x-show="mode === 'month'">
                                    <label>Bulan</label>
                                    <select name="month" class="field-control">
                                        @foreach (range(1, 12) as $m)
                                            <option value="{{ $m }}" @selected($m === 5)>{{ $m }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="field" style="margin-bottom:0">
                                    <label>Ketik <b>HAPUS</b> untuk konfirmasi</label>
                                    <input name="konfirmasi" x-model="konfirmasi" class="field-control" placeholder="HAPUS" autocomplete="off">
                                </div>
                            </div>
                            <button type="submit" class="btn" style="margin-top:14px;background:#b42318;color:#fff;border-color:#b42318"
                                    :disabled="konfirmasi !== 'HAPUS'" :style="konfirmasi !== 'HAPUS' ? 'opacity:.5;cursor:not-allowed' : ''">
                                Hapus Data
                            </button>
                            <p class="field-hint" style="margin-top:10px">
                                Menghapus data laporan & impor sesuai cakupan beserta batch periodenya. <b>Per Bulan</b> tidak menghapus anggaran tahunan (RKAP/RKO/areal). <b>Semua Data</b> mengosongkan seluruh batch &amp; data. Tindakan permanen.
                            </p>
                        </form>
                    </div>
                </section>
            @endif

            <section class="panel" style="overflow:hidden">
                <div class="panel-head"><span class="panel-title">Daftar Batch</span></div>
                <table class="htable">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Tahun</th>
                            <th>Bulan</th>
                            <th>Status</th>
                            <th>Diproses</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr>
                                <td class="file-cell">{{ $batch->code }}</td>
                                <td class="mono">{{ $batch->year }}</td>
                                <td class="mono">{{ $batch->month }}</td>
                                <td>
                                    @php $st = $batch->status; @endphp
                                    <span class="pill {{ $st === 'locked' ? 'pill-info' : ($st === 'final' ? 'pill-ok' : 'pill-idle') }}"><span class="dot"></span>{{ $st }}</span>
                                </td>
                                <td class="mono">{{ optional($batch->processed_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="5">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
