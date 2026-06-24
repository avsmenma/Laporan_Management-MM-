@extends('layouts.app')

@section('title', 'Produksi')

@section('content')
<div x-data="produksiApp()" x-init="init()" class="produksi-page">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Bulan</label>
                <select class="filter-select" x-model="month" @change="load()">
                    <option value="">— pilih bulan —</option>
                    <template x-for="m in months()" :key="m">
                        <option :value="m" x-text="bulanNama(m)"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model="year" @change="onYearChange()">
                    <option value="">— pilih tahun —</option>
                    <template x-for="y in years()" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div x-show="errorMsg" x-cloak class="lm-error-panel" x-text="errorMsg"></div>

    <div x-show="hasData" x-cloak x-ref="frames">
        {{-- Tiap tabel = frame mandiri, judulnya tampil sebagai tab (gaya tab-bar). --}}

        {{-- Ringkasan --}}
        <div class="prod-frame">
            <div class="tabs"><span class="tab active">Ringkasan</span></div>
            <div class="report-card">
                <div id="prod-ringkasan" class="lm-report-table"></div>
            </div>
        </div>

        {{-- 6 tabel pivot --}}
        <template x-for="t in tableDefs" :key="t.key">
            <div class="prod-frame">
                <div class="tabs"><span class="tab active" x-text="t.title"></span></div>
                <div class="report-card">
                    <div :id="'prod-' + t.key" class="lm-report-table"></div>
                </div>
            </div>
        </template>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🏭</div>
        <h3 style="color:#666;font-weight:500">Pilih tanggal untuk melihat laporan produksi PKS</h3>
    </div>
</div>

<style>
    /* Toolbar filter (dropdown tanggal) dibuat lengket di bawah header (60px)
       agar tetap terjangkau saat user men-scroll jauh ke bawah — banyak tabel.
       Hanya berlaku di halaman produksi. */
    .produksi-page .filter-bar {
        position: sticky;
        top: 60px;
        z-index: 30;
    }
    /* Saat mode layar penuh header disembunyikan, jadi lengket ke atas. */
    body.lm-focus .produksi-page .filter-bar { top: 0; }

    /* Tiap tabel produksi berdiri sendiri sebagai frame (kartu) dengan judul
       bergaya tab yang menempel di tepi atas kartu — bukan label biasa. */
    .prod-frame { margin-top: 22px; }
    .prod-frame:first-child { margin-top: 0; }
    .prod-frame .tabs { padding-left: 4px; }
    /* Tab tidak interaktif di sini (selalu aktif sebagai judul frame). */
    .prod-frame .tab.active { cursor: default; height: 38px; font-weight: 700; letter-spacing: .01em; }
    .prod-frame .tab.active:hover { background: var(--surface); color: var(--g-800); }
    /* Sudut kiri-atas kartu dibuat siku agar tab menyatu mulus dengan kartu. */
    .prod-frame .report-card { border-top-left-radius: 0; }
    /* Kartu sudah punya border; hilangkan border-atas tabel agar tidak dobel garis. */
    .prod-frame .lm-report-table { border-top: 0; }
</style>
@endsection

