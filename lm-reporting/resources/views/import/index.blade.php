@extends('layouts.app')

@section('title', 'Import Data')

@section('content')
    @if ($errors->any())
        <div class="alert alert-warn" style="margin-bottom:18px;flex-direction:column;align-items:stretch">
            <div><b>Periksa input</b> — unggahan tidak diproses:</div>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <div class="field-hint" style="margin-top:6px">Catatan: jika file besar gagal, kemungkinan melebihi batas ukuran upload server.</div>
        </div>
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
            @php
                // Pulihkan pilihan setelah pratinjau dari type backend (wbs/rko_bku/areal/...).
                // Tanpa pratinjau, SEMUA dropdown default kosong (user wajib memilih).
                $pType = $pending['type'] ?? '';
                $pIsBudget = $pType !== '' && \App\Domain\Import\SpreadsheetImportService::isBudget($pType);
                if ($pType === '') {
                    $pJenis = '';
                    $pKategori = '';
                } elseif ($pType === 'areal') {
                    $pJenis = 'areal';
                    $pKategori = '';
                } elseif ($pType === 'produksi') {
                    $pJenis = 'produksi';
                    $pKategori = '';
                } elseif ($pType === 'produksi_kebun') {
                    $pJenis = 'produksi_kebun';
                    $pKategori = '';
                } elseif ($pIsBudget) {
                    $pJenis = 'rko';
                    $pKategori = substr($pType, 4); // bku/ohc/gc
                } else {
                    $pJenis = 'aktual';
                    $pKategori = $pType; // wbs/ohc/gc
                }
            @endphp
            <form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data"
                  class="grid gap-4 md:grid-cols-5"
                  @submit="window.lmOverlay(true,'Memproses pratinjau…')"
                  x-data="{
                      jenis: '{{ $pJenis }}',
                      kategori: '{{ $pKategori }}',
                      year: '{{ $pending['year'] ?? '' }}',
                      month: '{{ $pending['month'] ?? '' }}',
                      fileName: '',
                      isBudgetType() { return this.jenis === 'rko' || this.jenis === 'rkap'; },
                      isBudget() { return this.isBudgetType(); },
                      isAreal() { return this.jenis === 'areal'; },
                      isProduksi() { return this.jenis === 'produksi'; },
                      isProduksiKebun() { return this.jenis === 'produksi_kebun'; },
                      noKategori() { return this.isAreal() || this.isProduksi() || this.isProduksiKebun(); },
                      needKategori() { return this.jenis !== '' && !this.noKategori(); },
                      kategoriOptions() {
                          return this.isBudgetType()
                              ? [{ v: 'bku', t: 'BKU' }, { v: 'ohc', t: 'OHC' }, { v: 'gc', t: 'GC' }]
                              : [{ v: 'wbs', t: 'WBS' }, { v: 'ohc', t: 'OHC' }, { v: 'gc', t: 'GC' }];
                      },
                      backendType() {
                          if (this.jenis === '') return '';
                          if (this.needKategori() && this.kategori === '') return '';
                          if (this.jenis === 'aktual') return this.kategori;
                          if (this.jenis === 'areal') return 'areal';
                          if (this.jenis === 'produksi') return 'produksi';
                          if (this.jenis === 'produksi_kebun') return 'produksi_kebun';
                          return 'rko_' + this.kategori;
                      },
                      // Semua dropdown wajib terisi sebelum boleh pratinjau / unduh template.
                      allSelected() {
                          return this.backendType() !== '' && this.year !== '' && this.month !== '';
                      },
                      templateUrl() {
                          return '{{ url('import/template') }}/' + this.backendType();
                      }
                  }">
                @csrf
                {{-- type backend (wbs/ohc/gc/rko_bku/rko_ohc/rko_gc/areal) dihitung dari Jenis × Kategori --}}
                <input type="hidden" name="type" :value="backendType()">
                <div class="field" style="margin-bottom:0">
                    <label>Jenis Import</label>
                    <select x-model="jenis" @change="kategori = ''" class="field-control">
                        <option value="">— pilih jenis —</option>
                        <option value="aktual">Aktual</option>
                        <option value="rko">RKO</option>
                        <option value="rkap">RKAP</option>
                        <option value="areal">Areal</option>
                        <option value="produksi">Produksi</option>
                        <option value="produksi_kebun">Produksi Kebun</option>
                    </select>
                </div>
                <div class="field" style="margin-bottom:0" x-show="needKategori()">
                    <label>Kategori Import</label>
                    <select x-model="kategori" class="field-control">
                        <option value="">— pilih kategori —</option>
                        <template x-for="opt in kategoriOptions()" :key="opt.v">
                            <option :value="opt.v" x-text="opt.t"></option>
                        </template>
                    </select>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label>Tahun</label>
                    <select name="year" x-model="year" class="field-control" required>
                        <option value="">— pilih tahun —</option>
                        @foreach (range((int) date('Y') + 1, 2020) as $y)
                            <option value="{{ $y }}" @selected((int) ($pending['year'] ?? 0) === $y)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label>Bulan</label>
                    @php
                        $namaBulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
                    @endphp
                    <select name="month" x-model="month" class="field-control" required>
                        <option value="">— pilih bulan —</option>
                        @foreach ($namaBulan as $m => $nama)
                            <option value="{{ $m }}" @selected((int) ($pending['month'] ?? 0) === $m)>{{ $nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label>File</label>
                    <input name="file" type="file" accept=".xlsx,.xls,.csv" class="field-control" required
                           @change="fileName = $event.target.files[0]?.name ?? ''">
                    <span class="field-hint" x-show="fileName" x-cloak x-text="'Terpilih: ' + fileName"
                          style="margin-top:6px;color:var(--g-700);font-weight:600;word-break:break-all"></span>
                </div>
                <div class="flex items-end gap-3 md:col-span-5">
                    <button class="btn btn-primary" type="submit"
                            :disabled="!allSelected()"
                            :style="!allSelected() ? 'opacity:.5;cursor:not-allowed' : ''">Pratinjau</button>
                    {{-- Tautan unduh template muncul hanya setelah semua dropdown dipilih. --}}
                    <a x-show="allSelected()" x-cloak :href="templateUrl()"
                       class="btn btn-outline" style="text-decoration:none">⬇ Download Template</a>
                    <span class="field-hint" x-show="!allSelected()" x-cloak style="align-self:center">
                        Pilih Jenis<span x-show="needKategori()">, Kategori</span>, Tahun &amp; Bulan untuk mengunduh template &amp; pratinjau.
                    </span>
                </div>
            </form>
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

                <div class="flex items-center gap-3" style="margin-top:16px" x-data="lmImportProgress()">
                    <button class="btn btn-primary" type="button"
                        @click="confirm({
                            token: '{{ $pending['token'] }}', ext: '{{ $pending['ext'] }}',
                            type: '{{ $pending['type'] }}', year: {{ (int) $pending['year'] }},
                            month: {{ (int) ($pending['month'] ?? 0) }}
                        })">Konfirmasi &amp; Simpan ke Database</button>
                    <form method="POST" action="{{ route('import.cancel') }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $pending['token'] }}">
                        <input type="hidden" name="ext" value="{{ $pending['ext'] }}">
                        <button class="btn btn-outline" type="submit">Batalkan</button>
                    </form>

                    {{-- Modal progress --}}
                    <div x-show="open" x-cloak style="position:fixed;inset:0;z-index:195;background:rgba(15,76,58,.4);display:flex;align-items:center;justify-content:center">
                        <div style="background:#fff;border-radius:12px;padding:24px;min-width:340px;box-shadow:0 16px 48px rgba(0,0,0,.3)">
                            <div style="font-weight:700;margin-bottom:12px" x-text="title"></div>
                            <div style="height:12px;background:var(--line,#e5ece9);border-radius:99px;overflow:hidden">
                                <div style="height:100%;background:var(--g-700,#0f4c3a);transition:width .4s" :style="`width:${pct}%`"></div>
                            </div>
                            <div style="margin-top:10px;font-size:12.5px;color:var(--ink-600,#54625c)" x-text="label"></div>
                        </div>
                    </div>
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
@endsection

