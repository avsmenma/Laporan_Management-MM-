@extends('layouts.app')

@section('title', 'Penjualan Produk')

@section('content')
<div x-data="penjualanApp()" x-init="init()" class="pjl-page">
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

    <div x-show="hasData" x-cloak class="pjl-frame">
        <div class="tabs pjl-tabs">
            <template x-for="t in tabDefs" :key="t.key">
                <span class="tab" :class="{ active: activeTab === t.key }"
                      @click="setTab(t.key)" x-text="t.title"></span>
            </template>
        </div>
        <div class="report-card">
            <div id="pjl-active" class="lm-report-table"></div>
        </div>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">💰</div>
        <h3 style="color:#666;font-weight:500">Pilih bulan &amp; tahun untuk melihat Penjualan Produk</h3>
    </div>
</div>

<style>
    .pjl-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .pjl-page .filter-bar { top: 0; }

    .pjl-frame .pjl-tabs { padding-left: 4px; flex-wrap: wrap; }
    .pjl-frame .pjl-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    .pjl-frame .pjl-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .pjl-frame .pjl-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .pjl-frame .pjl-tabs .tab.active { font-weight: 700; }
    .pjl-frame .report-card { border-top-left-radius: 0; }
    .pjl-frame .lm-report-table { border-top: 0; }
</style>
@endsection

