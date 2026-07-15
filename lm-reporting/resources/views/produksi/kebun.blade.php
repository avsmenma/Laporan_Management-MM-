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

        // Tab Pembelian sudah dipindah ke halaman /produksi/pembelian (menu Produksi).
        allTabs() {
            return [
                { key: 'sendiri', title: 'Kebun Sendiri' },
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

        // Label header blok angka — meniru halaman /kebun: blok "Bulan {bulan}"
        // (nilai bulan terpilih) & blok "s.d Bulan {bulan}" (kumulatif).
        biLabel() {
            const nm = this.bulanNama(this.month);
            return nm ? ('Bulan ' + nm) : 'Bulan Ini';
        },
        sdLabel() {
            const nm = this.bulanNama(this.month);
            return nm ? ('s.d Bulan ' + nm) : 's.d Bulan';
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

        qtyFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },

        async init() {
            // Muat daftar periode untuk mengisi opsi dropdown, TAPI jangan auto-pilih
            // bulan/tahun — biarkan kosong sampai user memilih sendiri.
            try {
                const resp = await fetch('/report-data/produksi/kebun');
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
            const afds = this.payload?.afdeling || [];
            // Dua blok kolom angka (Afdeling + Grand Total): "Bulan {bulan}" & "s.d Bulan {bulan}".
            const block = (prefix) => {
                const cols = afds.map(a => ({ title: a, field: prefix + '_afd_' + a, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 95 }));
                cols.push({ title: 'Grand Total', field: prefix + '_grand_total', hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth: 120 });
                return cols;
            };
            return [
                { title: 'UNIT KERJA', field: 'unit_kerja', frozen: true, minWidth: 220,
                  formatter: (c) => { const d = c.getRow().getData(); return d._type === 'grand' ? 'Grand Total' : (d.unit_kerja ?? ''); } },
                { title: this.biLabel(), headerHozAlign: 'center', columns: block('bi') },
                { title: this.sdLabel(), headerHozAlign: 'center', columns: block('sd') },
            ];
        },
        sendiriRows() {
            const ks = this.payload?.kebun_sendiri;
            const afds = this.payload?.afdeling || [];
            if (!ks) return [];
            const mk = (src, type, unit) => {
                const o = { unit_kerja: unit, _type: type };
                ['bi', 'sd'].forEach(p => {
                    const blk = src[p] || { afd: {}, grand_total: 0 };
                    o[p + '_grand_total'] = blk.grand_total ?? 0;
                    afds.forEach(a => { o[p + '_afd_' + a] = (blk.afd && blk.afd[a]) ? blk.afd[a] : 0; });
                });
                return o;
            };
            const rows = (ks.rows || []).map(r => mk(r, 'detail', r.unit_kerja || r.goods_recipient));
            const zero = { bi: { afd: {}, grand_total: 0 }, sd: { afd: {}, grand_total: 0 } };
            rows.push(mk(ks.grand || zero, 'grand', 'Grand Total'));
            return rows;
        },

        renderActive() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            const columns = this.sendiriColumns();
            const rows = this.sendiriRows();
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
