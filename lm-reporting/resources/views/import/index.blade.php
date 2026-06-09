<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Data - Sistem Pelaporan LM</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans">
    <div class="lm-shell">
        <header class="lm-topbar">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div>
                    <h1 class="font-semibold">Import Data LM</h1>
                    <p class="text-xs text-white/75">DB WBS, DB BTL, pabrik, RKAP/RKO, dan tahun lalu</p>
                </div>
                <div class="flex gap-2">
                    <a class="rounded bg-white/15 px-3 py-1 text-sm text-white" href="{{ route('batches.index') }}">Batch</a>
                    <a class="rounded bg-white px-3 py-1 text-sm font-medium text-[#0f4c3a]" href="{{ route('reports.index') }}">Report Viewer</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl space-y-5 px-6 py-6">
            @if (session('status'))
                <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            @if (session('import_errors'))
                <div class="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <div class="font-semibold">Ringkasan error</div>
                    <ul class="mt-2 list-disc pl-5">
                        @foreach (array_slice(session('import_errors'), 0, 10) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="lm-card p-5">
                <h2 class="mb-4 text-lg font-semibold">Upload File Excel</h2>
                <form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-4">
                    @csrf
                    <label class="text-sm">
                        <span class="font-medium">Batch</span>
                        <select name="batch_id" class="mt-1 w-full rounded border px-3 py-2" required>
                            @foreach ($batches as $batch)
                                <option value="{{ $batch->id }}">{{ $batch->code }} - {{ $batch->status }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="font-medium">Jenis Import</span>
                        <select name="type" class="mt-1 w-full rounded border px-3 py-2" required>
                            @foreach ($types as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="font-medium">File</span>
                        <input name="file" type="file" accept=".xlsx,.xls,.csv" class="mt-1 w-full rounded border px-3 py-2" required>
                    </label>
                    <div class="flex items-end">
                        <button class="w-full rounded bg-[#0f4c3a] px-4 py-2 font-semibold text-white">Import</button>
                    </div>
                </form>
            </section>

            <section class="lm-card overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[#0f4c3a] text-white">
                        <tr>
                            <th class="px-4 py-3">Waktu</th>
                            <th class="px-4 py-3">Batch</th>
                            <th class="px-4 py-3">Jenis</th>
                            <th class="px-4 py-3">User</th>
                            <th class="px-4 py-3">Baris</th>
                            <th class="px-4 py-3">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr class="border-t">
                                <td class="px-4 py-3">{{ $log->uploaded_at->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">{{ $log->batch?->code }}</td>
                                <td class="px-4 py-3">{{ $types[$log->jenis] ?? $log->jenis }}</td>
                                <td class="px-4 py-3">{{ $log->user?->name }}</td>
                                <td class="px-4 py-3">{{ $log->row_count }}</td>
                                <td class="px-4 py-3">{{ $log->error_count }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-500" colspan="6">Belum ada log upload.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
