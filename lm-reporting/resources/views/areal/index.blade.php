@extends('layouts.app')

@section('title', 'Areal')

@section('content')
<div x-data="arealApp()" x-init="init()">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Komoditas</label>
                <select class="filter-select" x-model="filters.komoditi" @change="onKomoditiChange()">
                    <option value="">— pilih komoditas —</option>
                    <option value="KS">Kelapa Sawit</option>
                    <option value="KR">Karet</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model="filters.year" @change="syncBatch(); load()">
                    <option value="">— pilih tahun —</option>
                    <template x-for="y in years()" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Periode (Bulan)</label>
                <select class="filter-select" x-model="filters.month" @change="load()">
                    <option value="">— pilih bulan —</option>
                    <template x-for="m in months()" :key="m">
                        <option :value="m" x-text="bulanNama(m)"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Unit Kebun</label>
                <select class="filter-select" x-model="filters.unit" @change="load()">
                    <option value="">— pilih unit —</option>
                    <option value="ALL">Semua Unit</option>
                    <template x-for="u in units" :key="u.code">
                        <option :value="u.code" x-text="`${u.code} - ${u.name}`"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div class="report-card" x-show="hasData" x-cloak>
        <div id="areal-table" class="lm-report-table"></div>
    </div>

    <div x-show="errorMsg" x-cloak class="lm-error-panel" x-text="errorMsg"></div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🗂️</div>
        <h3 style="color:#666;font-weight:500">Pilih unit &amp; periode untuk melihat data areal</h3>
        <p style="color:#999;margin-top:0.5rem">Pilih Komoditas, Tahun, Bulan, dan Unit Kebun</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
