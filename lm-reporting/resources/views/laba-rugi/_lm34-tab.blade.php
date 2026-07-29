{{-- Tab LM 34 (Daftar Penjualan Ekspor dan Lokal) pada halaman Penjualan.
     Sertakan di DALAM report-card halaman (tepat di atas div tabel), lalu spread
     window.lm34Mixin() ke komponen Alpine halaman: return { ...lm34Mixin(), ... }.
     Halaman memanggil renderLm34() bila tab aktif = 'lm34'.
     Sumber angka: endpoint /report-data/laba-rugi/lm34 (tabel penjualan_produk,
     agregasi sama dengan tab PLANT). --}}
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

{{-- Material di luar peta baris LM 34: dilaporkan, jangan sampai hilang diam-diam --}}
<div class="lm34-warn" x-show="activeTab === 'lm34' && lm34Unmapped.length" x-cloak>
    ⚠️ Material berikut belum punya baris di template LM 34 sehingga tidak ikut dijumlah:
    <span x-text="lm34Unmapped.map(u => u.material).join(', ')"></span>.
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
    .lm34-warn { background: #fff8e1; border: 1px solid #f0d38a; color: #7a5a00; font-size: .8rem; padding: 8px 12px; }
</style>

@push('scripts')
<script>
function lm34Mixin() {
    return {
        lm34Data: [],
        lm34Unmapped: [],
        lm34Loaded: '', // penanda periode data yang sudah dimuat (year-month)

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
        // Kolom Realisasi (volume & nilai) bisa dirinci → klik sel membuka sumber GL.
        lm34Columns() {
            const qty = this.lm34NumFmt(0);
            const hrg = this.lm34NumFmt(2);
            const col = (title, field, fmt, minWidth) => ({ title, field, hozAlign: 'right', headerHozAlign: 'center', formatter: fmt, minWidth });
            const drill = (title, field, fmt, minWidth, blok, measure) => ({
                ...col(title, field, fmt, minWidth),
                cssClass: 'lm-cell-drill',
                cellClick: (e, cell) => this.lm34Drill(cell, blok, measure),
            });
            return [
                { title: 'Volume Penjualan', headerHozAlign: 'center', columns: [
                    { title: 'Realisasi', headerHozAlign: 'center', columns: [drill('Bulan ini', 'vol_r_bln', qty, 100, 'bln', 'qty'), drill('s.d Bulan ini', 'vol_r_sd', qty, 100, 'sd', 'qty')] },
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
                    { title: 'Realisasi', headerHozAlign: 'center', columns: [drill('Bulan ini', 'nil_r_bln', qty, 130, 'bln', 'nilai'), drill('s.d Bulan ini', 'nil_r_sd', qty, 130, 'sd', 'nilai')] },
                    { title: 'Anggaran', headerHozAlign: 'center', columns: [col('Bulan ini', 'nil_a_bln', qty, 130), col('s.d Bulan ini', 'nil_a_sd', qty, 130)] },
                ] },
                { title: 'Selisih Lebih(Kurang)', headerHozAlign: 'center', columns: [col('sd. Bulan ini', 'selisih', qty, 140)] },
            ];
        },

        // Klik sel Realisasi → popup sumber data (tahap 1 pivot Produk×Plant, tahap 2 mentah).
        lm34Drill(cell, blok, measure) {
            const d = cell.getRow().getData();
            if (!d._drill) return;
            const v = Number(cell.getValue() ?? 0);
            if (!v) return;
            const blokLabel = blok === 'bln' ? 'Bulan ini' : 's.d Bulan ini';
            this.openDrill({
                title: 'LM 34 — ' + d.u,
                columnLabel: `${blokLabel} — ${measure === 'qty' ? 'Volume (Kg)' : 'Jumlah Nilai Penjualan'} · ${this.bulanNama(this.month)} ${this.year}`,
                unit: measure === 'qty' ? '' : 'Rp',
                value: v,
                params: { page: 'lm34', key: d._k, blok, measure, year: this.year, month: this.month },
            });
        },

        // Tarik baris + nilai dari backend (struktur baris juga dari server agar
        // template baris cuma hidup di satu tempat).
        async lm34Load() {
            const q = '?year=' + encodeURIComponent(this.year) + '&month=' + encodeURIComponent(this.month);
            const resp = await fetch('/report-data/laba-rugi/lm34' + q);
            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                throw new Error(err.message || ('HTTP ' + resp.status));
            }
            const data = await resp.json();
            this.lm34Data = data.rows || [];
            this.lm34Unmapped = data.unmapped || [];
            this.lm34Loaded = this.year + '-' + this.month;
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
        async renderLm34() {
            try {
                if (this.lm34Loaded !== (this.year + '-' + this.month)) {
                    await this.lm34Load();
                }
            } catch (e) {
                this.errorMsg = e.message;
                if (window.lmToast) window.lmToast(e.message, 'err');
                return;
            }
            if (this.activeTab !== 'lm34') return; // user keburu pindah tab

            this.table = new window.Tabulator('#pjl-active', {
                data: this.lm34Data, columns: this.lm34Columns(),
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