@push('scripts')
<script>
function produksiApp() {
    return {
        periods: [],
        year: '',
        month: '',
        plants: [],
        kebun: [],
        payload: null,
        hasData: false,
        errorMsg: null,
        tables: {},
        tableDefs: [
            { key: 'restan_awal', title: 'RESTAN AWAL TBS' },
            { key: 'tbs_diterima', title: 'TBS DITERIMA' },
            { key: 'tbs_diolah', title: 'TBS DIOLAH' },
            { key: 'restan_akhir', title: 'RESTAN AKHIR' },
            { key: 'minyak_sawit', title: 'PRODUKSI MINYAK SAWIT' },
            { key: 'inti_sawit', title: 'PRODUKSI INTI SAWIT' },
        ],

        qtyFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },
        rendFmt(cell) {
            const v = cell.getValue();
            return (v == null) ? '-' : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },

        // Tahun & bulan tersedia diturunkan dari daftar periode dari server.
        years() {
            return [...new Set(this.periods.map(p => p.year))].sort((a, b) => b - a);
        },
        months() {
            return this.periods
                .filter(p => String(p.year) === String(this.year))
                .map(p => Number(p.month))
                .sort((a, b) => b - a);
        },
        onYearChange() {
            const ms = this.months();
            if (!ms.includes(Number(this.month))) {
                this.month = ms[0] ?? '';
            }
            this.load();
        },

        async init() {
            await this.load();
        },

        async load() {
            this.errorMsg = null;
            try {
                const params = [];
                if (this.year) params.push('year=' + encodeURIComponent(this.year));
                if (this.month) params.push('month=' + encodeURIComponent(this.month));
                const q = params.length ? ('?' + params.join('&')) : '';
                const resp = await fetch('/report-data/produksi' + q);
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.message || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                this.periods = data.periods || [];
                this.year = data.year ?? '';
                this.month = data.month ?? '';
                this.plants = data.plants || [];
                this.kebun = data.kebun || [];
                this.payload = data;
                this.hasData = (this.periods.length > 0);
                if (this.hasData) {
                    this.$nextTick(() => this.renderAll(data));
                }
            } catch (e) {
                this.hasData = false;
                this.errorMsg = e.message;
                if (window.lmToast) window.lmToast(e.message, 'err');
            }
        },

        // Kolom dua blok: identitas (Kebun, Nama) frozen + grup BULAN INI + grup S.D BULAN INI.
        pivotColumns() {
            const block = (b) => {
                const cols = this.plants.map(p => ({
                    title: p.code, field: `${b}_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: this.qtyFmt.bind(this), minWidth: 90,
                }));
                cols.push({ title: 'Grand Total', field: `${b}_grand`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.qtyFmt.bind(this), minWidth: 110 });
                return cols;
            };
            return [
                { title: 'Kebun', field: 'kebun', frozen: true, minWidth: 90,
                  formatter: (c) => { const d = c.getRow().getData(); return d._grand ? 'Grand Total' : (d.kebun ?? ''); } },
                { title: 'Nama Kebun', field: 'nama', frozen: true, minWidth: 180 },
                { title: 'BULAN INI', headerHozAlign: 'center', columns: block('bi') },
                { title: 'S.D BULAN INI', headerHozAlign: 'center', columns: block('sd') },
            ];
        },

        pivotRows(tbl) {
            const rows = (tbl.rows || []).map(r => {
                const o = { kebun: r.kebun, nama: r.nama, _grand: false };
                this.plants.forEach(p => { o[`bi_${p.code}`] = r.bi?.[p.code] ?? 0; o[`sd_${p.code}`] = r.sd?.[p.code] ?? 0; });
                o['bi_grand'] = r.bi?.grand ?? 0;
                o['sd_grand'] = r.sd?.grand ?? 0;
                return o;
            });
            const g = { kebun: '', nama: '', _grand: true };
            this.plants.forEach(p => { g[`bi_${p.code}`] = tbl.grand?.bi?.[p.code] ?? 0; g[`sd_${p.code}`] = tbl.grand?.sd?.[p.code] ?? 0; });
            g['bi_grand'] = tbl.grand?.bi?.grand ?? 0;
            g['sd_grand'] = tbl.grand?.sd?.grand ?? 0;
            rows.push(g);
            return rows;
        },

        renderAll(data) {
            // Saat ganti tanggal, tabel dibongkar-pasang. Tanpa penjagaan, area
            // frame sempat runtuh ke 0 sehingga scroll user melompat ke atas.
            // Kunci tinggi area + simpan posisi scroll, lalu pulihkan setelah render.
            const wrap = this.$refs.frames;
            const prevScroll = window.scrollY;
            const prevH = wrap ? wrap.offsetHeight : 0;
            if (wrap && prevH) wrap.style.minHeight = prevH + 'px';

            // hancurkan tabel lama
            Object.values(this.tables).forEach(t => { try { t.destroy(); } catch (e) {} });
            this.tables = {};

            const mkTable = (id, columns, rows) => new window.Tabulator(id, {
                data: rows, columns,
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                rowFormatter: (row) => {
                    if (row.getData()._grand) {
                        row.getElement().style.fontWeight = '700';
                        row.getElement().style.background = '#eef5f1';
                    }
                },
            });

            // 6 pivot
            this.tableDefs.forEach(def => {
                const tbl = data.tables?.[def.key];
                if (!tbl) return;
                this.tables[def.key] = mkTable('#prod-' + def.key, this.pivotColumns(), this.pivotRows(tbl));
            });

            // Ringkasan
            if (data.ringkasan && (data.ringkasan.bi || data.ringkasan.sd)) {
                this.tables['ringkasan'] = mkTable('#prod-ringkasan', this.ringkasanColumns(), this.ringkasanRows(data.ringkasan));
            }

            // Pulihkan posisi scroll & lepas kunci tinggi setelah tabel terbentuk.
            this.$nextTick(() => {
                window.scrollTo({ top: prevScroll });
                setTimeout(() => { if (wrap) wrap.style.minHeight = ''; }, 150);
            });
        },

        ringkasanColumns() {
            const cols = [{ title: 'Uraian', field: 'uraian', frozen: true, minWidth: 150 }];
            const block = (b, label) => {
                const sub = this.plants.map(p => ({
                    title: p.code, field: `${b}_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: (c) => c.getRow().getData()._rend ? this.rendFmt(c) : this.qtyFmt(c), minWidth: 90,
                }));
                sub.push({ title: 'JLH', field: `${b}_JLH`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: (c) => c.getRow().getData()._rend ? this.rendFmt(c) : this.qtyFmt(c), minWidth: 100 });
                return { title: label, headerHozAlign: 'center', columns: sub };
            };
            cols.push(block('bi', 'BULAN INI'));
            cols.push(block('sd', 'S.D BULAN INI'));
            return cols;
        },

        ringkasanRows(ring) {
            const defs = [
                { f: 'restan_awal', t: 'Restan Awal', rend: false },
                { f: 'tbs_masuk', t: 'TBS Masuk', rend: false },
                { f: 'tbs_olah', t: 'TBS Olah', rend: false },
                { f: 'restan_akhir', t: 'Restan Akhir', rend: false },
                { f: 'ms', t: 'Minyak Sawit', rend: false },
                { f: 'is', t: 'Inti Sawit', rend: false },
                { f: 'jumlah', t: 'Jumlah MS + IS', rend: false },
                { f: 'rend_ms', t: 'Rend. MS (%)', rend: true },
                { f: 'rend_is', t: 'Rend. IS (%)', rend: true },
                { f: 'rend_total', t: 'Rend. MS + IS (%)', rend: true },
            ];
            const cols = [...this.plants.map(p => p.code), 'JLH'];
            return defs.map(d => {
                const o = { uraian: d.t, _rend: d.rend };
                cols.forEach(c => {
                    o[`bi_${c}`] = ring?.bi?.[c]?.[d.f] ?? 0;
                    o[`sd_${c}`] = ring?.sd?.[c]?.[d.f] ?? 0;
                });
                return o;
            });
        },
    };
}
</script>
@endpush
