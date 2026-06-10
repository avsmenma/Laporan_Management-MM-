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
                    <div class="brand-sub">DB WBS, DB BTL, pabrik, RKAP/RKO, dan tahun lalu</div>
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
                    <form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-4">
                        @csrf
                        <div class="field" style="margin-bottom:0">
                            <label>Batch</label>
                            <select name="batch_id" class="field-control" required>
                                @foreach ($batches as $batch)
                                    <option value="{{ $batch->id }}">{{ $batch->code }} - {{ $batch->status }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Jenis Import</label>
                            <select name="type" class="field-control" required>
                                @foreach ($types as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>File</label>
                            <input name="file" type="file" accept=".xlsx,.xls,.csv" class="field-control" required>
                        </div>
                        <div class="flex items-end">
                            <button class="btn btn-primary btn-block" type="submit">Import</button>
                        </div>
                    </form>
                </div>
            </section>

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
