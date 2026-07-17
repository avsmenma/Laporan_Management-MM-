@extends('layouts.app')

@section('title', $cfg['title'])

@section('content')
<div x-data="bebanUsahaApp(@js($cfg))" x-init="init()" class="bu-page">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Bulan</label>
                <select class="filter-select" x-model.number="month" @change="onPeriodChange()">
                    <template x-for="m in months()" :key="m">
                        <option :value="m" x-text="bulanNama(m)"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model.number="year" @change="onPeriodChange()">
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
</style>
@endsection

@push('scripts')
<script>
function bebanUsahaApp(cfg) {
    return {
        cfg,
        // Default mengikuti workbook acuan (Juni 2026); bila halaman punya sumber
        // data (cfg.dataUrl), periode & nilai dimuat dari API.
        month: 6,
        year: 2026,
        periods: [],
        values: null,
        activeTab: cfg.tabs[0].key,
        table: null,

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },
        years() {
            if (this.periods.length > 0) {
                return [...new Set(this.periods.map(p => p.year))].sort((a, b) => b - a);
            }
            return [2028, 2027, 2026, 2025];
        },
        months() {
            if (this.periods.length > 0) {
                return [...new Set(this.periods.filter(p => Number(p.year) === Number(this.year)).map(p => Number(p.month)))].sort((a, b) => a - b);
            }
            return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        },
        setTab(key) {
            if (this.activeTab === key) return;
            this.activeTab = key;
            this.$nextTick(() => this.render());
        },
        onPeriodChange() {
            if (this.cfg.dataUrl) {
                this.load(false);
            } else {
                this.render();
            }
        },
        async load(adopt) {
            try {
                const sep = this.cfg.dataUrl.includes('?') ? '&' : '?';
                const url = this.cfg.dataUrl + (adopt ? '' : `${sep}year=${this.year}&month=${this.month}`);
                const resp = await fetch(url);
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                this.periods = data.periods || [];
                if (data.year != null) { this.year = data.year; this.month = data.month; }
                this.values = data.values || null;
            } catch (e) {
                this.values = null;
                if (window.lmToast) window.lmToast('Gagal memuat data: ' + e.message, 'err');
            }
            this.render();
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
            // Kolom RO/Kebun&Pabrik hanya bila cfg.kolomKedua diisi (Pendapatan Lainnya;
            // di Beban Ops Lainnya dihapus atas permintaan user).
            if (this.cfg.preset === 'lain' && this.cfg.kolomKedua) {
                cols.push(this.num('Regional Office', 'ro', 120));
                cols.push(this.num(this.cfg.kolomKedua, 'kp', 120));
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
            const vals = this.values ? (this.values[tab.key] || null) : null;
            return tab.rows.map((r, i) => {
                const row = { kode: r.k || '', uraian: r.u, _type: r.t || 'detail' };
                const v = vals ? vals[i] : null;
                if (!v) return row; // tanpa data → semua sel '-'
                // RKAP & tahun lalu belum ada sumber → '-'; Selisih = Realisasi − RKAP(0)
                // meniru rumus Excel (E6=C6-D6 dgn D kosong).
                row.bln_r = v.bln;
                row.sd_r = v.sd;
                row.sdbl_r = v.sdbl;
                row.selbln_v = v.bln;
                row.selsd_v = v.sd;
                if (this.cfg.preset === 'lain') {
                    row.ro = v.ro;
                    row.kp = v.kp;
                }
                return row;
            });
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
            if (this.cfg.dataUrl) {
                this.load(true); // adopsi periode terbaru dari data
            } else {
                this.$nextTick(() => this.render());
            }
        },
    };
}
</script>
@endpush
