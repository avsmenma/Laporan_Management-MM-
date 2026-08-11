@extends('layouts.app')

@section('title', 'Persediaan')

@section('content')
<div x-data="persediaanApp()" x-init="init()" class="psd-page">
    <div class="filter-bar">
        <div class="filter-grid">
            {{-- Opsi dirender server (bukan x-for) supaya nilai awal Juli 2026 langsung terpilih --}}
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

    <div class="psd-frame">
        <div class="tabs psd-tabs">
            <template x-for="t in tabs" :key="t.key">
                <span class="tab" :class="{ active: tab === t.key }" @click="setTab(t.key)" x-text="t.label"></span>
            </template>
        </div>
        <div class="report-card">
            {{-- Kop persis template: blok identitas hijau (selebar kolom KOMODITI) + judul hijau --}}
            <div class="psd-head">
                <div class="psd-head-box">
                    <div class="psd-head-left">
                        <div>PT PERKEBUNAN NUSANTARA IV</div>
                        <div>REGIONAL 5</div>
                    </div>
                    <div class="psd-head-title" x-text="judul()"></div>
                </div>
            </div>
            <div id="psd-table" class="lm-report-table"></div>
        </div>
    </div>
</div>

<style>
    .psd-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .psd-page .filter-bar { top: 0; }

    .psd-frame .psd-tabs { padding-left: 4px; flex-wrap: wrap; }
    .psd-frame .psd-tabs .tab { cursor: pointer; height: 38px; letter-spacing: .01em; }
    .psd-frame .psd-tabs .tab:not(.active) { background: #eaf0ec; border-color: var(--line); }
    .psd-frame .psd-tabs .tab:not(.active):hover { background: #dfe8e2; }
    .psd-frame .psd-tabs .tab.active { font-weight: 700; }
    .psd-frame .report-card { border-top-left-radius: 0; border-top-right-radius: 0; }
    .psd-frame .lm-report-table { border-top: 0; }

    /* Kop menyatu dengan tabel: warna sama dengan pita judul grup di bawahnya
       (var(--g-700)) + garis pemisah putih tipis seperti grid header, atas
       permintaan user. Tanpa padding & margin apa pun antara kop dan header kolom
       (tema semantic-ui memberi margin pada .tabulator — dinolkan agar tidak ada
       sela putih). */
    .psd-head { padding: 0; background: var(--g-700); }
    .psd-frame #psd-table { margin: 0 !important; }
    .psd-head-box { display: grid; grid-template-columns: 250px 1fr; border-bottom: 1.5px solid rgba(255, 255, 255, .45); }
    .psd-head-left { background: var(--g-700); color: #fff; font-weight: 700; font-size: .8rem; display: flex; flex-direction: column; justify-content: center; gap: 2px; padding: 6px 10px; border-right: 1.5px solid rgba(255, 255, 255, .45); }
    .psd-head-title { background: var(--g-700); color: #fff; font-weight: 700; font-size: 1.05rem; letter-spacing: .02em; display: flex; align-items: center; justify-content: center; text-align: center; padding: 6px 12px; }

    /* Semua baris putih seperti Excel (matikan striping baris genap) */
    .psd-frame .tabulator .tabulator-row.tabulator-row-even { background: #fff; }

    /* Judul kolom boleh membungkus (PENERIMAAN TRANSFER, PENGOLAHAN SENDIRI, dst.)
       supaya lebar grup tetap = jumlah lebar anaknya, tanpa celah seperti Excel */
    .psd-frame .tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title { white-space: normal; }

    /* Hilangkan zona hijau tua kosong di header:
       - zona judul semua grup diseragamkan setinggi 3 baris (meniru merge baris 4-6
         Excel) sehingga garis sekat judul/label rata satu garis;
       - label kolom daun ((Kg), GR, Plant, dst.) dipusatkan vertikal karena Tabulator
         meregangkan sel daun sampai dasar header (teks menempel atas = tampak kosong). */
    .psd-frame .tabulator-header .tabulator-col-group > .tabulator-col-content { min-height: 65px; display: flex; align-items: center; justify-content: center; }
    .psd-frame .tabulator-header .tabulator-col:not(.tabulator-col-group) .tabulator-col-content { height: 100% !important; display: flex; align-items: center; justify-content: center; }
</style>
@endsection

@push('scripts')
<script>
function persediaanApp() {
    return {
        // Kolom PRODUKSI (Kg) & PENGELUARAN→PENJUALAN (Kg) tab KELAPA SAWIT diisi
        // dari endpoint /report-data/laba-rugi/persediaan (produksi_pks &
        // penjualan_produk); kolom PERSEDIAAN AWAL TAHUN diisi manual dari
        // TEMPLATE PERSEDIAAN-1.xlsx (konstanta awalTahun); kolom lain masih '-'
        // menunggu sumber. Periode awal diadopsi dari data terbaru saat init.
        month: 7,
        year: 2026,
        tab: 'sawit',
        table: null,
        produksi: null, // {ms: {plant: kg}, is: {...}, tbs: {...}} atau null bila periode tanpa data
        penjualan: null, // {ms: {plant: kg}, is: {...}} — TBS tak dijual dari sini
        nilai: {},       // {PRODUK: {"PLANT|UNIT": rp}} hasil impor ZSTOCK
        tabs: [
            { key: 'sawit', label: 'KELAPA SAWIT' },
            { key: 'karet', label: 'KARET' },
        ],

        // ---- PERSEDIAAN AWAL TAHUN: nilai manual dari TEMPLATE PERSEDIAAN-1.xlsx ----
        // Saldo awal tahun bersifat tetap (bukan tarikan data); hanya baris bernilai
        // yang dicatat, sisanya 0 → tampil '-'. Bentuk: produk → unit → [Kg, Rp];
        // (Rp/Kg) tidak disimpan karena diturunkan = Rp / Kg (persis formula sheet).
        // penyesuaianRp = baris "Penyesuaian atas nilai persediaan akhir" kolom (Rp).
        awalTahun: {
            sawit: {
                products: {
                    '- Minyak Sawit': {
                        'Tanah Merah': [712882, 12353944807],
                        'PKS Gunung Meliau': [2030505, 23271461007],
                        'PKS Rimba Belian': [737387, 7346163149],
                        'PKS Ngabang': [1062012, 15599707349],
                        'PKS Parindu': [1913260, 24541441053],
                        'PKS Kembayan': [1118908, 18298558807],
                        'PKS Pamukan': [82821, 1325320363],
                        'PKS Pelaihari': [1937181, 20927291873],
                        'PKS Long Pinang': [2829536, 49034667128],
                    },
                    '- Inti Sawit': {
                        'PKS Gunung Meliau': [1031360, 5393442463],
                        'PKS Rimba Belian': [697934, 4079867207],
                        'PKS Ngabang': [320539, 2085112545],
                        'PKS Parindu': [338739, 2521554971],
                        'PKS Kembayan': [159524, 555201093],
                        'PKS Pamukan': [36864, 164370461],
                        'PKS Pelaihari': [149876, 524842609],
                        'PKS Long Pinang': [489116, 1849985403],
                    },
                },
                // Dikoreksi user 2026-08-10 (dulu −10.565.230.360) → Jumlah Persediaan
                // jadi 194.983.848.689, sama dengan "Persediaan Awal" Kelapa Sawit LM-27A.
                penyesuaianRp: 5110916401,
            },
            karet: {
                products: {
                    '- LUMP': {
                        'Kebun Sintang': [10080, 2055906444],
                        'Kebun Kumai': [10897, 479567444],
                    },
                },
                penyesuaianRp: -2005715276,
            },
        },

        // ---- Struktur baris persis sheet KELAPA SAWIT & KARET (TEMPLATE PERSEDIAAN.xlsx) ----
        cfg() {
            if (this.tab === 'karet') {
                return {
                    judul: 'PERSEDIAAN AKHIR HASIL PRODUKSI KARET',
                    // key = kunci sumber produksi; karet belum ada sumber → null semua.
                    products: [
                        { label: '- SIR 20 SWJP', key: null },
                        { label: '- SCRAP', key: null },
                        { label: '- BLANKET', key: null },
                        { label: '- LATEKS', key: null },
                        { label: '- LUMP', key: null },
                        { label: '- RSS I', key: null },
                        { label: '- RSS II', key: null },
                        { label: '- RSS III', key: null },
                    ],
                    // Baris pemisah antarproduk di sheet memuat strip pada kolom
                    // "Persediaan per ..." — kecuali setelah produk terakhir.
                    spacerDash: [true, true, true, true, true, true, true, false],
                    units: [
                        ['5F20', 'PKR Tambarangan'],
                        ['5E06', 'Kebun Sintang'],
                        ['5E11', 'Kebun Danau Salak'],
                        ['5E19', 'Kebun Longkali'],
                        ['5E12', 'Kebun Kumai'],
                    ],
                };
            }
            return {
                judul: 'PERSEDIAAN AKHIR HASIL PRODUKSI KELAPA SAWIT',
                // key mengacu peta produksi dari API: ms/is/tbs (TBS = TBS Diterima);
                // peta penjualan hanya punya ms (CPO) & is (INTI SAWIT);
                // keyOlah (PENGOLAHAN SENDIRI) hanya TBS = TBS Diolah.
                products: [
                    { label: '- Minyak Sawit', key: 'ms' },
                    { label: '- Inti Sawit', key: 'is' },
                    { label: '- Tandan Buah Segar', key: 'tbs', keyOlah: 'olah' },
                ],
                spacerDash: [true, false, false],
                units: [
                    ['5R00', 'IPP Tayan'],
                    ['5R00', 'Tanah Merah'],
                    ['5F01', 'PKS Gunung Meliau'],
                    ['5F04', 'PKS Rimba Belian'],
                    ['5F07', 'PKS Ngabang'],
                    ['5F08', 'PKS Parindu'],
                    ['5F09', 'PKS Kembayan'],
                    ['5F14', 'PKS Pamukan'],
                    ['5F15', 'PKS Pelaihari'],
                    ['5F21', 'PKS Samuntai'],
                    ['5F22', 'PKS Long Pinang'],
                ],
            };
        },
        judul() {
            return this.cfg().judul;
        },

        bulanNama(m) {
            return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][Number(m)] || String(m);
        },
        // "JULI 2026" pada judul kolom persediaan/nilai akhir mengikuti filter.
        periodeLabel() {
            return this.bulanNama(this.month).toUpperCase() + ' ' + this.year;
        },

        // Sel nilai: angka format id-ID 0 desimal; 0/kosong → '-' dan negatif dalam
        // kurung (format akuntansi template); baris pemisah dibiarkan kosong.
        numFmt(cell) {
            const d = cell.getRow().getData();
            if (d._t === 'spacer') return (d._dash && cell.getField() === 'akhir_kg') ? '-' : '';
            const v = cell.getValue();
            if (v == null || Number(v) === 0) return '-';
            const teks = Math.abs(Number(v)).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            return Number(v) < 0 ? '(' + teks + ')' : teks;
        },

        // ---- Kolom persis template: grouped header 4 tingkat mengikuti merge Excel ----
        columns() {
            const num = (title, field, width) => ({ title, field, width, hozAlign: 'right', formatter: this.numFmt.bind(this) });
            const periode = this.periodeLabel();
            return [
                // Lebar Plant+Unit Kerja (280px) sekaligus menampung label
                // "Penyesuaian atas nilai persediaan akhir" pada emulasi colspan.
                { title: 'KOMODITI', columns: [
                    { title: 'Plant', field: 'plant', width: 60, frozen: true, hozAlign: 'left' },
                    { title: 'Unit Kerja', field: 'unit', width: 220, frozen: true, hozAlign: 'left' },
                ] },
                // Lebar (Rp/Kg) & (Rp) menampung nilai tebal terpanjang
                // (203.959 dan 172.698.555.536) tanpa elipsis.
                { title: 'PERSEDIAAN AWAL TAHUN', columns: [
                    num('(Kg)', 'awal_kg', 117),
                    num('(Rp/Kg)', 'awal_rpkg', 90),
                    num('(Rp)', 'awal_rp', 150),
                ] },
                { title: 'PRODUKSI', columns: [num('(Kg)', 'prod_kg', 117)] },
                { title: 'PENERIMAAN<br>TRANSFER', columns: [num('(Kg)', 'terima_kg', 117)] },
                { title: 'PENGELUARAN', columns: [
                    num('PENJUALAN<br>(Kg)', 'jual_kg', 117),
                    num('SUSUT<br>(Kg)', 'susut_kg', 117),
                    num('TRANSFER<br>(Kg)', 'trf_kg', 117),
                    num('PENGOLAHAN SENDIRI<br>(Kg)', 'olah_kg', 117),
                ] },
                { title: 'SELISIH<br>STOCK OPNAME', columns: [
                    num('GR', 'so_gr', 117),
                    num('GI', 'so_gi', 117),
                ] },
                { title: 'PERSEDIAAN<br>PER<br>' + periode, columns: [num('(Kg)', 'akhir_kg', 117)] },
                { title: 'HRG. POKOK/<br>SATUAN', columns: [num('(Rp/Kg)', 'hrg_rpkg', 96)] },
                { title: 'NILAI PERSEDIAAN<br>AKHIR<br>' + periode, columns: [num('(Rp)', 'nilai_rp', 145)] },
            ];
        },

        // Urutan baris: per produk (subtotal + rincian unit + pemisah) → Jumlah →
        // Penyesuaian → Jumlah Persediaan. Baris seksi KELAPA SAWIT/KARET dari
        // template TIDAK dirender — sudah terwakili tab bar (permintaan user).
        // Kolom PRODUKSI (Kg) diisi per plant dari peta produksi; kolom PERSEDIAAN
        // AWAL TAHUN dari konstanta awalTahun (manual). Subtotal produk & Jumlah =
        // penjumlahan rinciannya; Jumlah Persediaan (Rp) = Jumlah + Penyesuaian.
        rows() {
            // "Persediaan per <bulan> (Kg)" = (Awal Tahun + Produksi + Penerimaan
            // Transfer) − (Penjualan + Susut + Transfer + Pengolahan Sendiri +
            // Selisih Stock Opname GR & GI) — rumus dari user. Kolom yang belum
            // ada sumbernya dihitung 0; hasil 0 tetap dirender '-'.
            const akhirKg = (r) => {
                const n = (x) => Number(x || 0);
                const masuk = n(r.awal_kg) + n(r.prod_kg) + n(r.terima_kg);
                const keluar = n(r.jual_kg) + n(r.susut_kg) + n(r.trf_kg) + n(r.olah_kg) + n(r.so_gr) + n(r.so_gi);
                return masuk - keluar;
            };
            const withAkhir = (r) => ({ ...r, akhir_kg: akhirKg(r) });
            // Kunci peta nilai persediaan akhir (impor ZSTOCK) — samakan dengan
            // normalisasi di server: huruf besar, tanpa awalan '-'.
            const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim().replace(/^[-\s]+/, '').toUpperCase();
            const c = this.cfg();
            const prod = this.tab === 'sawit' ? this.produksi : null;
            const jual = this.tab === 'sawit' ? this.penjualan : null;
            const awal = this.awalTahun[this.tab] || { products: {}, penyesuaianRp: 0 };
            const rpkg = (kg, rp) => (kg ? rp / kg : 0);
            const out = [];
            let totalProd = 0;
            let adaProd = false;
            let totalJual = 0;
            let adaJual = false;
            let totalOlah = 0;
            let adaOlah = false;
            let totalNilai = 0;
            let adaNilai = false;
            let totAwalKg = 0;
            let totAwalRp = 0;
            c.products.forEach((p, i) => {
                const map = (prod && p.key) ? (prod[p.key] || {}) : null;
                // Produk tanpa peta penjualan (TBS) → kolom penjualan tetap '-'.
                const jmap = (jual && p.key && jual[p.key]) ? jual[p.key] : null;
                // Pengolahan sendiri hanya untuk TBS (bahan baku yang diolah).
                const omap = (prod && p.keyOlah) ? (prod[p.keyOlah] || {}) : null;
                // Nilai persediaan akhir (Rp) hasil impor ZSTOCK, per unit kerja.
                const nmap = this.nilai[norm(p.label)] || null;
                const am = awal.products[p.label] || {};
                let sub = 0;
                let subJual = 0;
                let subOlah = 0;
                let subNilai = 0;
                let subAwalKg = 0;
                let subAwalRp = 0;
                const units = c.units.map(([plant, unit]) => {
                    const v = map ? map[plant] : null;
                    if (v != null) sub += Number(v);
                    const j = jmap ? jmap[plant] : null;
                    if (j != null) subJual += Number(j);
                    const o = omap ? omap[plant] : null;
                    if (o != null) subOlah += Number(o);
                    const n = nmap ? nmap[norm(plant) + '|' + norm(unit)] : null;
                    if (n != null) subNilai += Number(n);
                    const [aKg, aRp] = am[unit] || [0, 0];
                    subAwalKg += aKg;
                    subAwalRp += aRp;
                    return withAkhir({ _t: 'detail', plant, unit, prod_kg: v ?? null, jual_kg: j ?? null, olah_kg: o ?? null, nilai_rp: n ?? null, awal_kg: aKg, awal_rpkg: rpkg(aKg, aRp), awal_rp: aRp });
                });
                out.push(withAkhir({ _t: 'product', unit: p.label, prod_kg: map ? sub : null, jual_kg: jmap ? subJual : null, olah_kg: omap ? subOlah : null, nilai_rp: nmap ? subNilai : null, awal_kg: subAwalKg, awal_rpkg: rpkg(subAwalKg, subAwalRp), awal_rp: subAwalRp }));
                out.push(...units);
                out.push({ _t: 'spacer', _dash: c.spacerDash[i] });
                if (map) { totalProd += sub; adaProd = true; }
                if (jmap) { totalJual += subJual; adaJual = true; }
                if (omap) { totalOlah += subOlah; adaOlah = true; }
                if (nmap) { totalNilai += subNilai; adaNilai = true; }
                totAwalKg += subAwalKg;
                totAwalRp += subAwalRp;
            });
            const akhirRp = totAwalRp + awal.penyesuaianRp;
            const jualTot = adaJual ? totalJual : null;
            const olahTot = adaOlah ? totalOlah : null;
            const nilaiTot = adaNilai ? totalNilai : null;
            out.push(withAkhir({ _t: 'jumlah', unit: 'Jumlah', prod_kg: adaProd ? totalProd : null, jual_kg: jualTot, olah_kg: olahTot, nilai_rp: nilaiTot, awal_kg: totAwalKg, awal_rpkg: rpkg(totAwalKg, totAwalRp), awal_rp: totAwalRp }));
            out.push({ _t: 'penyes', plant: 'Penyesuaian atas nilai persediaan akhir', awal_rp: awal.penyesuaianRp });
            out.push(withAkhir({ _t: 'jumlahp', unit: 'Jumlah Persediaan', prod_kg: adaProd ? totalProd : null, jual_kg: jualTot, olah_kg: olahTot, nilai_rp: nilaiTot, awal_kg: totAwalKg, awal_rpkg: rpkg(totAwalKg, akhirRp), awal_rp: akhirRp }));
            return out;
        },

        setTab(key) {
            if (this.tab === key) return;
            this.tab = key;
            this.$nextTick(() => this.render());
        },

        // Muat nilai dari API. adopt=true (saat init): tanpa parameter → server
        // memilih periode terbaru yang punya data dan periode filter mengikutinya.
        async load(adopt = false) {
            try {
                const url = '/report-data/laba-rugi/persediaan' + (adopt ? '' : `?year=${this.year}&month=${this.month}`);
                const resp = await fetch(url);
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                if (adopt && data.year != null) { this.year = data.year; this.month = data.month; }
                this.produksi = data.produksi || null;
                this.penjualan = data.penjualan || null;
                this.nilai = data.nilai || {};
            } catch (e) {
                this.produksi = null;
                this.penjualan = null;
                this.nilai = {};
                if (window.lmToast) window.lmToast('Gagal memuat data: ' + e.message, 'err');
            }
            this.render();
        },

        // Selaraskan sekat kop dengan batas kolom KOMODITI (Plant + Unit Kerja) supaya
        // kop & tabel tampak satu grid seperti Excel.
        syncKop() {
            if (!this.table) return;
            const box = document.querySelector('.psd-head-box');
            if (!box) return;
            const w = (f) => { const c = this.table.getColumn(f); return c ? c.getElement().offsetWidth : 0; };
            const kiri = w('plant') + w('unit');
            if (kiri > 0) box.style.gridTemplateColumns = kiri + 'px 1fr';
        },

        render() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            this.table = new window.Tabulator('#psd-table', {
                data: this.rows(),
                columns: this.columns(),
                columnDefaults: { headerSort: false, headerHozAlign: 'center', headerVertAlign: 'middle' },
                layout: 'fitDataStretch',
                maxHeight: 'calc(100vh - 300px)',
                rowFormatter: (row) => {
                    const d = row.getData();
                    // Warna band persis template: subtotal produk, Jumlah &
                    // Jumlah Persediaan #E2EFDA (tebal + garis atas-bawah).
                    const band = { product: '#e2efda', jumlah: '#e2efda', jumlahp: '#e2efda' }[d._t] || null;
                    const bold = band !== null;
                    const el = row.getElement();
                    if (band) el.style.background = band;
                    row.getCells().forEach((cell) => {
                        const ce = cell.getElement();
                        if (band) {
                            ce.style.background = band;
                            ce.style.borderTop = '1px solid #555';
                            ce.style.borderBottom = '1px solid #555';
                        }
                        if (bold) ce.style.fontWeight = '700';
                    });
                    // Label yang di Excel melimpah dari kolom Plant ke Unit Kerja
                    // (Penyesuaian atas nilai ...): sel Plant dilebarkan menutup
                    // kolom Unit Kerja (emulasi colspan).
                    if (d._t === 'penyes') {
                        const cells = row.getCells();
                        const pc = cells.find((c) => c.getField() === 'plant');
                        const uc = cells.find((c) => c.getField() === 'unit');
                        if (pc && uc) {
                            // Lebar diambil dari definisi kolom (bukan offsetWidth sel)
                            // agar formatter aman dijalankan berulang oleh virtual DOM.
                            const lebar = this.table.getColumn('plant').getWidth() + this.table.getColumn('unit').getWidth();
                            uc.getElement().style.display = 'none';
                            pc.getElement().style.width = lebar + 'px';
                            pc.getElement().style.whiteSpace = 'nowrap';
                        }
                    }
                },
            });
            this.table.on('tableBuilt', () => this.syncKop());
        },

        init() {
            this.$nextTick(() => this.load(true));
            window.addEventListener('resize', () => this.syncKop());
        },
    };
}
</script>
@endpush
