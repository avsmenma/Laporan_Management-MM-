@extends('layouts.app')

@section('title', 'Produksi')

@section('content')
<div x-data="produksiApp()" x-init="init()">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Tanggal Posting</label>
                <select class="filter-select" x-model="date" @change="load()">
                    <option value="">— pilih tanggal —</option>
                    <template x-for="d in dates" :key="d">
                        <option :value="d" x-text="d"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div x-show="errorMsg" x-cloak class="lm-error-panel" x-text="errorMsg"></div>

    <div class="report-card" x-show="hasData" x-cloak>
        {{-- Ringkasan --}}
        <h3 class="prod-title">Ringkasan</h3>
        <div id="prod-ringkasan" class="lm-report-table"></div>

        {{-- 6 tabel pivot --}}
        <template x-for="t in tableDefs" :key="t.key">
            <div style="margin-top:22px">
                <h3 class="prod-title" x-text="t.title"></h3>
                <div :id="'prod-' + t.key" class="lm-report-table"></div>
            </div>
        </template>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🏭</div>
        <h3 style="color:#666;font-weight:500">Pilih tanggal untuk melihat laporan produksi PKS</h3>
    </div>
</div>

<style>
    .prod-title { color: var(--g-700, #0f4c3a); font-weight: 700; margin: 0 0 8px; font-size: 14px; }
</style>
@endsection

@push('scripts')
<script>
function produksiApp() {
    return {
        dates: [],
        date: '',
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

        async init() {
            await this.load();
        },

        async load() {
            this.errorMsg = null;
            try {
                const q = this.date ? ('?date=' + encodeURIComponent(this.date)) : '';
                const resp = await fetch('/report-data/produksi' + q);
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.message || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                this.dates = data.dates || [];
                this.date = data.date || '';
                this.plants = data.plants || [];
                this.kebun = data.kebun || [];
                this.payload = data;
                this.hasData = (this.dates.length > 0);
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
