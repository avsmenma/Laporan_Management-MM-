@extends('layouts.app')

@section('title', 'LM 34')

@section('content')
<div x-data="lm34App()" x-init="init()" class="lm34-page">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Bulan</label>
                <select class="filter-select" x-model.number="month" @change="render()">
                    <template x-for="m in months()" :key="m">
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

    <div class="lm34-frame">
        <div class="report-card">
            <div class="lm34-title-strip">
                <div>
                    <div class="lm34-title">DAFTAR PENJUALAN EKSPOR DAN LOKAL</div>
                    <div class="lm34-subtitle">s.d. bulan : <span x-text="bulanNama(month) + ' ' + year"></span></div>
                </div>
                <div class="lm34-badge">LM - 34</div>
            </div>
            <div id="lm34-table" class="lm-report-table"></div>
        </div>
    </div>
</div>

<style>
    .lm34-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .lm34-page .filter-bar { top: 0; }
    .lm34-frame .lm-report-table { border-top: 0; }

    .lm34-title-strip { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; padding: 12px 14px 10px; border-bottom: 1px solid var(--line); background: #fff; }
    .lm34-title { font-weight: 700; color: var(--green-900, #0f4c3a); letter-spacing: .02em; }
    .lm34-subtitle { font-size: .8rem; color: #667; }
    .lm34-badge { font-weight: 700; font-size: .8rem; color: var(--green-900, #0f4c3a); border: 1px solid var(--line); border-radius: 6px; padding: 4px 10px; background: #eef5f1; }
</style>
@endsection

@push('scripts')
<script>
function lm34App() {
    return {
        // Data by tarikan (belum ada sumber) → seluruh nilai '-' dulu; filter
        // periode disiapkan untuk tarikan kelak. Default mengikuti template.
        month: 6,
        year: 2026,
        table: null,

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },
        years() {
            return [2028, 2027, 2026, 2025];
        },
        months() {
            return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        },

        // Sel angka: baris seksi/judul kosong; selain itu 0/null → '-';
        // negatif dalam kurung (pola halaman laba-rugi lain).
        numFmt(dec) {
            return (cell) => {
                const d = cell.getRow().getData();
                if (d._type === 'section' || d._type === 'header') return '';
                const v = cell.getValue();
                if (v == null || Number(v) === 0) return '-';
                const n = Number(v);
                const s = Math.abs(n).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
                return n < 0 ? '(' + s + ')' : s;
            };
        },

        // ---- Kolom persis template LM34.xlsx: Volume Penjualan | Harga Jual per kg |
        // Hasil Yang Terjual (di tengah, ikut Excel) | Jumlah Nilai Dollar FOB |
        // Jumlah Nilai Penjualan | Selisih Lebih(Kurang) ----
        columns() {
            const qty = this.numFmt(0);
            const hrg = this.numFmt(2);
            const col = (title, field, fmt, minWidth) => ({ title, field, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth });
            return [
                { title: 'Volume Penjualan', headerHozAlign: 'center', columns: [
                    { title: 'Realisasi', headerHozAlign: 'center', columns: [col('Bulan ini', 'vol_r_bln', qty, 100), col('s.d Bulan ini', 'vol_r_sd', qty, 100)] },
                    { title: 'Anggaran', headerHozAlign: 'center', columns: [col('Bulan ini', 'vol_a_bln', qty, 100), col('s.d Bulan ini', 'vol_a_sd', qty, 100)] },
                ] },
                { title: 'Harga Jual per kg', headerHozAlign: 'center', columns: [
                    { title: 'Bulan ini', headerHozAlign: 'center', columns: [col('Realisasi', 'hrg_bln_r', hrg, 95), col('Anggaran', 'hrg_bln_a', hrg, 95)] },
                    { title: 's.d Bulan ini', headerHozAlign: 'center', columns: [col('Realisasi', 'hrg_sd_r', hrg, 95), col('Anggaran', 'hrg_sd_a', hrg, 95)] },
                ] },
                { title: 'Hasil Yang Terjual', field: 'u', minWidth: 280, headerHozAlign: 'center' },
                { title: 'Jumlah Nilai Dollar FOB', headerHozAlign: 'center', columns: [
                    { title: 'Realisasi', headerHozAlign: 'center', columns: [col('Bulan ini', 'fob_bln', qty, 105), col('s.d Bulan ini', 'fob_sd', qty, 105)] },
                ] },
                { title: 'Jumlah Nilai Penjualan', headerHozAlign: 'center', columns: [
                    { title: 'Realisasi', headerHozAlign: 'center', columns: [col('Bulan ini', 'nil_r_bln', qty, 130), col('s.d Bulan ini', 'nil_r_sd', qty, 130)] },
                    { title: 'Anggaran', headerHozAlign: 'center', columns: [col('Bulan ini', 'nil_a_bln', qty, 130), col('s.d Bulan ini', 'nil_a_sd', qty, 130)] },
                ] },
                { title: 'Selisih Lebih(Kurang)', headerHozAlign: 'center', columns: [col('sd. Bulan ini', 'selisih', qty, 140)] },
            ];
        },

        // ---- Baris persis template (label verbatim dari sheet LM-34) ----
        rows() {
            const s = (u) => ({ u, _type: 'section' });
            const h = (u) => ({ u, _type: 'header' });
            const d = (u) => ({ u, _type: 'detail' });
            const j = (u) => ({ u, _type: 'subtotal' });
            const t = (u) => ({ u, _type: 'total' });
            return [
                s('L o k a l'),
                h('T B S'),
                d('Kebun Sendiri'),
                d('Kebun Plasma + Pihak III'),
                j('Jumlah'),
                h('A. Kelapa Sawit ( Kg )'),
                d('- Minyak Sawit ( CPO )'),
                d('- Inti Sawit ( PK )'),
                d('- Minyak Sawit ( CPO ) hasil titip olah'),
                d('- Inti Sawit ( PK ) hasil titip olah'),
                j('Jumlah A.'),
                h('B. G u l a'),
                d('- G u l a'),
                j('Jumlah B.'),
                h('C. Karet ( Kg )'),
                d('- Lump Kering (Sinta,Lokal,Kumai, Tambarangan & Dasal)'),
                d('- Lump Kering (Batu Licin)'),
                d('- RSS. 1'),
                d('- RSS. 2'),
                d('- RSS. 3'),
                d('- RSS. 4'),
                d('- Cutting A'),
                d('- Cutting B'),
                d('- Brown Crepe 1 x'),
                d('- Brown Crepe 2 x'),
                d('- Sir - 20 KAR'),
                d('- Sir - 20 KAB'),
                d('- Sir - 20 KAR'),
                d('- Sir - 20 KAU'),
                d('- Sir - 20 KAY'),
                d('- Sir - 20 KBB'),
                d('- Sir - 20 KBY'),
                d('- Sir - 20 KBF'),
                d('- Sir - 20 KBJ'),
                d('- Sir - 20 KBS'),
                j('Jumlah C.'),
                t('Jumlah Lokal ( A + B + C )'),
                t('Jumlah Ekspor + Lokal'),
            ];
        },

        render() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            this.table = new window.Tabulator('#lm34-table', {
                data: this.rows(), columns: this.columns(),
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                maxHeight: 'calc(100vh - 260px)',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null, italic = false;
                    if (d._type === 'section') { bg = '#d7e9df'; fw = '700'; italic = true; }
                    else if (d._type === 'total') { bg = '#dcebe2'; fw = '700'; }
                    else if (d._type === 'subtotal') { bg = '#eef5f1'; fw = '700'; }
                    else if (d._type === 'header') { fw = '700'; }
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

        init() {
            this.$nextTick(() => this.render());
        },
    };
}
</script>
@endpush
