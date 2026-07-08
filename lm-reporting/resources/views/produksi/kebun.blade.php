@extends('layouts.app')

@section('title', 'Produksi Kebun')

@section('content')
<div x-data="produksiKebunApp()" x-init="init()" class="produksi-page">
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

    <div x-show="hasData" x-cloak class="prod-frame">
        <div class="tabs prod-tabs">
            <template x-for="t in allTabs()" :key="t.key">
                <span class="tab" :class="{ active: activeTab === t.key }"
                      @click="setTab(t.key)" x-text="t.title"></span>
            </template>
        </div>
        <div class="report-card">
            <div id="prodkebun-active" class="lm-report-table"></div>
        </div>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🌴</div>
        <h3 style="color:#666;font-weight:500">Pilih bulan & tahun untuk melihat produksi TBS kebun</h3>
    </div>
</div>

<style>
    .produksi-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .produksi-page .filter-bar { top: 0; }
    .prod-frame .prod-tabs { padding-left: 4px; flex-wrap: wrap; }
    .prod-frame .prod-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    .prod-frame .prod-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .prod-frame .prod-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .prod-frame .prod-tabs .tab.active { font-weight: 700; }
    .prod-frame .report-card { border-top-left-radius: 0; }
    .prod-frame .lm-report-table { border-top: 0; }
</style>
@endsection

@push('scripts')
<script>
function produksiKebunApp() {
    return {
        periods: [],
        year: '',
        month: '',
        payload: null,
        hasData: false,
        errorMsg: null,
        table: null,
        activeTab: 'sendiri',

        allTabs() {
            return [
                { key: 'sendiri', title: 'Kebun Sendiri' },
                { key: 'pembelian', title: 'Pembelian' },
            ];
        },
        setTab(key) {
            if (this.activeTab === key) return;
            this.activeTab = key;
            this.$nextTick(() => this.renderActive());
        },

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },

        // Label periode kumulatif untuk header blok angka — meniru halaman /kebun
        // (mis. "s.d Bulan Mei"). Angka produksi TBS bersifat s.d bulan terpilih.
        sdLabel() {
            const nm = this.bulanNama(this.month);
            return nm ? ('s.d Bulan ' + nm) : 's.d Bulan';
        },

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
            if (!(this.month && ms.includes(Number(this.month)))) {
                this.month = '';
            }
            this.load();
        },

        qtyFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },

        async init() {
            await this.load(true);
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
                const resp = await fetch('/report-data/produksi/kebun' + q);
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

        // ---- Kebun Sendiri: UNIT KERJA + kolom Afdeling + Grand Total (Goods Recipient disembunyikan) ----
        sendiriColumns() {
            const fmt = this.qtyFmt.bind(this);
            // Kolom angka (afdeling + Grand Total) dikelompokkan di bawah header
            // "s.d Bulan {bulan}" agar sejajar dengan gaya halaman /kebun.
            const valueCols = [];
            (this.payload?.afdeling || []).forEach(a => {
                valueCols.push({ title: a, field: 'afd_' + a, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 95 });
            });
            valueCols.push({ title: 'Grand Total', field: 'grand_total', hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 120 });
            return [
                { title: 'UNIT KERJA', field: 'unit_kerja', frozen: true, minWidth: 220,
                  formatter: (c) => { const d = c.getRow().getData(); return d._type === 'grand' ? 'Grand Total' : (d.unit_kerja ?? ''); } },
                { title: this.sdLabel(), headerHozAlign: 'center', columns: valueCols },
            ];
        },
        sendiriRows() {
            const ks = this.payload?.kebun_sendiri;
            const afds = this.payload?.afdeling || [];
            if (!ks) return [];
            const mk = (src, type, unit) => {
                const o = { unit_kerja: unit, grand_total: src.grand_total ?? 0, _type: type };
                afds.forEach(a => { o['afd_' + a] = (src.afd && src.afd[a]) ? src.afd[a] : 0; });
                return o;
            };
            const rows = (ks.rows || []).map(r => mk(r, 'detail', r.unit_kerja || r.goods_recipient));
            rows.push(mk(ks.grand || { afd: {}, grand_total: 0 }, 'grand', 'Grand Total'));
            return rows;
        },

        // ---- Pembelian: PEMBELIAN + KODE SUPLIER + NAMA SUPPLIER + kolom Short Plant + Grand Total ----
        pembelianColumns() {
            const fmt = this.qtyFmt.bind(this);
            // Kolom angka (Short Plant + Grand Total) dikelompokkan di bawah header
            // "s.d Bulan {bulan}" agar sejajar dengan gaya halaman /kebun.
            const valueCols = [];
            (this.payload?.short_plant || []).forEach(sp => {
                valueCols.push({ title: sp, field: 'sp_' + sp, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 95 });
            });
            valueCols.push({ title: 'Grand Total', field: 'grand_total', hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 120 });
            return [
                { title: 'PEMBELIAN', field: 'kategori', frozen: true, minWidth: 150 },
                { title: 'KODE SUPLIER', field: 'supplier_code', frozen: true, minWidth: 120 },
                { title: 'NAMA SUPPLIER', field: 'supplier_name', frozen: true, minWidth: 240 },
                { title: this.sdLabel(), headerHozAlign: 'center', columns: valueCols },
            ];
        },
        pembelianRows() {
            const pb = this.payload?.pembelian;
            const sps = this.payload?.short_plant || [];
            if (!pb) return [];
            const fill = (o, src) => { sps.forEach(sp => { o['sp_' + sp] = (src.sp && src.sp[sp]) ? src.sp[sp] : 0; }); return o; };
            const rows = [];
            (pb.groups || []).forEach(g => {
                (g.rows || []).forEach((r, i) => {
                    rows.push(fill({
                        kategori: i === 0 ? g.kategori : '',
                        supplier_code: r.supplier_code,
                        supplier_name: r.supplier_name,
                        grand_total: r.grand_total ?? 0,
                        _type: 'detail',
                    }, r));
                });
                rows.push(fill({
                    kategori: g.kategori + ' Total', supplier_code: '', supplier_name: '',
                    grand_total: g.subtotal?.grand_total ?? 0, _type: 'subtotal',
                }, g.subtotal || { sp: {} }));
            });
            rows.push(fill({
                kategori: 'Grand Total', supplier_code: '', supplier_name: '',
                grand_total: pb.grand?.grand_total ?? 0, _type: 'grand',
            }, pb.grand || { sp: {} }));
            return rows;
        },

        renderActive() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            const isSendiri = this.activeTab === 'sendiri';
            const columns = isSendiri ? this.sendiriColumns() : this.pembelianColumns();
            const rows = isSendiri ? this.sendiriRows() : this.pembelianRows();
            this.table = new window.Tabulator('#prodkebun-active', {
                data: rows, columns,
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                // Tinggi tetap → tabel punya area gulir sendiri sehingga header tabel
                // tetap terlihat (frozen) saat baris digulir ke bawah.
                height: '70vh',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null;
                    // Baris total (Grand Total kebun sendiri & total/subtotal pembelian)
                    // pakai warna band hijau muda yang sama dgn seksi "PRODUKSI TBS"
                    // pada halaman produksi/pks (tab Ringkasan).
                    if (d._type === 'grand' || d._type === 'subtotal') { bg = '#d7e9df'; fw = '700'; }
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
