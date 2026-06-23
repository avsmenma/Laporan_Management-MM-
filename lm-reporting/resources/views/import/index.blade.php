<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Data - Sistem Pelaporan LM</title>
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
                    <div class="brand-name">Import Data LM</div>
                    <div class="brand-sub">DB WBS, DB OHC, DB GC</div>
                </div>
            </div>
            <div class="topbar-spacer"></div>
            <div class="topbar-right">
                <a class="topbar-link" href="{{ route('batches.index') }}">Batch</a>
                <a class="topbar-link solid" href="{{ route('reports.index') }}">Report Viewer</a>
            </div>
        </header>

        <main class="app-main page">
            @if (session('status'))
                <div class="alert alert-ok" style="margin-bottom:18px">{{ session('status') }}</div>
            @endif

            @if (session('import_errors'))
                <div class="alert alert-warn" style="margin-bottom:18px;flex-direction:column;align-items:stretch">
                    <div><b>Ringkasan error</b></div>
                    <ul class="mt-2 list-disc pl-5">
                        @foreach (array_slice(session('import_errors'), 0, 10) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="panel" style="margin-bottom:20px">
                <div class="panel-head"><span class="panel-title">Upload File Excel</span></div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data"
                          class="grid gap-4 md:grid-cols-4"
                          x-data="{ type: '{{ $pending['type'] ?? 'wbs' }}', isBudget() { return this.type.startsWith('rko_'); } }">
                        @csrf
                        <div class="field" style="margin-bottom:0">
                            <label>Jenis Import</label>
                            <select name="type" x-model="type" class="field-control" required>
                                <optgroup label="Realisasi (per bulan)">
                                    @foreach ($types as $key => $label)
                                        @if (! \App\Domain\Import\SpreadsheetImportService::isBudget($key))
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                                <optgroup label="Anggaran (per tahun)">
                                    @foreach ($types as $key => $label)
                                        @if (\App\Domain\Import\SpreadsheetImportService::isBudget($key))
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Tahun</label>
                            <input name="year" type="number" min="2000" max="2100" value="{{ $pending['year'] ?? date('Y') }}" class="field-control" required>
                        </div>
                        <div class="field" style="margin-bottom:0" x-show="!isBudget()">
                            <label>Bulan <span class="field-hint">(otomatis dari file)</span></label>
                            <select name="month" class="field-control" x-bind:required="!isBudget()">
                                <option value="">— deteksi dari file —</option>
                                @foreach (range(1, 12) as $m)
                                    <option value="{{ $m }}" @selected(($pending['month'] ?? null) === $m)>{{ str_pad((string) $m, 2, '0', STR_PAD_LEFT) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>File</label>
                            <input name="file" type="file" accept=".xlsx,.xls,.csv" class="field-control" required>
                        </div>
                        <div class="flex items-end md:col-span-4">
                            <button class="btn btn-primary" type="submit">Pratinjau</button>
                        </div>
                    </form>
                    <p class="field-hint" style="margin-top:12px">Data tidak langsung disimpan. Setelah unggah, periksa pratinjau lalu klik <b>Konfirmasi &amp; Simpan</b>.</p>
                </div>
            </section>

            @isset($preview)
                @if (! empty($detected_months ?? []))
                    <div class="alert {{ count($detected_months) > 1 ? 'alert-warn' : 'alert-ok' }}" style="margin:0 0 12px">
                        Bulan terdeteksi dari file: <b>{{ implode(', ', array_map(fn ($m) => str_pad((string) $m, 2, '0', STR_PAD_LEFT), $detected_months)) }}</b>.
                        @if (count($detected_months) > 1) Asumsi domain "1 file = 1 bulan" tidak terpenuhi — periksa file. @endif
                    </div>
                @endif
                <section class="panel" style="margin-bottom:20px;border-color:var(--g-500)">
                    <div class="panel-head" style="gap:10px">
                        <span class="panel-title">Pratinjau Import — {{ $preview['label'] }}</span>
                        <span class="pill pill-info" style="margin-left:auto"><span class="dot"></span>{{ number_format($preview['total'], 0, ',', '.') }} baris</span>
                    </div>
                    <div class="panel-body">
                        <div class="alert alert-warn" style="margin-bottom:14px">
                            File: <b>{{ $pending['filename'] }}</b> &middot; menampilkan {{ count($preview['rows']) }} dari {{ number_format($preview['total'], 0, ',', '.') }} baris. Periksa dulu sebelum menyimpan.
                        </div>
                        <div style="overflow:auto;border:1px solid var(--line);border-radius:8px">
                            <table class="htable" style="font-size:11.5px;white-space:nowrap">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        @foreach ($preview['columns'] as $col)
                                            <th>{{ $col }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($preview['rows'] as $i => $row)
                                        <tr>
                                            <td class="mono">{{ $i + 1 }}</td>
                                            @foreach ($preview['columns'] as $ci => $col)
                                                <td>{{ \Illuminate\Support\Str::limit((string) ($row[$ci] ?? ''), 40) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center gap-3" style="margin-top:16px">
                            <form method="POST" action="{{ route('import.confirm') }}">
                                @csrf
                                <input type="hidden" name="token" value="{{ $pending['token'] }}">
                                <input type="hidden" name="ext" value="{{ $pending['ext'] }}">
                                <input type="hidden" name="type" value="{{ $pending['type'] }}">
                                <input type="hidden" name="year" value="{{ $pending['year'] }}">
                                @unless ($pending['is_budget'] ?? false)
                                    <input type="hidden" name="month" value="{{ $pending['month'] }}">
                                @endunless
                                <button class="btn btn-primary" type="submit">Konfirmasi &amp; Simpan ke Database</button>
                            </form>
                            <form method="POST" action="{{ route('import.cancel') }}">
                                @csrf
                                <input type="hidden" name="token" value="{{ $pending['token'] }}">
                                <input type="hidden" name="ext" value="{{ $pending['ext'] }}">
                                <button class="btn btn-outline" type="submit">Batalkan</button>
                            </form>
                        </div>
                    </div>
                </section>
            @endisset

            <section class="panel" style="overflow:hidden">
                <div class="panel-head"><span class="panel-title">Riwayat Upload</span></div>
                <table class="htable">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Batch</th>
                            <th>Jenis</th>
                            <th>User</th>
                            <th>Baris</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td class="mono">{{ $log->uploaded_at->format('Y-m-d H:i') }}</td>
                                <td class="file-cell">{{ $log->batch?->code }}</td>
                                <td>{{ $types[$log->jenis] ?? $log->jenis }}</td>
                                <td>{{ $log->user?->name }}</td>
                                <td class="mono">{{ $log->row_count }}</td>
                                <td>
                                    @if ($log->error_count > 0)
                                        <span class="pill pill-err"><span class="dot"></span>{{ $log->error_count }}</span>
                                    @else
                                        <span class="pill pill-ok"><span class="dot"></span>{{ $log->error_count }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="6">Belum ada log upload.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
