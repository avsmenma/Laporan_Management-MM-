@extends('layouts.app')

@section('title', 'RBB')

@section('content')
<div x-data="rbbApp()" x-init="init()" class="rbb-page">
    <div class="filter-bar">
        <div class="filter-grid">
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

            <div class="filter-group">
                <label class="filter-label">Kategori</label>
                <select class="filter-select" x-model="kategori" @change="render()">
                    <option value="semua">Semua</option>
                    <template x-for="s in daftarSegmen()" :key="s">
                        <option :value="s" x-text="namaSegmen(s)"></option>
                    </template>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Rincian</label>
                <div class="rbb-aksi">
                    <button type="button" class="btn" @click="bukaSemua()">Buka semua</button>
                    <button type="button" class="btn" @click="tutupSemua()">Tutup semua</button>
                </div>
            </div>
        </div>
    </div>

    <div class="rbb-frame">
        <div class="report-card">
            {{-- Kop persis sheet acuan: identitas perusahaan + judul berperiode --}}
            <div class="rbb-head">
                <div>PT. PERKEBUNAN NUSANTARA IV - REGIONAL V</div>
                <div class="rbb-head-judul" x-text="'RINCIAN PNL ' + (data.periode.label || '')"></div>
            </div>
            <div id="rbb-table" class="lm-report-table"></div>
            <div class="rbb-kosong" x-show="!loading && !data.ada_data" x-cloak>
                Belum ada data RBB untuk periode ini. Unggah berkas GL lewat menu Import
                (jenis <strong>RBB — Rincian PNL (GL)</strong>).
            </div>
        </div>
    </div>
</div>

