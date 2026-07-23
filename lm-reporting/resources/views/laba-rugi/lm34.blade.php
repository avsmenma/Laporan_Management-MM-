@extends('layouts.app')

@section('title', 'LM 34')

@section('content')
<div x-data="lm34App()" x-init="init()" class="lm34-page">
    <div class="filter-bar">
        <div class="filter-grid">
            {{-- Opsi dirender server (bukan x-for) supaya nilai awal Juni 2026 langsung terpilih --}}
            <div class="filter-group">
                <label class="filter-label">Bulan</label>
                <select class="filter-select" x-model.number="month" @change="render()">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $i => $nm)
                        <option value="{{ $i + 1 }}">{{ $nm }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model.number="year" @change="render()">
                    @foreach ([2028, 2027, 2026, 2025] as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="lm34-frame">
        <div class="report-card">
            {{-- Kepala laporan persis Excel: identitas perusahaan | judul | periode, label LM - 34 di kanan atas --}}
            <div class="lm34-head">
                <div class="lm34-head-code">LM - 34</div>
                <div class="lm34-head-box">
                    <div class="lm34-head-left">
                        <div>PT PERKEBUNAN NUSANTARA IV REG V</div>
                        <div>KANTOR REGIONAL</div>
                        <div>PONTIANAK - KALIMANTAN BARAT</div>
                    </div>
                    <div class="lm34-head-title">DAFTAR PENJUALAN EKSPOR DAN LOKAL</div>
                    <div class="lm34-head-right">s.d. bulan : <span x-text="bulanNama(month) + ' ' + year"></span></div>
                </div>
            </div>
            <div id="lm34-table" class="lm-report-table"></div>
        </div>
    </div>
</div>

<style>
    .lm34-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .lm34-page .filter-bar { top: 0; }
    .lm34-frame .lm-report-table { border-top: 0; }

    /* Kop menyatu dengan tabel: tanpa padding samping/bawah, kotak menempel header kolom */
    .lm34-head { padding: 6px 0 0; background: #fff; }
    .lm34-head-code { text-align: right; font-weight: 700; font-size: .85rem; color: #222; padding: 0 8px 4px; }
    .lm34-head-box { display: grid; grid-template-columns: minmax(230px, 24%) 1fr minmax(150px, 15%); border: 1px solid #333; border-left: 0; border-right: 0; }
    .lm34-head-box > div { display: flex; flex-direction: column; justify-content: center; padding: 10px 12px; font-weight: 700; color: #222; }
    .lm34-head-left { border-right: 1px solid #333; font-size: .8rem; gap: 4px; }
    .lm34-head-title { text-align: center; font-size: 1rem; letter-spacing: .02em; }
    .lm34-head-right { border-left: 1px solid #333; text-align: center; font-size: .8rem; }
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
                    // Label seksi/judul digarisbawahi meniru Excel (L o k a l, T B S, A./B./C.).
                    let bg = null, fw = null, italic = false, under = false;
                    if (d._type === 'section') { bg = '#d7e9df'; fw = '700'; italic = true; under = true; }
                    else if (d._type === 'total') { bg = '#dcebe2'; fw = '700'; }
                    else if (d._type === 'subtotal') { bg = '#eef5f1'; fw = '700'; }
                    else if (d._type === 'header') { fw = '700'; italic = true; under = true; }
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
                        if (under) ce.style.textDecoration = 'underline';
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
