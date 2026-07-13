@extends('layouts.app')

@section('title', 'Alokasi Biaya Olah')

@section('content')
<div x-data="alokasiBiayaOlahApp()" x-init="init()" class="abo-page">
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

    <div x-show="hasData" x-cloak class="abo-frame">
        <div class="tabs abo-tabs">
            <template x-for="t in tabDefs" :key="t.key">
                <span class="tab" :class="{ active: activeTab === t.key }"
                      @click="setTab(t.key)" x-text="t.title"></span>
            </template>
        </div>
        <div class="report-card">
            <div id="abo-active" class="lm-report-table"></div>
        </div>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🏭</div>
        <h3 style="color:#666;font-weight:500">Pilih bulan &amp; tahun untuk melihat Alokasi Biaya Olah</h3>
    </div>
</div>

<style>
    .abo-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .abo-page .filter-bar { top: 0; }

    .abo-frame .abo-tabs { padding-left: 4px; flex-wrap: wrap; }
    .abo-frame .abo-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    .abo-frame .abo-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .abo-frame .abo-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .abo-frame .abo-tabs .tab.active { font-weight: 700; }
    .abo-frame .report-card { border-top-left-radius: 0; }
    .abo-frame .lm-report-table { border-top: 0; }
</style>
@endsection

@push('scripts')
<script>
function alokasiBiayaOlahApp() {
    return {
        periods: [],
        year: '',
        month: '',
        plants: [],
        kebun: [],
        hasData: false,
        errorMsg: null,
        table: null,
        activeTab: 'summary',

        // 4 tab; tiap tab memakai kerangka matriks Kebun × PKS yang sama.
        // `cost` = label baris pool biaya paling atas (mengikuti Sheet2 acuan).
        tabDefs: [
            { key: 'summary',    title: 'Summary',           cost: 'Summary' },
            { key: 'pengolahan', title: 'Biaya Pengolahan',  cost: 'Biaya Pengolahan' },
            { key: 'overhead',   title: 'Biaya Overhead',    cost: 'Biaya Overhead' },
            { key: 'depresiasi', title: 'Biaya Depresiasi',  cost: 'Biaya Depresiasi' },
        ],

        // Nilai belum dihitung pada fase ini → tampilkan '-' sebagai placeholder.
        valFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },

        years() {
            return [...new Set(this.periods.map(p => p.year))].sort((a, b) => b - a);
        },
        months() {
            return this.periods
                .filter(p => String(p.year) === String(this.year))
                .map(p => Number(p.month))
                .sort((a, b) => a - b);
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
                const resp = await fetch('/report-data/alokasi-biaya-olah' + q);
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
                this.kebun = data.kebun || [];
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

        // Kolom: Kebun & Nama Kebun (frozen) + kolom per PKS + JLH.
        columns() {
            const cols = [
                { title: 'Kebun', field: 'kebun', frozen: true, minWidth: 110,
                  formatter: (c) => { const d = c.getRow().getData(); return d._grand ? 'Grand Total' : (d.kebun ?? ''); } },
                { title: 'Nama Kebun', field: 'nama', frozen: true, minWidth: 190 },
            ];
            this.plants.forEach(p => cols.push({
                title: p.name || p.code, field: `v_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                formatter: this.valFmt.bind(this), minWidth: 95,
            }));
            cols.push({ title: 'JLH', field: 'v_grand', hozAlign: 'right', headerHozAlign: 'center',
                formatter: this.valFmt.bind(this), minWidth: 120 });
            return cols;
        },

        // Baris (identik Sheet2): pool biaya → "Proporsi:" → daftar Kebun → Grand Total.
        rows(costLabel) {
            const blank = () => {
                const o = {};
                this.plants.forEach(p => { o[`v_${p.code}`] = null; });
                o['v_grand'] = null;
                return o;
            };
            const list = [];
            list.push({ kebun: costLabel, nama: '', _cost: true, ...blank() });
            list.push({ kebun: 'Proporsi:', nama: '', _section: true });
            this.kebun.forEach(k => list.push({ kebun: k.code, nama: k.nama, ...blank() }));
            list.push({ kebun: '', nama: '', _grand: true, ...blank() });
            return list;
        },

        renderActive() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            const def = this.tabDefs.find(d => d.key === this.activeTab);
            if (!def) return;

            this.table = new window.Tabulator('#abo-active', {
                data: this.rows(def.cost),
                columns: this.columns(),
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null, italic = false;
                    if (d._grand) { bg = '#eef5f1'; fw = '700'; }
                    else if (d._section) { bg = '#d7e9df'; fw = '700'; italic = true; }
                    else if (d._cost) { bg = '#eef5f1'; fw = '700'; }
                    if (!bg && !fw) return;
                    const el = row.getElement();
                    if (fw) el.style.fontWeight = fw;
                    if (bg) el.style.background = bg;
                    if (italic) el.style.fontStyle = 'italic';
                    row.getCells().forEach((c) => {
                        const ce = c.getElement();
                        if (fw) ce.style.fontWeight = fw;
                        if (bg) ce.style.background = bg;
                        if (italic) ce.style.fontStyle = 'italic';
                    });
                },
            });
        },
    };
}
</script>
@endpush