<style>
    .rbb-page .filter-bar { position: sticky; top: 60px; z-index: 30; }
    body.lm-focus .rbb-page .filter-bar { top: 0; }
    .rbb-frame .lm-report-table { border-top: 0; }

    .rbb-aksi { display: flex; gap: 8px; }
    .rbb-aksi .btn { height: 38px; padding: 0 14px; }

    /* Kop menyatu dengan tabel: warna sama dengan header kolom */
    .rbb-head { background: var(--g-700); color: #fff; padding: 10px 14px; font-size: 12px; letter-spacing: .02em; border-bottom: 1.5px solid rgba(255, 255, 255, .45); }
    .rbb-head-judul { font-size: 15px; font-weight: 700; margin-top: 2px; }

    .rbb-kosong { padding: 28px 18px; text-align: center; color: var(--ink-500); font-size: 13px; }

    /* Segitiga buka/tutup rincian pada kolom Uraian */
    .rbb-caret { display: inline-block; width: 16px; color: var(--g-700); font-size: 10px; }
    .rbb-caret-kosong { color: transparent; }
    .rbb-baris-anak { cursor: pointer; }
</style>
@endsection

@push('scripts')
<script>
function rbbApp() {
    return {
        year: 2026,
        month: 1,
        kategori: 'semua',  // 'semua' | nama segmen ('1. Sawit', dst)
        loading: false,
        data: { periode: {}, blok: [], baris: [], ada_data: false },
        tutup: {},          // id baris yang rinciannya disembunyikan
        petaKunci: {},      // field kolom Tabulator → kunci nilai dari server
        kunciSegmen: [],    // kunci sel segmen yang sedang tampil
        fieldGrand: '',     // field kolom Grand Total
        table: null,

        async load(adopsiPeriode = false) {
            this.loading = true;
            try {
                const q = adopsiPeriode ? '' : `?year=${this.year}&month=${this.month}`;
                const resp = await fetch(`/report-data/laba-rugi/rbb${q}`);
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                this.data = await resp.json();
                if (this.data.periode) {
                    this.year = this.data.periode.year;
                    this.month = this.data.periode.month;
                }
                // Periode baru bisa saja tak punya segmen yang sedang dipilih.
                if (this.kategori !== 'semua' && !this.daftarSegmen().includes(this.kategori)) {
                    this.kategori = 'semua';
                }
                this.tutupSemua(false);
                this.render();
            } catch (e) {
                if (window.lmToast) window.lmToast('Gagal memuat RBB: ' + e.message, 'err');
            } finally {
                this.loading = false;
            }
        },

        // ===== Buka/tutup rincian =====

        punyaAnak(id) {
            return this.data.baris.some(r => r.induk === id);
        },
        // Bawaan seperti pivot acuan: kelompok besar terbuka, rincian akun tertutup.
        tutupSemua(gambarUlang = true) {
            const t = {};
            this.data.baris.forEach(r => { if (r.level === 2) t[r.id] = true; });
            this.tutup = t;
            if (gambarUlang) this.render();
        },
        bukaSemua() {
            this.tutup = {};
            this.render();
        },
        toggle(id) {
            if (!this.punyaAnak(id)) return;
            this.tutup = { ...this.tutup, [id]: !this.tutup[id] };
            this.render();
        },
        tampil() {
            const induk = {};
            this.data.baris.forEach(r => { induk[r.id] = r.induk; });
            return this.data.baris.filter(r => {
                let p = r.induk;
                while (p) {
                    if (this.tutup[p]) return false;
                    p = induk[p];
                }
                return true;
            });
        },

        // ===== Saringan kategori (segmen) =====

        // Daftar segmen yang ada datanya pada periode ini, gabungan seluruh blok.
        daftarSegmen() {
            const out = [];
            (this.data.blok || []).forEach((b) => b.segmen.forEach((s) => {
                if (!out.includes(s)) out.push(s);
            }));
            return out.sort();
        },
        // Label dropdown tanpa nomor urut: "1. Sawit" → "Sawit".
        namaSegmen(s) {
            return String(s).replace(/^\s*\d+\.\s*/, '');
        },
        // Segmen yang sedang dipilih, atau null bila "Semua".
        segmenAktif() {
            const daftar = this.daftarSegmen();
            return this.kategori !== 'semua' && daftar.includes(this.kategori) ? this.kategori : null;
        },
        // Blok yang punya segmen terpilih (saat "Semua": seluruh blok).
        blokTampil() {
            const seg = this.segmenAktif();
            return (this.data.blok || []).filter((b) => seg === null || b.segmen.includes(seg));
        },

        // ===== Kolom & baris Tabulator =====

        esc(s) {
            return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        },
        // Format akuntansi sheet acuan: minus dalam kurung, nol jadi '-', sel tanpa
        // posting dibiarkan KOSONG (bukan 0) persis seperti pivot.
        numFmt(cell) {
            const v = cell.getValue();
            if (v === undefined || v === null || v === '') return '';
            const n = Number(v);
            if (!isFinite(n)) return '';
            if (n === 0) return '-';
            const t = Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });
            return n < 0 ? '(' + t + ')' : t;
        },
        num(title, field, kunci, minWidth = 140) {
            this.petaKunci[field] = kunci;
            return { title, field, hozAlign: 'right', headerHozAlign: 'center', minWidth, formatter: this.numFmt.bind(this) };
        },
        uraianFmt(cell) {
            const d = cell.getRow().getData();
            const pad = d._level >= 1 ? (d._level - 1) * 16 : 0;
            const caret = d._anak
                ? '<span class="rbb-caret">' + (d._tutup ? '&#9656;' : '&#9662;') + '</span>'
                : '<span class="rbb-caret rbb-caret-kosong">&#9656;</span>';
            return '<span style="padding-left:' + pad + 'px">' + caret + this.esc(d.uraian) + '</span>';
        },
        kolom() {
            // Nama field sengaja c0,c1,… — nama blok/segmen mengandung titik ("1. Sawit")
            // yang akan dibaca Tabulator sebagai penunjuk objek bersarang.
            this.petaKunci = {};
            this.kunciSegmen = [];   // kunci sel segmen yang tampil → dasar Grand Total
            const seg = this.segmenAktif();
            let i = 0;
            const cols = [
                { title: 'Uraian', field: 'uraian', frozen: true, minWidth: 330,
                  formatter: this.uraianFmt.bind(this),
                  cellClick: (e, cell) => this.toggle(cell.getRow().getData().id) },
                { title: 'GL Account Desc', field: 'gl', frozen: true, minWidth: 250 },
            ];
            this.blokTampil().forEach((b) => {
                const sub = (seg === null ? b.segmen : [seg]).map((s) => {
                    const field = 'c' + i++;
                    this.kunciSegmen.push(b.nama + '|' + s);
                    return this.num(s, field, b.nama + '|' + s);
                });
                // Kolom Total blok hanya berarti bila lebih dari satu segmen tampil.
                if (seg === null) sub.push(this.num('Total', 'c' + i++, b.nama + '|__total'));
                cols.push({ title: b.nama, headerHozAlign: 'center', columns: sub });
            });
            // Grand Total dihitung ulang dari kolom segmen yang tampil (bukan nilai
            // __grand dari server), supaya ikut menyusut saat kategori disaring.
            this.fieldGrand = 'c' + i++;
            cols.push({ title: 'Grand Total', field: this.fieldGrand, hozAlign: 'right', headerHozAlign: 'center', minWidth: 160, formatter: this.numFmt.bind(this) });

            return cols;
        },
        baris() {
            const out = [];
            this.tampil().forEach((r) => {
                const row = {
                    id: r.id, uraian: r.uraian, gl: r.gl,
                    _level: r.level, _anak: this.punyaAnak(r.id), _tutup: !!this.tutup[r.id],
                };
                let ada = false;
                Object.keys(this.petaKunci).forEach((f) => {
                    const v = r.nilai[this.petaKunci[f]];
                    if (v !== undefined) { row[f] = v; ada = true; }
                });
                let grand = null;
                this.kunciSegmen.forEach((k) => {
                    const v = r.nilai[k];
                    if (v !== undefined) grand = (grand ?? 0) + Number(v);
                });
                if (grand !== null) row[this.fieldGrand] = grand;
                // Saat kategori disaring, baris tanpa posting di kategori itu ikut
                // hilang seperti pivot yang difilter; baris Grand Total selalu tampil.
                if (ada || r.level === 0) out.push(row);
            });
            return out;
        },

        render() {
            if (this.table) { try { this.table.destroy(); } catch (e) {} this.table = null; }
            const columns = this.kolom();   // mengisi petaKunci → wajib sebelum baris()
            this.table = new window.Tabulator('#rbb-table', {
                data: this.baris(),
                columns,
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                maxHeight: 'calc(100vh - 250px)',
                rowFormatter: (row) => {
                    const d = row.getData();
                    let bg = null, fw = null;
                    if (d._level === 0) { bg = '#dcebe2'; fw = '700'; }        // Grand Total
                    else if (d._level === 1) { bg = '#eef5f1'; fw = '700'; }   // Klasifikasi 2
                    else if (d._level === 2) { fw = '600'; }                   // Jenis Beban
                    const el = row.getElement();
                    if (d._anak) el.classList.add('rbb-baris-anak');
                    if (!bg && !fw) return;
                    if (fw) el.style.fontWeight = fw;
                    if (bg) el.style.background = bg;
                    row.getCells().forEach((c) => {
                        const ce = c.getElement();
                        if (fw) ce.style.fontWeight = fw;
                        if (bg) ce.style.background = bg;
                    });
                },
            });
        },

        init() {
            this.load(true);   // periode terbaru yang ada datanya
        },
    };
}
</script>
@endpush