function arealApp() {
    return {
        filters: { komoditi: '', year: '', month: '', unit: '' },
        batches: [],
        units: [],
        hasData: false,
        errorMsg: null,
        table: null,

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },

        async init() {
            // Tidak pra-pilih apa pun; user memilih Komoditas → Tahun → Bulan → Unit sendiri
            // (sejajar perilaku halaman Kebun). Unit dimuat saat komoditas dipilih.
            try {
                const resp = await fetch('/api/batches');
                const json = await resp.json();
                this.batches = json.data ?? json;
            } catch (e) {
                console.error('Gagal memuat batches:', e);
            }
        },

        years() {
            return [...new Set(this.batches.map(x => x.year))].sort((a, b) => b - a);
        },

        months() {
            return this.batches
                .filter(x => String(x.year) === String(this.filters.year))
                .map(x => Number(x.period ?? x.month))
                .sort((a, b) => a - b);
        },

        syncBatch() {
            const ms = this.months();
            if (!ms.includes(Number(this.filters.month))) {
                this.filters.month = ms[0] ?? '';
            }
        },

        async loadUnits() {
            try {
                const resp = await fetch(`/api/units?type=KEBUN&komoditi=${this.filters.komoditi}`);
                const json = await resp.json();
                this.units = json.data ?? json;
            } catch (e) {
                console.error('Gagal memuat units:', e);
            }
        },

        async onKomoditiChange() {
            this.filters.unit = ''; // buang unit lama agar tidak menarik data komoditi sebelumnya
            this.units = [];
            if (this.filters.komoditi) {
                await this.loadUnits();
            }
            await this.load();
        },

        async load() {
            if (!this.filters.komoditi || !this.filters.unit || !this.filters.year || !this.filters.month) {
                this.hasData = false;
                return;
            }
            this.errorMsg = null;
            try {
                const q = `year=${this.filters.year}&month=${this.filters.month}&komoditi=${this.filters.komoditi}&unit=${this.filters.unit}`;
                const resp = await fetch(`/report-data/areal?${q}`);
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.message || `HTTP ${resp.status}`);
                }
                const data = await resp.json();
                this.render(data);
            } catch (e) {
                this.hasData = false;
                this.errorMsg = e.message;
                if (window.lmToast) window.lmToast(e.message, 'err');
            }
        },

        render(data) {
            const luasFmt = (cell) => {
                const v = cell.getValue();
                return (v == null || Math.abs(Number(v)) < 0.005)
                    ? '-'
                    : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };
            const pokokFmt = (cell) => {
                const v = cell.getValue();
                return (v == null || Number(v) === 0)
                    ? '-'
                    : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            };

            const cols = [
                {
                    title: 'Status Blok/Petak',
                    field: 'status',
                    frozen: true,
                    minWidth: 160,
                    formatter: (cell) => {
                        const d = cell.getRow().getData();
                        if (d._type === 'subtotal' || d._type === 'grandtotal') {
                            return d.label ?? '';
                        }
                        // Kode status cukup ditampilkan sekali di baris teratas tiap grup;
                        // baris berikutnya dibiarkan kosong (baris paling atas mewakili).
                        return d._firstOfStatus ? (d.status ?? '') : '';
                    },
                },
                {
                    title: 'Tahun Tanam',
                    field: 'tahun_tanam',
                    frozen: true,
                    minWidth: 110,
                    hozAlign: 'center',
                    headerHozAlign: 'center',
                    formatter: (cell) => {
                        const d = cell.getRow().getData();
                        if (d._type === 'subtotal' || d._type === 'grandtotal') return '';
                        const v = cell.getValue();
                        return (v != null && v !== '') ? v : '';
                    },
                },
            ];

            (data.afds || []).forEach(afd => {
                cols.push({
                    title: afd,
                    headerHozAlign: 'center',
                    columns: [
                        {
                            title: 'Luas [Ha]',
                            field: `luas_${afd}`,
                            hozAlign: 'right',
                            headerHozAlign: 'center',
                            formatter: luasFmt,
                            minWidth: 90,
                        },
                        {
                            title: 'Jlh Pokok',
                            field: `pokok_${afd}`,
                            hozAlign: 'right',
                            headerHozAlign: 'center',
                            formatter: pokokFmt,
                            minWidth: 90,
                        },
                    ],
                });
            });

            cols.push({
                title: 'Total Luas [Ha]',
                field: 'tluas',
                hozAlign: 'right',
                headerHozAlign: 'center',
                formatter: luasFmt,
                minWidth: 120,
            });
            cols.push({
                title: 'Total Jlh Pokok',
                field: 'tpokok',
                hozAlign: 'right',
                headerHozAlign: 'center',
                formatter: pokokFmt,
                minWidth: 120,
            });

            let prevDetailStatus = null;
            const rows = (data.rows || []).map(r => {
                const o = {
                    status: r.status ?? '',
                    tahun_tanam: r.tahun_tanam ?? '',
                    label: r.label ?? null,
                    _type: r.type,
                    tluas: r.total?.luas ?? 0,
                    tpokok: r.total?.pokok ?? 0,
                };
                // Tandai baris detail pertama tiap grup status (detail satu status berurutan).
                if (r.type === 'detail') {
                    o._firstOfStatus = (r.status !== prevDetailStatus);
                    prevDetailStatus = r.status;
                }
                (data.afds || []).forEach(a => {
                    o[`luas_${a}`] = r.cells?.[a]?.luas ?? 0;
                    o[`pokok_${a}`] = r.cells?.[a]?.pokok ?? 0;
                });
                return o;
            });

            if (this.table) {
                this.table.destroy();
                this.table = null;
            }

            this.hasData = rows.length > 0;
            if (!this.hasData) return;

            this.$nextTick(() => {
                this.table = new window.Tabulator('#areal-table', {
                    data: rows,
                    columns: cols,
                    columnDefaults: { headerSort: false }, // hapus fitur sort (tanpa ikon panah)
                    layout: 'fitDataStretch',
                    height: '70vh',
                    rowFormatter: (row) => {
                        const t = row.getData()._type;
                        // Baris "{kode} Total" diberi band hijau + garis batas atas/bawah agar
                        // menjadi pemisah visual yang jelas antar grup status.
                        let bg = null, fw = null;
                        if (t === 'subtotal') { bg = '#d7e9df'; fw = '700'; }
                        else if (t === 'grandtotal') { bg = '#c3dccf'; fw = '800'; }
                        if (!bg) return;
                        // Band hijau sudah cukup menandai baris Total; garis batas dibuat
                        // tipis (1px) dan hanya di sisi atas sebagai pemisah antar grup —
                        // bukan kotak tebal atas+bawah yang terkesan ramai.
                        const border = '1px solid #0f4c3a';
                        const el = row.getElement();
                        el.style.fontWeight = fw;
                        el.style.background = bg;
                        el.style.borderTop = border;
                        el.style.borderBottom = 'none';
                        // Sel beku (Status Blok/Petak & Tahun Tanam) dirender di container terpisah,
                        // jadi warnai tiap sel agar band hijau & garis batas menutup seluruh baris.
                        row.getCells().forEach((c) => {
                            const ce = c.getElement();
                            ce.style.background = bg;
                            ce.style.fontWeight = fw;
                            ce.style.borderTop = border;
                            ce.style.borderBottom = 'none';
                        });
                    },
                });
            });
        },
    };
}
</script>
@endpush
