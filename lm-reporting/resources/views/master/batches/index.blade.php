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
                <div class="alert" style="margin-bottom:18px;background:#fff4f3;border-color:#e6b8b3;color:#b42318">
                    Untuk menghapus data laporan/impor, buka halaman <a href="{{ route('data.index') }}" style="font-weight:700;color:#b42318;text-decoration:underline">🗑️ Hapus Data</a> (menu di sidebar laporan).
                </div>
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
                            <th>Status Laporan</th>
                            <th>Proses</th>
                            <th>Ubah Status</th>
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
                                <td>
                                    @if ($batch->needs_regenerate)
                                        <span class="pill pill-warn"><span class="dot"></span>Perlu diproses</span>
                                    @else
                                        <span class="pill pill-ok"><span class="dot"></span>Terakhir diproses: {{ $batch->processed_at?->format('Y-m-d H:i') ?? '-' }}</span>
                                    @endif
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('proses-laporan.store') }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Memproses…'">
                                        @csrf
                                        <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                                        <button class="btn btn-primary" style="height:28px;padding:0 10px;font-size:11.5px" type="submit">Proses Laporan</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('batches.store') }}" style="display:inline-flex;gap:6px">
                                        @csrf
                                        <input type="hidden" name="year" value="{{ $batch->year }}">
                                        <input type="hidden" name="month" value="{{ $batch->month }}">
                                        <button class="btn" style="height:28px;padding:0 10px;font-size:11.5px" name="status" value="final" @disabled($st === 'final')>Final</button>
                                        <button class="btn" style="height:28px;padding:0 10px;font-size:11.5px" name="status" value="locked" @disabled($st === 'locked')>Kunci</button>
                                        <button class="btn" style="height:28px;padding:0 10px;font-size:11.5px" name="status" value="draft" @disabled($st === 'draft')>Draft</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="8">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
