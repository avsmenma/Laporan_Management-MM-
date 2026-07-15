@extends('layouts.app')

@section('title', 'Pembelian TBS')

@section('content')
<div x-data="pembelianTbsApp()" x-init="init()" class="ptbs-page">
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

    <div x-show="hasData" x-cloak class="ptbs-frame">
        <div class="tabs ptbs-tabs">
            <template x-for="t in tabDefs" :key="t.key">
                <span class="tab" :class="{ active: activeTab === t.key }"
                      @click="setTab(t.key)" x-text="t.title"></span>
            </template>
        </div>
        <div class="report-card">
            <div id="ptbs-active" class="lm-report-table"></div>
        </div>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🛒</div>
        <h3 style="color:#666;font-weight:500">Pilih bulan &amp; tahun untuk melihat Pembelian TBS</h3>
    </div>
</div>

<style>
    .ptbs-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .ptbs-page .filter-bar { top: 0; }

    .ptbs-frame .ptbs-tabs { padding-left: 4px; flex-wrap: wrap; }
    .ptbs-frame .ptbs-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    .ptbs-frame .ptbs-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .ptbs-frame .ptbs-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .ptbs-frame .ptbs-tabs .tab.active { font-weight: 700; }
    .ptbs-frame .report-card { border-top-left-radius: 0; }
    .ptbs-frame .lm-report-table { border-top: 0; }
</style>
@endsection

