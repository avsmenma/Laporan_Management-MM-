@extends('layouts.app')

@section('title', 'LM-27A')

@section('content')
<div x-data="lm27aApp()" x-init="init()" class="lm27a-page">
    <div class="filter-bar">
        <div class="filter-grid">
            {{-- Opsi dirender server (bukan x-for) supaya nilai awal Juni 2026 langsung terpilih --}}
            <div class="filter-group">
                <label class="filter-label">Bulan</label>
                <select class="filter-select" x-model.number="month" @change="load()">
                    @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $i => $nm)
                        <option value="{{ $i + 1 }}">{{ $nm }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Tahun</label>
                <select class="filter-select" x-model.number="year" @change="load()">
                    @foreach ([2028, 2027, 2026, 2025] as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="lm27a-frame">
        <div class="report-card">
            {{-- Kepala laporan persis Excel: identitas | judul | Periode (label kode LM dihapus agar kop mentok atas) --}}
            <div class="lm27a-head">
                <div class="lm27a-head-box">
                    <div class="lm27a-head-left">
                        <div>PTP NUSANTARA IV - REG 5</div>
                        <div>KANTOR REGIONAL OFFICE</div>
                        <div class="ul">PONTIANAK - KALIMANTAN BARAT</div>
                    </div>
                    <div class="lm27a-head-title">PERHITUNGAN LABA ( RUGI )</div>
                    <div class="lm27a-head-right">
                        <div>Periode</div>
                        <div x-text="periodeText()"></div>
                    </div>
                </div>
            </div>
            <div id="lm27a-table" class="lm-report-table"></div>
        </div>
    </div>
</div>

<style>
    .lm27a-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .lm27a-page .filter-bar { top: 0; }
    .lm27a-frame .lm-report-table { border-top: 0; }

    /* Kop menyatu dengan tabel: warna sama dengan header kolom (var(--g-700)) +
       garis pemisah putih tipis seperti grid header, tanpa sela ke header kolom */
    .lm27a-head { padding: 0; background: #fff; }
    .lm27a-head-box { display: grid; grid-template-columns: minmax(300px, 38%) 1fr minmax(200px, 21%); background: var(--g-700); border-bottom: 1.5px solid rgba(255, 255, 255, .45); }
    .lm27a-head-box > div { display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 8px 10px; font-weight: 700; color: #fff; }
    .lm27a-head-left { border-right: 1.5px solid rgba(255, 255, 255, .45); font-size: .8rem; gap: 2px; }
    .lm27a-head-left .ul { text-decoration: underline; }
    .lm27a-head-title { font-size: 1rem; letter-spacing: .02em; }
    .lm27a-head-right { border-left: 1.5px solid rgba(255, 255, 255, .45); font-size: .8rem; gap: 4px; }

    /* Tabel rapat ke kop (tema semantic-ui memberi margin pada .tabulator) dan
       label kolom tanpa sub (U r a i a n, Jumlah, %) dipusatkan vertikal supaya
       tidak ada zona hijau kosong di header. */
    .lm27a-frame #lm27a-table { margin: 0 !important; }
    .lm27a-frame .tabulator-header .tabulator-col:not(.tabulator-col-group) .tabulator-col-content { height: 100% !important; display: flex; align-items: center; justify-content: center; }

    /* Semua baris putih seperti Excel (matikan striping baris genap) */
    .lm27a-frame .tabulator .tabulator-row.tabulator-row-even { background: #fff; }

    /* Kolom uraian: indentasi meniru posisi kolom A/B/D Excel (±57px & ±171px) */
    .lm27a-frame .tabulator-cell[tabulator-field="u"] { padding-left: 0; padding-right: 0; }
    .lm27a-u { white-space: nowrap; }
    .lm27a-u.lvl1 { padding-left: 57px; }
    .lm27a-u.lvl2 { padding-left: 171px; }
    .lm27a-u.tot { text-align: center; font-weight: 700; }
    .lm27a-u.bold { font-weight: 700; }
    .lm27a-u.ul { text-decoration: underline; }
</style>
@endsection

@push('scripts')
<script>
function lm27aApp() {
    return {
        // Nilai tiap baris ditarik dari /report-data/laba-rugi/lm27a, semuanya
        // memakai jalur hitung halaman sumbernya: Lokal (LM 34), Persediaan
        // Awal/Akhir (halaman Persediaan), Biaya Produksi & Penyusutan (LM
        // Eksploitasi), Biaya Penjualan (Beban Penjualan), Administrasi Kandir
        // (Beban Administrasi). Baris lain belum ada sumber → tetap '-'.
        // Default template Juni 2026; saat init diganti periode data TERBARU.
        month: 6,
        year: 2026,
        values: {},
        table: null,

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },
        // "1 Januari s/d 30 Juni 2026" — tanggal akhir mengikuti bulan terpilih.
        periodeText() {
            const akhir = new Date(this.year, this.month, 0).getDate();
            return '1 Januari s/d ' + akhir + ' ' + this.bulanNama(this.month) + ' ' + this.year;
        },

        // Sel angka: baris judul tanpa nilai → kosong; 0/null → '-';
        // negatif dalam KURUNG gaya akuntansi (permintaan user) — mis. baris
        // biaya blok Harga Pokok Penjualan yang memang bertanda minus.
        // $minDec dipakai kolom % agar desimal SELALU tampil (mis. 100,00).
        numFmt(maxDec, minDec = 0) {
            return (cell) => {
                const d = cell.getRow().getData();
                if (d._type === 'group') return '';
                const v = cell.getValue();
                if (v == null || Number(v) === 0) return '-';
                const teks = Math.abs(Number(v)).toLocaleString('id-ID', { minimumFractionDigits: minDec, maximumFractionDigits: maxDec });
                return Number(v) < 0 ? '(' + teks + ')' : teks;
            };
        },

        // Kolom uraian: indentasi + tebal/garis bawah/rata tengah sesuai tipe baris Excel.
        uraianFmt(cell) {
            const d = cell.getRow().getData();
            const cls = ['lm27a-u'];
            if (d._type === 'total') { cls.push('tot'); }
            else if (d._type === 'detail' || d._type === 'dhead') { cls.push('lvl2'); }
            else { cls.push('lvl1'); }
            if (d._type === 'group' || d._type === 'gvalue' || d._type === 'dhead') { cls.push('bold'); }
            if (d._type === 'group' || d._type === 'dhead') { cls.push('ul'); }
            return '<div class="' + cls.join(' ') + '">' + d.u + '</div>';
        },

        // ---- Kolom persis template LM-27A.xlsx: U r a i a n | Budidaya (Kelapa Sawit % Karet %) | Jumlah | % ----
        columns() {
            const rp = this.numFmt(0);
            const pc = this.numFmt(2, 2); // persen: selalu 2 desimal (permintaan user)
            // minWidth + widthGrow: proporsi lebar kolom mengikuti template (kolom %
            // sempit). minWidth dinaikkan agar di layar sempit angka penuh
            // (1.104.788.325.803 & 100,00) tidak terpotong elipsis.
            const col = (title, field, fmt, minWidth, grow) => ({ title, field, minWidth, widthGrow: grow, hozAlign: 'right', headerHozAlign: 'center', headerVertAlign: 'middle', formatter: fmt });
            return [
                { title: 'U r a i a n', field: 'u', minWidth: 300, widthGrow: 6, headerHozAlign: 'center', headerVertAlign: 'middle', formatter: (c) => this.uraianFmt(c) },
                { title: 'Budidaya', headerHozAlign: 'center', columns: [
                    col('Kelapa Sawit', 'ks', rp, 155, 2),
                    col('%', 'ks_p', pc, 80, 0.8),
                    col('Karet', 'kr', rp, 145, 1.9),
                    col('%', 'kr_p', pc, 80, 0.8),
                ] },
                col('Jumlah', 'jm', rp, 155, 2.1),
                col('%', 'jm_p', pc, 80, 0.8),
            ];
        },

        // ---- Nilai per kunci baris; baris tanpa sumber TIDAK diisi (tetap '-'),
        // tetapi tetap dihitung 0 pada baris jumlah (persis template Excel) ----
        nilaiBaris() {
            const out = {};
            const lokal = this.values.lokal;
            if (lokal) {
                const ks = Number(lokal.ks || 0);
                const kr = Number(lokal.kr || 0);
                out.lokal = { ks, kr };
                // Jumlah Penjualan = Ekspor + Lokal + Perubahan Nilai Wajar Aset
                // Biologis; dua baris itu belum ada sumber → dihitung 0.
                out.jml_penjualan = { ks, kr };
            }
            // Persediaan Awal = baris "Jumlah Persediaan" kolom PERSEDIAAN AWAL
            // TAHUN (Rp) di /laba-rugi/persediaan (tab KELAPA SAWIT & KARET).
            // Baris total "Harga Pokok Penjualan" SENGAJA belum diisi: komponen
            // lain (Biaya Produksi, Penyusutan, Order Produksi, Persediaan Akhir)
            // belum ada sumbernya, jadi totalnya akan menyesatkan.
            // Blok Harga Pokok Penjualan. Tiga baris biaya sudah dikirim NEGATIF
            // oleh server; "Persediaan Akhir" positif karena mengurangi harga pokok.
            //   Persediaan Awal   ← halaman Persediaan (PERSEDIAAN AWAL TAHUN Rp)
            //   Biaya Produksi    ← LM Eksploitasi "Jumlah Biaya Produksi" − penyusutan
            //   Penyusutan        ← LM Eksploitasi "Jumlah Beban Penyusutan"
            //   Persediaan Akhir  ← halaman Persediaan (NILAI PERSEDIAAN AKHIR Rp)
            const ambil = (k) => {
                const v = this.values[k];
                if (!v) return;
                out[k] = { ks: Number(v.ks || 0), kr: Number(v.kr || 0) };
            };
            // Blok Biaya Usaha & Biaya Lain - Lain (juga bertanda minus dari server):
            //   Biaya Penjualan       ← halaman Beban Penjualan ("Jumlah" per seksi)
            //   - Administrasi Kandir ← Beban Administrasi tab ADMI KS/KR
            //     baris "Jumlah beban administrasi Include Penyusutan"
            //   Biaya Lain - Lain     ← Beban Ops Lainnya tab KELAPA SAWIT/KARET
            //     baris "Total"
            [
                'persediaan_awal', 'biaya_produksi', 'penyusutan', 'persediaan_akhir',
                'biaya_penjualan', 'administrasi_kandir', 'biaya_lain_lain',
            ].forEach(ambil);

            // Harga Pokok Penjualan = jumlah SELURUH baris blok itu (Persediaan Awal
            // s/d Persediaan Akhir; Order Produksi belum ada sumber → 0), lalu
            // Laba (Rugi) Kotor = Jumlah Penjualan + Harga Pokok Penjualan — dua
            // rumus dari user, jalan karena baris biaya sudah bertanda minus.
            //
            // Hanya untuk kolom yang komponennya lengkap (values.hpp_kolom): kolom
            // Karet masih kekurangan Biaya Produksi & Penyusutan, jadi totalnya akan
            // menyesatkan bila tetap dihitung.
            const siap = this.values.hpp_kolom || [];
            if (siap.length) {
                const hppRows = ['persediaan_awal', 'biaya_produksi', 'penyusutan', 'order_produksi', 'persediaan_akhir'];
                const hpp = {};
                const kotor = {};
                ['ks', 'kr'].forEach((c) => {
                    if (!siap.includes(c)) { hpp[c] = null; kotor[c] = null; return; }
                    hpp[c] = hppRows.reduce((t, k) => t + Number((out[k] || {})[c] || 0), 0);
                    kotor[c] = Number((out.jml_penjualan || {})[c] || 0) + hpp[c];
                });
                out.hpp = hpp;
                out.laba_kotor = kotor;
            }

            // Blok Biaya Usaha (tiga rumus dari user; semua baris biayanya sudah
            // bertanda minus, jadi cukup dijumlahkan):
            //   Biaya Administrasi / Umum = Administrasi Kandir + Administrasi Kebun
            //   Jumlah Biaya Usaha        = Biaya Penjualan + Biaya Administrasi / Umum
            //   Laba ( Rugi ) Usaha       = Laba ( Rugi ) Kotor + Jumlah Biaya Usaha
            //   Laba (Rugi) Usaha Setelah Biaya Bunga = Laba ( Rugi ) Usaha + Biaya Bunga
            //   Jumlah Pendapatan / Biaya Lain - Lain = Pendapatan Lain - Lain + Biaya Lain - Lain
            //   Laba (Rugi) sebelum Pajak = Laba (Rugi) Usaha Setelah Biaya Bunga
            //                               + Jumlah Pendapatan / Biaya Lain - Lain
            // "- Administrasi Kebun", "Biaya Bunga" & "Pendapatan Lain - Lain" belum
            // ada sumbernya → dihitung 0. Baris laba butuh Laba Kotor, jadi kolom
            // tanpa Laba Kotor tetap '-'.
            const adm = {};
            const jbu = {};
            const usaha = {};
            const usahaBunga = {};
            const lain = {};
            const sebelumPajak = {};
            ['ks', 'kr'].forEach((c) => {
                const kol = (k) => Number((out[k] || {})[c] || 0);
                adm[c] = kol('administrasi_kandir') + kol('administrasi_kebun');
                jbu[c] = kol('biaya_penjualan') + adm[c];
                const kotor = (out.laba_kotor || {})[c];
                usaha[c] = (kotor == null) ? null : kotor + jbu[c];
                usahaBunga[c] = (usaha[c] == null) ? null : usaha[c] + kol('biaya_bunga');
                lain[c] = kol('pendapatan_lain_lain') + kol('biaya_lain_lain');
                sebelumPajak[c] = (usahaBunga[c] == null) ? null : usahaBunga[c] + lain[c];
            });
            out.biaya_adm_umum = adm;
            out.jumlah_biaya_usaha = jbu;
            out.laba_usaha = usaha;
            out.laba_usaha_bunga = usahaBunga;
            out.jumlah_lain_lain = lain;
            out.laba_sebelum_pajak = sebelumPajak;
            return out;
        },

        // ---- Baris persis template (label verbatim; baris kosong pemisah Excel
        // TIDAK dirender — dihapus atas permintaan user) ----
        rows() {
            const g = (u) => ({ u, _type: 'group' });   // judul blok: tebal + garis bawah
            const gv = (u) => ({ u, _type: 'gvalue' }); // judul blok bernilai: tebal saja
            const sb = (u) => ({ u, _type: 'sub' });    // baris setingkat judul blok, biasa
            const d = (u, k) => ({ u, _type: 'detail', _k: k || null });  // rincian
            const dh = (u, k) => ({ u, _type: 'dhead', _k: k || null }); // rincian bertebal + garis bawah
            const t = (u, k, fill) => ({ u, _type: 'total', _k: k || null, _fill: fill || null });
            const defs = [
                g('Penjualan'),
                d('Ekspor', 'ekspor'),
                d('Lokal', 'lokal'),
                d('Perubahan Nilai Wajar Aset Biologis', 'pnw'),
                t('Jumlah Penjualan', 'jml_penjualan'),
                g('Harga Pokok Penjualan'),
                d('Persediaan Awal', 'persediaan_awal'),
                d('Biaya Produksi', 'biaya_produksi'),
                d('Penyusutan', 'penyusutan'),
                d('Order Produksi'),
                d('Persediaan Akhir', 'persediaan_akhir'),
                t('Harga Pokok Penjualan', 'hpp'),
                t('Laba ( Rugi ) Kotor', 'laba_kotor'),
                g('Biaya Usaha'),
                d('Biaya Penjualan', 'biaya_penjualan'),
                dh('Biaya Administrasi / Umum', 'biaya_adm_umum'),
                d('- Administrasi Kandir', 'administrasi_kandir'),
                d('- Administrasi Kebun', 'administrasi_kebun'),
                t('Jumlah Biaya Usaha', 'jumlah_biaya_usaha'),
                t('Laba ( Rugi ) Usaha', 'laba_usaha'),
                d('Biaya Bunga', 'biaya_bunga'),
                t('Laba (Rugi) Usaha Setelah Biaya Bunga', 'laba_usaha_bunga'),
                gv('Pendapatan / Biaya Lain - Lain'),
                d('Pendapatan Lain - Lain', 'pendapatan_lain_lain'),
                d('Biaya Lain - Lain', 'biaya_lain_lain'),
                t('Jumlah Pendapatan / Biaya Lain - Lain', 'jumlah_lain_lain'),
                t('Laba (Rugi) sebelum Pajak', 'laba_sebelum_pajak', 'green'),
                sb('Pajak Perseroan'),
                t('Laba (Rugi) setelah Pajak'),
                sb('Pajak Tangguhan'),
                t('Laba (Rugi) setelah Pajak Tangguhan', null, 'gold'),
                g('Pendapatan Komprehensif Lain :'),
                sb('Selisih Nilai Wajar Asset Keuangan'),
                sb('Keuntungan ( Kerugian ) Aktuaria'),
                sb('Penyesuaian Pajak tangguhan atas OCI tahun 2021'),
                t('Jumlah Pendapatan Komprehensif Lain', null, 'grey'),
                t('Laba (Rugi) Komprehensif', null, 'grey'),
            ];

            // Isi nilai + kolom Jumlah (= Kelapa Sawit + Karet) dan kolom %.
            // Dasar hitung % (permintaan user): kolom Kelapa Sawit & Karet =
            // nilai ÷ Jumlah BARIS ITU (porsi tiap budidaya, total 100%);
            // kolom % paling kanan = Jumlah baris ÷ "Jumlah Penjualan" (porsi
            // baris terhadap total penjualan). Pola aman: penyebut 0 → '-'.
            const nilai = this.nilaiBaris();
            const basis = nilai.jml_penjualan || null;
            const pct = (x, b) => (b ? (x / b) * 100 : null);
            return defs.map((r) => {
                const v = r._k ? nilai[r._k] : null;
                if (!v) return r;
                const jm = v.ks + v.kr;
                return {
                    ...r,
                    ks: v.ks, kr: v.kr, jm,
                    ks_p: pct(v.ks, jm),
                    kr_p: pct(v.kr, jm),
                    jm_p: pct(jm, basis ? basis.ks + basis.kr : 0),
                };
            });
        },

        // Selaraskan sekat kop dengan batas kolom tabel (Uraian | Budidaya | Jumlah+%)
        // supaya kop & tabel tampak satu grid seperti Excel.
        syncKop() {
            if (!this.table) return;
            const box = document.querySelector('.lm27a-head-box');
            if (!box) return;
            const w = (f) => { const c = this.table.getColumn(f); return c ? c.getElement().offsetWidth : 0; };
            const kiri = w('u'), kanan = w('jm') + w('jm_p');
            if (kiri > 0 && kanan > 0) {
                box.style.gridTemplateColumns = kiri + 'px 1fr ' + kanan + 'px';
            }
        },

        render() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            this.table = new window.Tabulator('#lm27a-table', {
                data: this.rows(), columns: this.columns(),
                columnDefaults: { headerSort: false },
                layout: 'fitColumns',
                maxHeight: 'calc(100vh - 260px)',
                rowFormatter: (row) => {
                    const d = row.getData();
                    // Warna latar baris kunci sesuai template: hijau muda (sebelum pajak),
                    // kuning (setelah pajak tangguhan), abu (komprehensif). Selain itu,
                    // judul blok & baris total diberi band hijau muda (permintaan user)
                    // supaya struktur tabel lebih terbaca.
                    const fills = { green: '#eaf1dd', gold: '#ffd966', grey: '#efefef' };
                    let bg = d._fill ? fills[d._fill] : null;
                    if (!bg) {
                        if (d._type === 'group' || d._type === 'gvalue') bg = '#d7e9df';
                        else if (d._type === 'total') bg = '#dcebe2';
                    }
                    const bold = d._type === 'total';
                    if (!bg && !bold) return;
                    const el = row.getElement();
                    if (bg) el.style.background = bg;
                    row.getCells().forEach((c) => {
                        const ce = c.getElement();
                        if (bg) ce.style.background = bg;
                        if (bold) ce.style.fontWeight = '700';
                    });
                },
            });
            this.table.on('tableBuilt', () => this.syncKop());
        },

        // Ambil nilai periode terpilih. $adopt (saat init) = tanpa parameter periode
        // → API mengembalikan periode data TERBARU dan filter mengikutinya.
        async load(adopt = false) {
            let url = '/report-data/laba-rugi/lm27a';
            if (!adopt) {
                url += '?year=' + encodeURIComponent(this.year) + '&month=' + encodeURIComponent(this.month);
            }
            try {
                const resp = await fetch(url);
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                this.values = data.values || {};
                if (adopt && data.year && data.month) {
                    this.year = Number(data.year);
                    this.month = Number(data.month);
                }
            } catch (e) {
                this.values = {};
                if (window.lmToast) window.lmToast('Gagal memuat LM-27A: ' + e.message, 'err');
            }
            this.render();
        },

        init() {
            this.$nextTick(() => this.load(true));
            window.addEventListener('resize', () => this.syncKop());
        },
    };
}
</script>
@endpush