@push('scripts')
<script>
function penjualanApp() {
    return {
        periods: [],
        year: '',
        month: '',
        plants: [],
        buyer: null,
        plant: null,
        all: null,
        hasData: false,
        errorMsg: null,
        table: null,
        activeTab: 'buyer',

        tabDefs: [
            { key: 'buyer', title: 'BUYER' },
            { key: 'plant', title: 'PLANT' },
            { key: 'all', title: 'ALL' },
        ],

        // Angka apa adanya dari GL (penjualan = kredit → negatif): negatif dirender
        // dalam tanda kurung seperti pivot Excel (warna tetap normal); 0/null → '-'.
        numFmt(cell) {
            const v = cell.getValue();
            if (v == null || Number(v) === 0) return '-';
            const n = Number(v);
            const s = Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            return n < 0 ? '(' + s + ')' : s;
        },

        // Rasio → persen; 0/null → '-' (pola aman IFERROR, sama dgn halaman Pembelian).
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
                const resp = await fetch('/report-data/laba-rugi/penjualan');
                if (resp.ok) {
                    const data = await resp.json();
                    this.periods = data.periods || [];
                }
            } catch (e) { /* biarkan; dropdown akan kosong bila gagal */ }
            this.year = '';
            this.month = '';
            this.hasData = false;
        },

        async load() {
            if (!this.year || !this.month) {
                this.hasData = false;
                return;
            }
            this.errorMsg = null;
            try {
                const q = '?year=' + encodeURIComponent(this.year) + '&month=' + encodeURIComponent(this.month);
                const resp = await fetch('/report-data/laba-rugi/penjualan' + q);
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.message || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                this.periods = data.periods || [];
                this.plants = data.plants || [];
                this.buyer = data.buyer || null;
                this.plant = data.plant || null;
                this.all = data.all || null;
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

        // Blok rasio {QTY, NILAI} berformat persen untuk satu prefix field.
        pctCols(prefix) {
            const fmt = this.pctFmt.bind(this);
            return [
                { title: 'QTY', field: `${prefix}_q`, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 75 },
                { title: 'NILAI', field: `${prefix}_n`, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 75 },
            ];
        },

        // Blok {QTY, RP/KG, NILAI} untuk satu prefix field.
        qrnCols(prefix) {
            const fmt = this.numFmt.bind(this);
            return [
                { title: 'QTY', field: `${prefix}_q`, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 100 },
                { title: 'RP/KG', field: `${prefix}_r`, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 75 },
                { title: 'NILAI', field: `${prefix}_n`, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 140 },
            ];
        },

        // ===== Tab BUYER / PLANT (identik temp Buyer / temp Plant) =====

        duaColumns(isPlant) {
            const ident = [
                { title: 'PRODUCT', field: 'product', frozen: true, minWidth: 170 },
                { title: isPlant ? 'CODE PLANT' : 'CODE CUSTOMER', field: 'code', frozen: true, minWidth: 110 },
                { title: isPlant ? 'NAMA PABRIK' : 'NAME CUSTOMER', field: 'name', frozen: true, minWidth: 250 },
            ];
            if (isPlant) {
                // RKAP belum punya sumber data → sel dirender '-' (field tak diisi).
                return [
                    ...ident,
                    { title: 'BULAN LALU', columns: this.qrnCols('bl') },
                    { title: 'BULAN INI', columns: this.qrnCols('bi') },
                    { title: 'RKAP BULAN INI', columns: this.qrnCols('rkbi') },
                    { title: 'SD BULAN INI', columns: this.qrnCols('sd') },
                    { title: 'SD RKAP BULAN INI', columns: this.qrnCols('rksd') },
                    { title: '%BI/BL', columns: this.pctCols('r_bibl') },
                    { title: '%BI/RKAP', columns: this.pctCols('r_birkap') },
                    { title: '%S.D BI/S.D RKAP', columns: this.pctCols('r_sdrkap') },
                ];
            }
            return [
                ...ident,
                { title: 'BULAN INI', columns: this.qrnCols('bi') },
                { title: 'SD BULAN INI', columns: this.qrnCols('sd') },
            ];
        },

        duaRows(src) {
            const list = [];
            if (!src || !src.groups) return list;
            const fill = (o, b) => {
                // 'bl' hanya ada di tab PLANT; RKAP (rkbi/rksd) belum ada sumber → null ('-').
                ['bl', 'bi', 'sd'].forEach(k => {
                    const blk = b ? b[k] : null;
                    o[`${k}_q`] = blk ? blk.qty : null;
                    o[`${k}_r`] = blk ? blk.rpkg : null;
                    o[`${k}_n`] = blk ? blk.nilai : null;
                });
                // Rasio %BI/BL (penyebut 0/kosong → '-'); rasio vs RKAP menunggu
                // sumber anggaran → dibiarkan null ('-').
                const div = (a, c) => (a != null && c != null && Number(c) !== 0) ? Number(a) / Number(c) : null;
                o.r_bibl_q = div(o.bi_q, o.bl_q);
                o.r_bibl_n = div(o.bi_n, o.bl_n);
                return o;
            };
            src.groups.forEach(g => {
                // Baris judul grup produk (spt B5=CPO di template, tanpa angka).
                list.push({ product: g.material, code: '', name: '', _section: true });
                g.rows.forEach(r => {
                    list.push(fill({ product: '', code: r.code || '—', name: r.name || '—' }, r));
                });
                list.push(fill({ product: 'Jumlah', code: '', name: '', _jumlah: true }, g.jumlah));
            });
            if (src.total) {
                list.push(fill({ product: 'Total', code: '', name: '', _grand: true }, src.total));
            }
            return list;
        },

        // ===== Tab ALL (identik temp Buyer Plant; BULAN INI saja) =====

        allColumns() {
            const plantCols = this.plants.map(p => ({
                title: p.nama || p.code,
                columns: this.qrnCols(`p_${p.code}`),
            }));
            return [
                { title: 'PRODUCT', field: 'product', frozen: true, minWidth: 170 },
                { title: 'CODE CUSTOMER', field: 'code', frozen: true, minWidth: 110 },
                { title: 'NAME CUSTOMER', field: 'name', frozen: true, minWidth: 250 },
                { title: 'BULAN INI', columns: [...plantCols, { title: 'JUMLAH', columns: this.qrnCols('jml') }] },
            ];
        },

        allRows() {
            const list = [];
            if (!this.all || !this.all.groups) return list;
            const fill = (o, src) => {
                this.plants.forEach(p => {
                    const blk = src.plants ? src.plants[p.code] : null;
                    o[`p_${p.code}_q`] = blk ? blk.qty : null;
                    o[`p_${p.code}_r`] = blk ? blk.rpkg : null;
                    o[`p_${p.code}_n`] = blk ? blk.nilai : null;
                });
                o.jml_q = src.jumlah ? src.jumlah.qty : null;
                o.jml_r = src.jumlah ? src.jumlah.rpkg : null;
                o.jml_n = src.jumlah ? src.jumlah.nilai : null;
                return o;
            };
            this.all.groups.forEach(g => {
                list.push({ product: g.material, code: '', name: '', _section: true });
                g.rows.forEach(r => {
                    list.push(fill({ product: '', code: r.code || '—', name: r.name || '—' }, r));
                });
                list.push(fill({ product: 'Jumlah', code: '', name: '', _jumlah: true }, g.jumlah));
            });
            if (this.all.total) {
                list.push(fill({ product: 'Total', code: '', name: '', _grand: true }, this.all.total));
            }
            return list;
        },

        renderActive() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            let data, columns;
            if (this.activeTab === 'all') {
                data = this.allRows();
                columns = this.allColumns();
            } else {
                const isPlant = this.activeTab === 'plant';
                data = this.duaRows(isPlant ? this.plant : this.buyer);
                columns = this.duaColumns(isPlant);
            }

            this.table = new window.Tabulator('#pjl-active', {
                data, columns,
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
                    else if (d._section) { bg = '#d7e9df'; fw = '700'; }
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