@push('scripts')
<script>
function pembelianTbsApp() {
    return {
        periods: [],
        year: '',
        month: '',
        plants: [],
        summary: null,
        rincian: null,
        hasData: false,
        errorMsg: null,
        table: null,
        activeTab: 'summary',

        tabDefs: [
            { key: 'summary', title: 'Summary' },
            { key: 'rincian', title: 'Rincian' },
        ],

        // Angka kuantum/nilai: 0/null → '-' (spt tampilan Excel).
        numFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },
        // Rasio → persen; 0/null → '-' (pola aman IFERROR).
        pctFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : (Number(v) * 100).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
        },

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },

        years() {
            return [...new Set(this.periods.map(p => p.year))].sort((a, b) => b - a);
        },
        months() {
            // Bila tahun sudah dipilih, batasi bulan ke tahun itu; bila belum,
            // tampilkan semua bulan yang tersedia agar dropdown tetap berisi item.
            const src = this.year
                ? this.periods.filter(p => String(p.year) === String(this.year))
                : this.periods;
            return [...new Set(src.map(p => Number(p.month)))].sort((a, b) => a - b);
        },
        onYearChange() {
            const ms = this.months();
            if (!(this.month && ms.includes(Number(this.month)))) {
                this.month = '';
            }
            this.load();
        },
        setTab(key) {
            if (this.activeTab === key) return;
            this.activeTab = key;
            this.$nextTick(() => this.renderActive());
        },

        async init() {
            // Muat daftar periode untuk mengisi opsi dropdown, TAPI jangan auto-pilih
            // bulan/tahun — biarkan kosong sampai user memilih sendiri.
            try {
                const resp = await fetch('/report-data/produksi/pembelian');
                if (resp.ok) {
                    const data = await resp.json();
                    this.periods = data.periods || [];
                }
            } catch (e) { /* biarkan; dropdown akan kosong bila gagal */ }
            this.year = '';
            this.month = '';
            this.hasData = false;
        },

        async load(adopt = false) {
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
                const resp = await fetch('/report-data/produksi/pembelian' + q);
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.message || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                this.periods = data.periods || [];
                if (adopt) {
                    this.year = data.year ?? '';
                    this.month = data.month ?? '';
                }
                this.plants = data.plants || [];
                this.summary = data.summary || null;
                this.rincian = data.rincian || null;
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

        // ===== Tab SUMMARY (identik TEMPLATE baris 11-38) =====

        // Blok {Qty, Rp/Kg, Value} untuk satu prefix field.
        qrvCols(prefix) {
            return [
                { title: 'Qty', field: `${prefix}_qty`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 95 },
                { title: 'Rp/Kg', field: `${prefix}_rpkg`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 75 },
                { title: 'Value', field: `${prefix}_val`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 120 },
            ];
        },

        summaryColumns() {
            const rasio = (title, qf, vf) => ({
                title,
                columns: [
                    { title: 'Qty', field: qf, hozAlign: 'right', headerHozAlign: 'center', formatter: this.pctFmt.bind(this), minWidth: 75 },
                    { title: 'Value', field: vf, hozAlign: 'right', headerHozAlign: 'center', formatter: this.pctFmt.bind(this), minWidth: 75 },
                ],
            });
            return [
                { title: 'Nama Pabrik', field: 'pabrik', frozen: true, minWidth: 200 },
                { title: 'Pembelian', field: 'pembelian', frozen: true, minWidth: 130 },
                { title: 'Bulan Lalu', columns: this.qrvCols('bl') },
                { title: 'Bulan Ini', columns: this.qrvCols('bi') },
                { title: 'Sd Bulan Ini', columns: this.qrvCols('sd') },
                { title: 'RKAP Bulan Ini', columns: this.qrvCols('rkbi') },
                { title: 'RKAP Sd. Bulan Ini', columns: this.qrvCols('rksd') },
                rasio('BI/BL', 'r_bibl_q', 'r_bibl_v'),
                rasio('BI/RKAP BI', 'r_birkap_q', 'r_birkap_v'),
                rasio('SBI/RKAP SBI', 'r_sbirkap_q', 'r_sbirkap_v'),
            ];
        },

        summaryRows() {
            const list = [];
            if (!this.summary || !this.summary.rows) return list;
            const fill = (o, b) => {
                const put = (prefix, blok) => {
                    o[`${prefix}_qty`] = blok ? blok.qty : null;
                    o[`${prefix}_rpkg`] = blok ? blok.rpkg : null;
                    o[`${prefix}_val`] = blok ? blok.value : null;
                };
                put('bl', b.bl); put('bi', b.bi); put('sd', b.sd);
                put('rkbi', b.rkap_bi); put('rksd', b.rkap_sd);
                o.r_bibl_q = b.ratio.bibl_qty; o.r_bibl_v = b.ratio.bibl_val;
                o.r_birkap_q = b.ratio.birkap_qty; o.r_birkap_v = b.ratio.birkap_val;
                o.r_sbirkap_q = b.ratio.sbirkap_qty; o.r_sbirkap_v = b.ratio.sbirkap_val;
                return o;
            };
            this.summary.rows.forEach(r => {
                r.baris.forEach((b, i) => {
                    list.push(fill({
                        pabrik: i === 0 ? r.plant_nama : '',
                        pembelian: b.label,
                        _jumlah: b.key === 'JML',
                    }, b));
                });
            });
            if (this.summary.total) {
                list.push(fill({ pabrik: 'Total', pembelian: '', _grand: true }, this.summary.total));
            }
            return list;
        },

        // ===== Tab RINCIAN (identik TEMPLATE baris 2-6, semua pabrik melebar) =====

        rincianColumns() {
            const idCols = [
                { title: 'Pembelian', field: 'pembelian', frozen: true, minWidth: 110 },
                { title: 'Kode Supplier', field: 'kode', frozen: true, minWidth: 105 },
                { title: 'Nama Supplier', field: 'nama', frozen: true, minWidth: 220 },
            ];
            const blok = (pc, key, label) => ({
                title: label,
                columns: [
                    { title: 'Qty', field: `${key}_${pc}_q`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 90 },
                    { title: 'Rp/Kg', field: `${key}_${pc}_r`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 70 },
                    { title: 'Value', field: `${key}_${pc}_v`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 115 },
                ],
            });
            const plantCols = this.plants.map(p => ({
                title: p.short || p.code,
                columns: [blok(p.code, 'bi', 'Bulan Ini'), blok(p.code, 'sd', 'SD Bulan Ini')],
            }));
            const totalCols = {
                title: 'Total',
                columns: [
                    { title: 'Bulan Ini', columns: [
                        { title: 'Qty', field: 'tbi_q', hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 100 },
                        { title: 'Nilai', field: 'tbi_v', hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 125 },
                    ] },
                    { title: 'SD Bulan Ini', columns: [
                        { title: 'Qty', field: 'tsd_q', hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 100 },
                        { title: 'Nilai', field: 'tsd_v', hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth: 125 },
                    ] },
                ],
            };
            return [...idCols, ...plantCols, totalCols];
        },

        rincianRows() {
            const list = [];
            if (!this.rincian || !this.rincian.groups) return list;
            const fill = (o, src) => {
                this.plants.forEach(p => {
                    ['bi', 'sd'].forEach(key => {
                        const cell = src.plants && src.plants[p.code] ? src.plants[p.code][key] : null;
                        o[`${key}_${p.code}_q`] = cell ? cell.qty : null;
                        o[`${key}_${p.code}_r`] = cell ? cell.rpkg : null;
                        o[`${key}_${p.code}_v`] = cell ? cell.value : null;
                    });
                });
                o.tbi_q = src.total ? src.total.bi.qty : null;
                o.tbi_v = src.total ? src.total.bi.value : null;
                o.tsd_q = src.total ? src.total.sd.qty : null;
                o.tsd_v = src.total ? src.total.sd.value : null;
                return o;
            };
            this.rincian.groups.forEach(g => {
                g.rows.forEach((r, i) => {
                    // Kode grup (PHTG/PLSM) tampil pada baris pertama grup (spt pivot Excel).
                    list.push(fill({
                        pembelian: i === 0 ? g.batch : '',
                        kode: r.vendor_code || '—',
                        nama: r.vendor_name || '—',
                    }, r));
                });
                list.push(fill({ pembelian: `${g.batch} Total`, kode: '', nama: '', _jumlah: true }, g.subtotal));
            });
            if (this.rincian.grand) {
                list.push(fill({ pembelian: 'Grand Total', kode: '', nama: '', _grand: true }, this.rincian.grand));
            }
            return list;
        },

        renderActive() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            const isSummary = this.activeTab === 'summary';

            this.table = new window.Tabulator('#ptbs-active', {
                data: isSummary ? this.summaryRows() : this.rincianRows(),
                columns: isSummary ? this.summaryColumns() : this.rincianColumns(),
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                // Tinggi maksimum → tabel punya scroll vertikal sendiri sehingga
                // grouped header tetap frozen di atas saat isi digulir.
                maxHeight: 'calc(100vh - 210px)',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null;
                    if (d._grand) { bg = '#dcebe2'; fw = '700'; }
                    else if (d._jumlah) { bg = '#eef5f1'; fw = '700'; }
                    if (!bg && !fw) return;
                    const el = row.getElement();
                    if (fw) el.style.fontWeight = fw;
                    if (bg) el.style.background = bg;
                    row.getCells().forEach((c) => {
                        const ce = c.getElement();
                        if (fw) ce.style.fontWeight = fw;
                        if (bg) ce.style.background = bg;
                    });
                },
            });
        },
    };
}
</script>
@endpush
