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

    <div x-show="hasData" x-cloak x-ref="frames" class="prod-frame">
        {{-- Semua tabel disatukan dalam SATU tab-bar; hanya tabel dari tab
             terpilih yang ditampilkan agar user tak perlu scroll panjang. --}}
        <div class="tabs prod-tabs">
            <template x-for="t in allTabs()" :key="t.key">
                <span class="tab" :class="{ active: activeTab === t.key }"
                      @click="setTab(t.key)" x-text="t.title"></span>
            </template>
        </div>
        <div class="report-card">
            <div id="prod-active" class="lm-report-table"></div>
        </div>
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

    /* Satu tab-bar untuk semua tabel; tab bisa diklik untuk berpindah tabel.
       Tab dibuat bisa membungkus (wrap) bila judulnya banyak. */
    .prod-frame .prod-tabs { padding-left: 4px; flex-wrap: wrap; }
    .prod-frame .prod-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    /* Tab tak-terpilih tetap berlatar (redup) agar terlihat sebagai blok, namun
       jelas berbeda dari tab terpilih yang lebih terang (putih + garis hijau). */
    .prod-frame .prod-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .prod-frame .prod-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .prod-frame .prod-tabs .tab.active { font-weight: 700; }
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
        activeTab: 'ringkasan',
        tableDefs: [
            { key: 'restan_awal', title: 'RESTAN AWAL TBS' },
            { key: 'tbs_diterima', title: 'TBS DITERIMA' },
            { key: 'tbs_diolah', title: 'TBS DIOLAH' },
            { key: 'restan_akhir', title: 'RESTAN AKHIR' },
            { key: 'minyak_sawit', title: 'PRODUKSI MINYAK SAWIT' },
            { key: 'inti_sawit', title: 'PRODUKSI INTI SAWIT' },
            { key: 'rend_minyak', title: 'REND. MINYAK SAWIT (%)', rend: true },
            { key: 'rend_inti', title: 'REND. INTI SAWIT (%)', rend: true },
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

        // Daftar tab: Ringkasan + semua tabel pivot, dalam satu tab-bar.
        allTabs() {
            return [{ key: 'ringkasan', title: 'Ringkasan' }, ...this.tableDefs];
        },
        setTab(key) {
            if (this.activeTab === key) return;
            this.activeTab = key;
            this.$nextTick(() => this.renderActive());
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
            // Jangan auto-pilih bulan saat ganti tahun. Pertahankan bulan bila masih
            // tersedia di tahun baru; selain itu kosongkan agar user memilih sendiri.
            const ms = this.months();
            if (!(this.month && ms.includes(Number(this.month)))) {
                this.month = '';
            }
            this.load();
        },

        async init() {
            // Muat-awal: adopsi periode terbaru dari server agar halaman langsung berisi.
            await this.load(true);
        },

        async load(adopt = false) {
            // Aksi pengguna butuh tahun & bulan eksplisit; hanya muat-awal (adopt) yang
            // boleh mengambil periode terbaru dari server tanpa pilihan user.
            if (!adopt && (!this.year || !this.month)) {
                this.hasData = false;
                return;
            }
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
                // Hanya muat-awal yang mengikuti periode pilihan server; aksi user
                // mempertahankan pilihannya sendiri.
                if (adopt) {
                    this.year = data.year ?? '';
                    this.month = data.month ?? '';
                }
                this.plants = data.plants || [];
                this.kebun = data.kebun || [];
                this.payload = data;
                this.hasData = (this.periods.length > 0) && !!this.year && !!this.month;
                if (this.hasData) {
                    this.$nextTick(() => this.renderActive());
                }
            } catch (e) {
                this.hasData = false;
                this.errorMsg = e.message;
                if (window.lmToast) window.lmToast(e.message, 'err');
            }
        },

        // Kolom dua blok: identitas (Kebun, Nama) frozen + grup BULAN INI + grup S.D BULAN INI.
        // Tabel rendemen (rend=true) memakai format 2 desimal, selain itu kuantitas 0 desimal.
        pivotColumns(rend = false) {
            const fmt = (rend ? this.rendFmt : this.qtyFmt).bind(this);
            const block = (b) => {
                const cols = this.plants.map(p => ({
                    title: p.code, field: `${b}_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: fmt, minWidth: 90,
                }));
                cols.push({ title: 'Grand Total', field: `${b}_grand`, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 110 });
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

        // Hanya tabel dari tab aktif yang dibangun ke dalam satu kontainer.
        renderActive() {
            const data = this.payload;
            if (!data) return;

            // hancurkan tabel lama
            Object.values(this.tables).forEach(t => { try { t.destroy(); } catch (e) {} });
            this.tables = {};

            const mkTable = (id, columns, rows) => new window.Tabulator(id, {
                data: rows, columns,
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null, italic = false;
                    if (d._grand) { bg = '#eef5f1'; fw = '700'; }
                    else if (d._section) { bg = '#d7e9df'; fw = '700'; italic = true; }
                    else if (d._bold) { fw = '700'; }
                    if (!bg && !fw) return;
                    const el = row.getElement();
                    if (fw) el.style.fontWeight = fw;
                    if (bg) el.style.background = bg;
                    if (italic) el.style.fontStyle = 'italic';
                    // Sel beku (kolom Uraian/Kebun) dirender terpisah → warnai tiap sel.
                    row.getCells().forEach((c) => {
                        const ce = c.getElement();
                        if (fw) ce.style.fontWeight = fw;
                        if (bg) ce.style.background = bg;
                        if (italic) ce.style.fontStyle = 'italic';
                    });
                },
            });

            const key = this.activeTab;
            if (key === 'ringkasan') {
                if (data.ringkasan && (data.ringkasan.bi || data.ringkasan.sd)) {
                    this.tables['ringkasan'] = mkTable('#prod-active', this.ringkasanColumns(), this.ringkasanRows(data.ringkasan));
                }
            } else {
                const def = this.tableDefs.find(d => d.key === key);
                const tbl = data.tables?.[key];
                if (def && tbl) {
                    this.tables[key] = mkTable('#prod-active', this.pivotColumns(def.rend), this.pivotRows(tbl));
                }
            }
        },

        ringkasanColumns() {
            const cols = [{ title: 'Uraian', field: 'uraian', frozen: true, minWidth: 150 }];
            const block = (b, label) => {
                const sub = this.plants.map(p => ({
                    title: p.code, field: `${b}_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: (c) => { const d = c.getRow().getData(); return d._section ? '' : (d._rend ? this.rendFmt(c) : this.qtyFmt(c)); }, minWidth: 90,
                }));
                sub.push({ title: 'JLH', field: `${b}_JLH`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: (c) => { const d = c.getRow().getData(); return d._section ? '' : (d._rend ? this.rendFmt(c) : this.qtyFmt(c)); }, minWidth: 100 });
                return { title: label, headerHozAlign: 'center', columns: sub };
            };
            cols.push(block('bi', 'BULAN INI'));
            cols.push(block('sd', 'S.D BULAN INI'));
            return cols;
        },

        ringkasanRows(ring) {
            const cols = [...this.plants.map(p => p.code), 'JLH'];
            // Baris data biasa (isi nilai per plant + JLH).
            const dataRow = (f, label, opts = {}) => {
                const o = { uraian: label, _rend: !!opts.rend, _bold: !!opts.bold };
                cols.forEach(c => {
                    o[`bi_${c}`] = ring?.bi?.[c]?.[f] ?? 0;
                    o[`sd_${c}`] = ring?.sd?.[c]?.[f] ?? 0;
                });
                return o;
            };
            // Baris label seksi: kosong (tanpa nilai), diberi band hijau.
            const sectionRow = (label) => ({ uraian: label, _section: true });

            return [
                sectionRow('PRODUKSI TBS'),
                dataRow('restan_awal', 'Restan Awal', { bold: true }),
                dataRow('tbs_masuk', 'TBS Masuk'),
                dataRow('tbs_olah', 'TBS Olah'),
                dataRow('restan_akhir', 'Restan Akhir', { bold: true }),
                sectionRow('PRODUKSI MS + IS'),
                dataRow('ms', 'Minyak Sawit'),
                dataRow('is', 'Inti Sawit'),
                dataRow('jumlah', 'Jumlah MS + IS', { bold: true }),
                sectionRow('RENDEMEN MS + IS'),
                dataRow('rend_ms', 'Rend. MS (%)', { rend: true }),
                dataRow('rend_is', 'Rend. IS (%)', { rend: true }),
                dataRow('rend_total', 'Rend. MS + IS (%)', { rend: true, bold: true }),
            ];
        },
    };
}
</script>
@endpush
