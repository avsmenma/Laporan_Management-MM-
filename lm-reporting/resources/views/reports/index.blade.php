<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistem Pelaporan LM PTPN IV Regional V</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans">
    <div class="lm-shell">
        <header class="lm-topbar">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded bg-white/15 font-bold">PN</div>
                    <div>
                        <h1 class="text-base font-semibold">PT. Perkebunan Nusantara - Sistem Pelaporan Kebun - MIS</h1>
                        <p class="text-xs text-white/75">Regional V - fondasi prompt_00</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 text-sm">
                    <span class="rounded bg-white/15 px-3 py-1">{{ auth()->user()->role->name }}</span>
                    <span>{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded bg-white px-3 py-1 font-medium text-[#0f4c3a]">Keluar</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto grid max-w-7xl grid-cols-12 gap-6 px-6 py-6">
            <aside class="col-span-12 rounded bg-[#0f4c3a] p-3 text-white md:col-span-2">
                <nav class="space-y-2 text-sm">
                    <a class="block rounded bg-white px-3 py-2 font-semibold text-[#0f4c3a]" href="#">KEBUN</a>
                    <a class="block rounded px-3 py-2 text-white/85 hover:bg-white/10" href="#">PABRIK</a>
                </nav>
            </aside>

            <section class="col-span-12 space-y-5 md:col-span-10">
                <div class="lm-card p-5">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-[#0f4c3a]">PT PERKEBUNAN NUSANTARA IV - RPC 5</p>
                            <h2 class="mt-1 text-xl font-semibold">Report Viewer Biaya Produksi Kebun & Pabrik</h2>
                            <p class="mt-1 text-sm text-slate-600">Tahun 2026 - Kebun 5E01 - I. DETAIL</p>
                        </div>
                        <div class="grid grid-cols-3 overflow-hidden rounded border border-[#d9e3dd] text-center text-sm">
                            <div class="bg-[#f7f3ea] px-4 py-2"><b>31</b><br>Jlh. Hari</div>
                            <div class="bg-white px-4 py-2"><b>9</b><br>Dijalani</div>
                            <div class="bg-[#f7f3ea] px-4 py-2"><b>22</b><br>Sisa</div>
                        </div>
                    </div>
                </div>

                <div class="lm-card p-5" x-data="{ komoditas: 'Sawit', periode: 5, unit: '5E01' }">
                    <div class="mb-4 grid gap-4 md:grid-cols-4">
                        <label class="text-sm">
                            <span class="font-medium">Komoditas</span>
                            <select x-model="komoditas" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                                <option>Sawit</option>
                                <option>Karet</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="font-medium">Periode</span>
                            <select x-model="periode" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                                @foreach (range(1, 12) as $month)
                                    <option value="{{ $month }}">{{ $month }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="font-medium">Unit</span>
                            <select x-model="unit" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
                                <option value="5E01">5E01 - Kebun Gunung Meliau</option>
                                <option value="5E11">5E11 - Kebun Danau Salak</option>
                                <option value="5F01">5F01 - PKS Gunung Meliau</option>
                            </select>
                        </label>
                        <div class="rounded border border-[#d9e3dd] bg-[#f7f3ea] px-3 py-2 text-sm">
                            <span class="font-medium">Batch</span>
                            <div>Batch #2026-05</div>
                        </div>
                    </div>

                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold">LM 14 - Contoh Integrasi Tabulator</h3>
                            <p class="text-sm text-slate-600">Periode <span x-text="periode"></span> - <span x-text="komoditas"></span> - <span x-text="unit"></span></p>
                        </div>
                        <div class="flex gap-2 text-sm">
                            <button class="rounded border px-3 py-1.5">Excel</button>
                            <button class="rounded border px-3 py-1.5">CSV</button>
                            <button class="rounded border px-3 py-1.5">PDF</button>
                            <button class="rounded border px-3 py-1.5">Cetak</button>
                        </div>
                    </div>

                    <div x-data="lmDemoTable" x-init="initTable()">
                        <div x-ref="table"></div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