@push('scripts')
<script>
    function lmImportProgress() {
        return {
            open: false, pct: 0, title: 'Mengimpor…', label: 'Menyiapkan…',
            async confirm(payload) {
                this.open = true; this.pct = 0; this.title = 'Mengimpor ' + payload.type; this.label = 'Mengantre…';
                try {
                    const res = await fetch('{{ route('import.confirm') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error(e.message || 'Gagal memulai import'); }
                    const { status_url } = await res.json();
                    this.poll(status_url);
                } catch (e) { this.open = false; window.lmToast(e.message, 'err'); }
            },
            poll(url) {
                let attempts = 0;
                const MAX_ATTEMPTS = 600; // 600 × 2s = 20 menit
                const tick = async () => {
                    attempts++;
                    if (attempts > MAX_ATTEMPTS) {
                        this.open = false;
                        window.lmToast('Masih diproses di latar belakang — cek Riwayat Upload nanti.', 'ok');
                        return;
                    }
                    try {
                        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const s = await r.json();
                        if (s.total > 0) this.pct = Math.min(100, Math.round(s.processed / s.total * 100));
                        this.label = (s.processed || 0).toLocaleString('id-ID') + ' / ' + (s.total || 0).toLocaleString('id-ID') + ' baris (' + this.pct + '%)';
                        if (s.status === 'done') { this.open = false; window.lmToast('Import berhasil: ' + s.row_count + ' baris', 'ok'); setTimeout(() => location.reload(), 1200); return; }
                        if (s.status === 'failed') { this.open = false; window.lmToast('Import gagal: ' + (s.error || 'tidak diketahui'), 'err'); return; }
                        setTimeout(tick, 2000);
                    } catch (e) { setTimeout(tick, 3000); }
                };
                tick();
            },
        };
    }
</script>
@endpush
