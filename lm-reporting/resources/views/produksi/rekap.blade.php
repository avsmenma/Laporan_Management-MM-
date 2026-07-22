@extends('layouts.app')

@section('title', 'Rekap Produksi')

@section('content')
<div x-data="rekapProduksiApp()" x-init="init()" class="produksi-page">
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
        <div class="report-card">
            <div id="rekap-produksi-table" class="lm-report-table"></div>
        </div>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🌴</div>
        <h3 style="color:#666;font-weight:500">Pilih bulan & tahun untuk melihat rekap produksi</h3>
    </div>
</div>

<style>
    .produksi-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .produksi-page .filter-bar { top: 0; }
    .prod-frame .report-card { border-top-left-radius: 12px; }
    .prod-frame .lm-report-table { border-top: 0; }
</style>
@endsection

@push('scripts')
<script>
function rekapProduksiApp() {
    return {
        periods: [],
        year: '',
        month: '',
        payload: null,
        hasData: false,
        errorMsg: null,
        table: null,

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },
        years() {
            return [...new Set(this.periods.map(p => p.year))].sort((a, b) => b - a);
        },
        months() {
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
            const d = cell.getRow().getData();
            if (d._section || d._group) return '';
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },
        pctFmt(cell) {
            const d = cell.getRow().getData();
            if (d._section || d._group) return '';
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        async init() {
            // Muat-awal: adopsi periode terbaru dari server (pola /produksi/pks).
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
                const resp = await fetch('/report-data/produksi/rekap' + q);
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
                    this.$nextTick(() => this.render());
                }
            } catch (e) {
                this.hasData = false;
                this.errorMsg = e.message;
                if (window.lmToast) window.lmToast(e.message, 'err');
            }
        },

        // ---- Kolom: identitas frozen + 5 blok nilai + 3 blok rasio (identik template Excel) ----
        columns() {
            const qty = this.qtyFmt.bind(this);
            const pct = this.pctFmt.bind(this);
            const blockCols = (b) => ([
                { title: 'TBS DITERIMA', field: b + '_tbs_diterima', hozAlign: 'right', headerHozAlign: 'center', formatter: qty, minWidth: 105 },
                { title: 'TBS DIOLAH', field: b + '_tbs_diolah', hozAlign: 'right', headerHozAlign: 'center', formatter: qty, minWidth: 105 },
                { title: 'MS', field: b + '_ms', hozAlign: 'right', headerHozAlign: 'center', formatter: qty, minWidth: 90 },
                { title: 'IS', field: b + '_is', hozAlign: 'right', headerHozAlign: 'center', formatter: qty, minWidth: 90 },
                { title: 'Rend MS', field: b + '_rend_ms', hozAlign: 'right', headerHozAlign: 'center', formatter: pct, minWidth: 80 },
                { title: 'Rend IS', field: b + '_rend_is', hozAlign: 'right', headerHozAlign: 'center', formatter: pct, minWidth: 80 },
            ]);
            const ratioCols = (g) => ([
                { title: 'TBS', field: g + '_tbs', hozAlign: 'right', headerHozAlign: 'center', formatter: pct, minWidth: 80 },
                { title: 'RMS', field: g + '_rms', hozAlign: 'right', headerHozAlign: 'center', formatter: pct, minWidth: 80 },
                { title: 'RIS', field: g + '_ris', hozAlign: 'right', headerHozAlign: 'center', formatter: pct, minWidth: 80 },
            ]);
            return [
                { title: 'No.', field: 'no', frozen: true, minWidth: 46, hozAlign: 'left' },
                { title: 'Kode Kebun', field: 'kode', frozen: true, minWidth: 90 },
                { title: 'Nama Kebun', field: 'nama', frozen: true, minWidth: 190 },
                { title: 'BULAN LALU', headerHozAlign: 'center', columns: blockCols('bl') },
                { title: 'BULAN INI', headerHozAlign: 'center', columns: blockCols('bi') },
                { title: 'S.D BULAN INI', headerHozAlign: 'center', columns: blockCols('sd') },
                { title: 'RKAP BULAN INI', headerHozAlign: 'center', columns: blockCols('rkap_bi') },
                { title: 'RKAP S.D BULAN INI', headerHozAlign: 'center', columns: blockCols('rkap_sd') },
                { title: 'BI/BL', headerHozAlign: 'center', columns: ratioCols('bi_bl') },
                { title: 'BI/RKAP', headerHozAlign: 'center', columns: ratioCols('bi_rkap') },
                { title: 'S.D BI/RKAP', headerHozAlign: 'center', columns: ratioCols('sd_rkap') },
            ];
        },

        // ---- Baris: band seksi (I. Kebun / II. Plasma/Pihak III / III. PKS) → detail → JUMLAH ----
        rows() {
            const out = [];
            const flat = (r, extra = {}) => {
                // r.group = judul kelompok per PKS di seksi II (kode + nama tampil,
                // seluruh angka disembunyikan); r.subtotal = JUMLAH per PKS.
                const o = { no: '', kode: r.code || '', nama: r.nama || '', ...(r.group ? { _group: true } : {}), ...(r.subtotal ? { _subtotal: true } : {}), ...extra };
                ['bl', 'bi', 'sd', 'rkap_bi', 'rkap_sd'].forEach(b => {
                    const blk = r[b] || {};
                    ['tbs_diterima', 'tbs_diolah', 'ms', 'is', 'rend_ms', 'rend_is'].forEach(m => {
                        o[b + '_' + m] = blk[m] ?? 0;
                    });
                });
                ['bi_bl', 'bi_rkap', 'sd_rkap'].forEach(g => {
                    const grp = (r.ratio || {})[g] || {};
                    ['tbs', 'rms', 'ris'].forEach(m => { o[g + '_' + m] = grp[m] ?? 0; });
                });
                return o;
            };
            (this.payload?.sections || []).forEach(sec => {
                out.push({ no: '', kode: '', nama: sec.title, _section: true });
                (sec.rows || []).forEach(r => out.push(flat(r)));
                if (sec.total) out.push(flat(sec.total, { _total: true }));
            });
            return out;
        },

        render() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            this.table = new window.Tabulator('#rekap-produksi-table', {
                data: this.rows(), columns: this.columns(),
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                // Tinggi tetap → area gulir sendiri sehingga header tetap terlihat.
                height: '72vh',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null, italic = false;
                    if (d._section) { bg = '#d7e9df'; fw = '700'; italic = true; }
                    else if (d._total) { bg = '#eef5f1'; fw = '700'; }
                    // JUMLAH per PKS (seksi Plasma/Pihak III): tebal + latar lebih muda.
                    else if (d._subtotal) { bg = '#f4f9f6'; fw = '700'; }
                    // Judul kelompok per PKS: tebal saja, tanpa latar (nilai kosong).
                    else if (d._group) { fw = '600'; }
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
