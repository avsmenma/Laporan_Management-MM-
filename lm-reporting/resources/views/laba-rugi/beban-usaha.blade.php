@extends('layouts.app')

@section('title', $cfg['title'])

@section('content')
<div x-data="bebanUsahaApp(@js($cfg))" x-init="init()" class="bu-page">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Bulan</label>
                <select class="filter-select" x-model.number="month" @change="render()">
                    <template x-for="m in 12" :key="m">
                        <option :value="m" x-text="bulanNama(m)"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model.number="year" @change="render()">
                    <template x-for="y in years()" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div class="bu-frame">
        <div class="tabs bu-tabs" x-show="cfg.tabs.length > 1">
            <template x-for="t in cfg.tabs" :key="t.key">
                <span class="tab" :class="{ active: activeTab === t.key }"
                      @click="setTab(t.key)" x-text="t.label"></span>
            </template>
        </div>
        <div class="report-card">
            <div class="bu-title-strip">
                <div>
                    <div class="bu-title" x-text="cfg.title"></div>
                    <div class="bu-subtitle">(<span x-text="cfg.subtitle"></span>)</div>
                </div>
                <div class="bu-note">Sumber data belum tersedia — seluruh nilai tampil “-”</div>
            </div>
            <div id="bu-table" class="lm-report-table"></div>
        </div>
    </div>
</div>

<style>
    .bu-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .bu-page .filter-bar { top: 0; }

    .bu-frame .bu-tabs { padding-left: 4px; flex-wrap: wrap; }
    .bu-frame .bu-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    .bu-frame .bu-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .bu-frame .bu-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .bu-frame .bu-tabs .tab.active { font-weight: 700; }
    .bu-frame .report-card { border-top-left-radius: 0; }
    .bu-frame .lm-report-table { border-top: 0; }

    .bu-title-strip { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; padding: 12px 14px 10px; border-bottom: 1px solid var(--line); background: #fff; }
    .bu-title { font-weight: 700; color: var(--green-900, #0f4c3a); letter-spacing: .02em; }
    .bu-subtitle { font-size: .8rem; color: #667; }
    .bu-note { font-size: .78rem; color: #8a6d1a; background: #fdf6e3; border: 1px solid #f0e3b8; border-radius: 8px; padding: 4px 10px; white-space: nowrap; }
</style>
@endsection

@push('scripts')
<script>
function bebanUsahaApp(cfg) {
    return {
        cfg,
        // Default mengikuti workbook acuan (Juni 2026); dropdown hanya mengubah
        // label periode di header kolom karena sumber data belum ada.
        month: 6,
        year: 2026,
        activeTab: cfg.tabs[0].key,
        table: null,

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },
        years() {
            return [2028, 2027, 2026, 2025];
        },
        setTab(key) {
            if (this.activeTab === key) return;
            this.activeTab = key;
            this.$nextTick(() => this.render());
        },

        // Nilai belum ada → semua sel angka '-' (negatif kelak dalam kurung, pola
        // sama dengan halaman Penjualan).
        numFmt(cell) {
            const v = cell.getValue();
            if (v == null || Number(v) === 0) return '-';
            const n = Number(v);
            const s = Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            return n < 0 ? '(' + s + ')' : s;
        },

        num(title, field, minWidth = 110) {
            return { title, field, hozAlign: 'right', headerHozAlign: 'center', formatter: this.numFmt.bind(this), minWidth };
        },

        // ===== Kolom per preset (label periode mengikuti dropdown) =====

        columns() {
            const bln = `Bulan ${this.bulanNama(this.month)} ${this.year}`;
            const sd = `sd Bulan ${this.bulanNama(this.month)} ${this.year}`;
            const sdLalu = `sd Bulan ${this.bulanNama(this.month)} ${this.year - 1}`;

            if (this.cfg.preset === 'penjualan') {
                // Sheet LM PENJUALAN: Rekg./Uraian | Bulan (Real, Anggaran) |
                // sd Bulan (Real, Anggaran) | sd thn lalu | Selisih (+/-, %Tase) | sd Bulan Lalu.
                return [
                    { title: 'Rekg.', field: 'kode', frozen: true, minWidth: 90 },
                    { title: 'Uraian', field: 'uraian', frozen: true, minWidth: 300 },
                    { title: bln, columns: [this.num('Realisasi', 'bln_r'), this.num('Anggaran', 'bln_a')] },
                    { title: sd, columns: [this.num('Realisasi', 'sd_r'), this.num('Anggaran', 'sd_a')] },
                    this.num(sdLalu, 'thn_lalu', 130),
                    { title: 'Selisih', columns: [this.num('+ / -', 'sel_v', 100), this.num('% Tase', 'sel_p', 80)] },
                    { title: 'sd Bulan Lalu', columns: [this.num('Realisasi', 'sdbl_r'), this.num('Anggaran', 'sdbl_a')] },
                ];
            }

            // Sheet LM ADMINISTRASI / BEBAN LAIN-LAIN / PENDAPATAN LAIN-LAIN:
            // Uraian [Regional Office, Kebun & Pabrik] | Bulan (Real, RKAP) | Selisih |
            // sd Bulan (Real, RKAP) | sd thn lalu | Selisih | sd Bulan Lalu (Real, RKAP).
            const cols = [{ title: 'U R A I A N', field: 'uraian', frozen: true, minWidth: 360 }];
            if (this.cfg.preset === 'lain') {
                cols.push(this.num('Regional Office', 'ro', 120));
                cols.push(this.num(this.cfg.kolomKedua || 'Kebun & Pabrik', 'kp', 120));
            }
            cols.push(
                { title: bln, columns: [this.num('Realisasi', 'bln_r'), this.num('RKAP', 'bln_a')] },
                { title: 'Selisih', columns: [this.num('+ / -', 'selbln_v', 100), this.num('%', 'selbln_p', 70)] },
                { title: sd, columns: [this.num('Realisasi', 'sd_r'), this.num('RKAP', 'sd_a')] },
                this.num(sdLalu, 'thn_lalu', 130),
                { title: 'Selisih', columns: [this.num('+ / -', 'selsd_v', 100), this.num('%', 'selsd_p', 70)] },
                { title: 'sd Bulan Lalu', columns: [this.num('Realisasi', 'sdbl_r'), this.num('RKAP', 'sdbl_a')] },
            );

            return cols;
        },

        rows() {
            const tab = this.cfg.tabs.find(t => t.key === this.activeTab) || this.cfg.tabs[0];
            // Nilai belum ada → hanya struktur baris; field angka sengaja tak diisi ('-').
            return tab.rows.map(r => ({ kode: r.k || '', uraian: r.u, _type: r.t || 'detail' }));
        },

        render() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            this.table = new window.Tabulator('#bu-table', {
                data: this.rows(),
                columns: this.columns(),
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                maxHeight: 'calc(100vh - 250px)',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null;
                    if (d._type === 'total') { bg = '#dcebe2'; fw = '700'; }
                    else if (d._type === 'subtotal') { bg = '#eef5f1'; fw = '700'; }
                    else if (d._type === 'header') { bg = '#d7e9df'; fw = '700'; }
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

        init() {
            this.$nextTick(() => this.render());
        },
    };
}
</script>
@endpush
