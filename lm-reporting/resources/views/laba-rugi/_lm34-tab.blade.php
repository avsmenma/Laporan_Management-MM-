{{-- Tab LM 34 (Daftar Penjualan Ekspor dan Lokal) pada halaman Penjualan.
     Sertakan di DALAM report-card halaman (tepat di atas div tabel), lalu spread
     window.lm34Mixin() ke komponen Alpine halaman: return { ...lm34Mixin(), ... }.
     Halaman memanggil renderLm34() bila tab aktif = 'lm34'.
     Data by tarikan (belum ada sumber) → seluruh nilai '-' dulu. --}}
<div class="lm34-head" x-show="activeTab === 'lm34'" x-cloak>
    {{-- Kepala laporan persis Excel: identitas perusahaan | judul | periode, label LM - 34 di kanan atas --}}
    <div class="lm34-head-code">LM - 34</div>
    <div class="lm34-head-box">
        <div class="lm34-head-left">
            <div>PT PERKEBUNAN NUSANTARA IV REG V</div>
            <div>KANTOR REGIONAL</div>
            <div>PONTIANAK - KALIMANTAN BARAT</div>
        </div>
        <div class="lm34-head-title">DAFTAR PENJUALAN EKSPOR DAN LOKAL</div>
        <div class="lm34-head-right">s.d. bulan : <span x-text="kopPeriode()"></span></div>
    </div>
</div>

<style>
    /* Kop menyatu dengan tabel: tanpa padding samping/bawah, kotak menempel header kolom */
    .lm34-head { padding: 6px 0 0; background: #fff; }
    .lm34-head-code { text-align: right; font-weight: 700; font-size: .85rem; color: #222; padding: 0 8px 4px; }
    .lm34-head-box { display: grid; grid-template-columns: minmax(230px, 24%) 1fr minmax(150px, 15%); border: 1px solid #333; border-left: 0; border-right: 0; }
    .lm34-head-box > div { display: flex; flex-direction: column; justify-content: center; padding: 10px 12px; font-weight: 700; color: #222; }
    .lm34-head-left { border-right: 1px solid #333; font-size: .8rem; gap: 4px; }
    .lm34-head-title { text-align: center; font-size: 1rem; letter-spacing: .02em; }
    .lm34-head-right { border-left: 1px solid #333; text-align: center; font-size: .8rem; }
</style>

@push('scripts')
<script>
function lm34Mixin() {
    return {
        // Periode kop mengikuti filter halaman Penjualan; bila belum dipilih → '—'.
        kopPeriode() {
            return (this.year && this.month) ? (this.bulanNama(this.month) + ' ' + this.year) : '—';
        },

        // Sel angka: baris seksi/judul kosong; selain itu 0/null → '-';
        // negatif dalam kurung (pola halaman laba-rugi lain).
        lm34NumFmt(dec) {
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
        lm34Columns() {
            const qty = this.lm34NumFmt(0);
            const hrg = this.lm34NumFmt(2);
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
        lm34Rows() {
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

        // Selaraskan sekat vertikal kop dengan batas blok kolom pertama
        // (Volume Penjualan) supaya kop & tabel tampak satu grid seperti Excel.
        lm34SyncKop() {
            if (this.activeTab !== 'lm34') return;
            const grp = document.querySelector('#pjl-active .tabulator-header .tabulator-col.tabulator-col-group');
            const box = document.querySelector('.lm34-head-box');
            if (grp && box && grp.offsetWidth > 0) {
                box.style.gridTemplateColumns = grp.offsetWidth + 'px 1fr minmax(150px, 15%)';
            }
        },

        // Render tabel LM 34 ke wadah tabel bersama halaman Penjualan.
        renderLm34() {
            this.table = new window.Tabulator('#pjl-active', {
                data: this.lm34Rows(), columns: this.lm34Columns(),
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                maxHeight: 'calc(100vh - 300px)',
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
            this.table.on('tableBuilt', () => this.lm34SyncKop());
        },
    };
}
</script>
@endpush
