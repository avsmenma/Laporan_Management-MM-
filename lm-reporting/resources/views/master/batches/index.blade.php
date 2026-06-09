<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Batch - Sistem Pelaporan LM</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans">
    <div class="lm-shell">
        <header class="lm-topbar">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div>
                    <h1 class="font-semibold">Batch Laporan</h1>
                    <p class="text-xs text-white/75">Kelola periode 1-12 dan status report</p>
                </div>
                <a class="rounded bg-white px-3 py-1 text-sm font-medium text-[#0f4c3a]" href="{{ route('reports.index') }}">Report Viewer</a>
            </div>
        </header>

        <main class="mx-auto max-w-5xl space-y-5 px-6 py-6">
            @if (session('status'))
                <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            <section class="lm-card p-5">
                <h2 class="mb-4 text-lg font-semibold">Buat / Update Batch</h2>
                <form method="POST" action="{{ route('batches.store') }}" class="grid gap-4 md:grid-cols-4">
                    @csrf
                    <label class="text-sm">
                        <span class="font-medium">Tahun</span>
                        <input name="year" type="number" value="2026" class="mt-1 w-full rounded border px-3 py-2">
                    </label>
                    <label class="text-sm">
                        <span class="font-medium">Bulan</span>
                        <select name="month" class="mt-1 w-full rounded border px-3 py-2">
                            @foreach (range(1, 12) as $month)
                                <option value="{{ $month }}" @selected($month === 5)>{{ $month }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="font-medium">Status</span>
                        <select name="status" class="mt-1 w-full rounded border px-3 py-2">
                            <option value="draft">draft</option>
                            <option value="final">final</option>
                            <option value="locked">locked</option>
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button class="w-full rounded bg-[#0f4c3a] px-4 py-2 font-semibold text-white">Simpan</button>
                    </div>
                </form>
            </section>

            <section class="lm-card overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[#0f4c3a] text-white">
                        <tr>
                            <th class="px-4 py-3">Kode</th>
                            <th class="px-4 py-3">Tahun</th>
                            <th class="px-4 py-3">Bulan</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Diproses</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr class="border-t">
                                <td class="px-4 py-3 font-medium">{{ $batch->code }}</td>
                                <td class="px-4 py-3">{{ $batch->year }}</td>
                                <td class="px-4 py-3">{{ $batch->month }}</td>
                                <td class="px-4 py-3">{{ $batch->status }}</td>
                                <td class="px-4 py-3">{{ optional($batch->processed_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-4 py-6 text-slate-500" colspan="5">Belum ada batch.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>
