@extends('layouts.app')

@section('title', 'Persediaan')

@section('content')
<div x-data="persediaanApp()" x-init="init()" class="psd-page">
    <div class="filter-bar">
        <div class="filter-grid">
            {{-- Opsi dirender server (bukan x-for) supaya nilai awal Juli 2026 langsung terpilih --}}
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
    .psd-frame .report-card { border-top-left-radius: 0; }
    .psd-frame .lm-report-table { border-top: 0; }

    /* Kop menyatu dengan tabel: hijau #70AD47 + teks putih persis template */
    .psd-head { padding: 6px 0 0; background: #fff; }
    .psd-head-box { display: grid; grid-template-columns: 250px 1fr; border: 1px solid #333; border-left: 0; border-right: 0; }
    .psd-head-left { background: #70ad47; color: #fff; font-weight: 700; font-size: .8rem; display: flex; flex-direction: column; justify-content: center; gap: 2px; padding: 6px 10px; border-right: 1px solid #3d6b28; }
    .psd-head-title { background: #70ad47; color: #fff; font-weight: 700; font-size: 1.05rem; letter-spacing: .02em; display: flex; align-items: center; justify-content: center; text-align: center; padding: 6px 12px; }

    /* Semua baris putih seperti Excel (matikan striping baris genap) */
    .psd-frame .tabulator .tabulator-row.tabulator-row-even { background: #fff; }

    /* Judul kolom boleh membungkus (PENERIMAAN TRANSFER, PENGOLAHAN SENDIRI, dst.)
       supaya lebar grup tetap = jumlah lebar anaknya, tanpa celah seperti Excel */
    .psd-frame .tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title { white-space: normal; }
</style>
@endsection

@push('scripts')
<script>
function persediaanApp() {
    return {
        // UI dulu (template) — seluruh nilai '-', sumber data menyusul. Default periode
        // mengikuti workbook acuan (Juli 2026); dropdown hanya mengubah label periode.
        month: 7,
        year: 2026,
        tab: 'sawit',
        table: null,
        tabs: [
            { key: 'sawit', label: 'KELAPA SAWIT' },
            { key: 'karet', label: 'KARET' },
        ],

        // ---- Struktur baris persis sheet KELAPA SAWIT & KARET (TEMPLATE PERSEDIAAN.xlsx) ----
        cfg() {
            if (this.tab === 'karet') {
                return {
                    judul: 'PERSEDIAAN AKHIR HASIL PRODUKSI KELAPA KARET',
                    section: 'KARET',
                    products: ['- SIR 20 SWJP', '- SCRAP', '- BLANKET', '- LATEKS', '- LUMP', '- RSS I', '- RSS II', '- RSS III'],
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
                section: 'KELAPA SAWIT',
                products: ['- Minyak Sawit', '- Inti Sawit', '- Tandan Buah Segar'],
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

        // Belum ada data → sel nilai '-' (format akuntansi template menampilkan 0
        // sebagai strip); baris seksi & pemisah dibiarkan kosong.
        numFmt(cell) {
            const d = cell.getRow().getData();
            if (d._t === 'section') return '';
            if (d._t === 'spacer') return (d._dash && cell.getField() === 'akhir_kg') ? '-' : '';
            return '-';
        },

        // ---- Kolom persis template: grouped header 4 tingkat mengikuti merge Excel ----
        columns() {
            const num = (title, field, width) => ({ title, field, width, hozAlign: 'right', formatter: this.numFmt.bind(this) });
            const periode = this.periodeLabel();
            return [
                { title: 'KOMODITI', columns: [
                    { title: 'Plant', field: 'plant', width: 60, frozen: true, hozAlign: 'left' },
                    { title: 'Unit Kerja', field: 'unit', width: 190, frozen: true, hozAlign: 'left' },
                ] },
                { title: 'PERSEDIAAN AWAL TAHUN', columns: [
                    num('(Kg)', 'awal_kg', 117),
                    num('(Rp/Kg)', 'awal_rpkg', 75),
                    num('(Rp)', 'awal_rp', 129),
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
                { title: 'NILAI PERSEDIAAN<br>AKHIR<br>' + periode, columns: [num('(Rp)', 'nilai_rp', 129)] },
            ];
        },

        // Urutan baris verbatim: seksi → per produk (subtotal + rincian unit + pemisah)
        // → Jumlah → Penyesuaian → Jumlah Persediaan.
        rows() {
            const c = this.cfg();
            const out = [{ _t: 'section', plant: c.section }];
            c.products.forEach((p, i) => {
                out.push({ _t: 'product', unit: p });
                c.units.forEach(([plant, unit]) => out.push({ _t: 'detail', plant, unit }));
                out.push({ _t: 'spacer', _dash: c.spacerDash[i] });
            });
            out.push({ _t: 'jumlah', unit: 'Jumlah' });
            out.push({ _t: 'penyes', plant: 'Penyesuaian atas nilai persediaan akhir' });
            out.push({ _t: 'jumlahp', unit: 'Jumlah Persediaan' });
            return out;
        },

        setTab(key) {
            if (this.tab === key) return;
            this.tab = key;
            this.$nextTick(() => this.render());
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
                    // Warna band persis template: seksi #C6E0B4; subtotal produk,
                    // Jumlah & Jumlah Persediaan #E2EFDA (tebal + garis atas-bawah).
                    const band = { section: '#c6e0b4', product: '#e2efda', jumlah: '#e2efda', jumlahp: '#e2efda' }[d._t] || null;
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
                    // (KELAPA SAWIT / KARET / Penyesuaian ...): sel Plant dilebarkan
                    // menutup kolom Unit Kerja (emulasi colspan).
                    if (d._t === 'section' || d._t === 'penyes') {
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
            this.$nextTick(() => this.render());
            window.addEventListener('resize', () => this.syncKop());
        },
    };
}
</script>
@endpush
