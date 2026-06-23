# Desain: Halaman Areal (statement areal per blok/petak)

- Tanggal: 2026-06-23
- Status: Disetujui user (verbal)
- Sumber: `docs/areal/AREAL STEATMENT 2026.xlsx` (sheet VIEW = layout tampilan, sheet DB = data upload/template).

---

## 1. Tujuan
Halaman menu **Areal** menampilkan statement luas & jumlah pokok per blok/petak, dipivot
per Status Blok/Petak × Tahun Tanam (baris) dan per AFD/Divisi (kolom), mengikuti sheet
VIEW. Data diunggah dari sheet DB lewat alur import yang sudah ada.

## 2. Sumber data (sheet DB, kolom A–S)
| Kol | Header | Pakai |
|---|---|---|
| A | Status (mis. `@5B@`) | simpan apa adanya (penanda) |
| B | Status Blok/Petak (TM/TU/ATTP/TBM) | **grup baris** |
| C | Plant | **kode unit** (mis. 5E01) → filter unit |
| D | Divisi (AFD01..AFD13) | **kolom pivot** |
| E | Kode Blok/Petak | simpan |
| F | Tanggal Mulai | simpan (serial) |
| G | Sampai | simpan (serial) |
| H | Project Definition | simpan |
| I | Deskripsi Blok/Petak | simpan |
| J | **Luas Tanam (Ha)** | **nilai Luas** (2 desimal) |
| K | Tahun Tanam | **sub-grup baris** |
| L | Total Pokok | simpan |
| M | Luas (Ha) | simpan |
| N | **Total Pokok Produktif** | **nilai Jlh Pokok** (bulat) |
| O | Kondisi Areal | simpan |
| P | Jenis Tanah | simpan |
| Q | GIS ID | simpan |
| R | Unit Kerja (nama) | simpan |
| S | Komoditi (KS) | filter komoditi |

**Terverifikasi:** Σ kolom J seluruh DB = 56.113,698 = Grand Total Luas VIEW; Σ kolom N =
6.836.640 = Grand Total Jlh Pokok VIEW. Jadi Luas←J, Pokok←N (pasti, bukan tebakan).

## 3. Tata letak tampilan (mengikuti sheet VIEW rows 5–67)
- Header induk (row 5): `AFD01 … AFD13`, `Total Luas [Ha]`, `Total Jlh Pokok`.
- Sub-header (row 6): kolom identitas `Status Blok/Petak`, `Tahun Tanam`, lalu tiap AFD
  punya 2 anak: `Luas [Ha]`, `Jlh Pokok`.
- Baris data dikelompokkan **Status** dengan urutan tetap **ATTP → TBM → TM → TU**
  (status lain, bila ada, ditambahkan setelahnya secara alfabetis). Dalam tiap status:
  baris per **Tahun Tanam** (urut naik), lalu baris **"{Status} Total"**. Ditutup baris
  **"Grand Total"**.
- Kolom AFD **dinamis per unit**: hanya AFD yang ada datanya untuk unit terpilih, urut
  numerik (AFD01, AFD02, …). `Total Luas`/`Total Jlh Pokok` selalu di ujung kanan.
- **Format angka:** Luas = 2 desimal; Jlh Pokok = bilangan bulat. Sel kosong → "-".
- Subtotal & Grand Total ditebalkan.

## 4. Arsitektur

### 4.1 Tabel `areal_blok` (migrasi baru)
Kolom: `id`, `batch_id` (FK batch), `status` (string, kol A), `status_blok_petak` (string,
kol B), `plant_code` (string, kol C), `divisi` (string, kol D), `kode_blok` (string, E),
`tanggal_mulai` (string/null, F), `tanggal_sampai` (string/null, G), `project_definition`
(string/null, H), `deskripsi` (string/null, I), `luas_tanam` (decimal 16,2, J),
`tahun_tanam` (smallint/null, K), `total_pokok` (int/null, L), `luas_ha` (decimal 16,2, M),
`total_pokok_produktif` (int/null, N), `kondisi_areal` (string/null, O), `jenis_tanah`
(string/null, P), `gis_id` (string/null, Q), `unit_kerja` (string/null, R), `komoditi`
(string, S), timestamps. Index `(batch_id, komoditi, plant_code, status_blok_petak, divisi, tahun_tanam)`.

### 4.2 Import (jenis baru `areal`)
- Tambah `areal` ke `SpreadsheetImportService::types()` (label "Areal"); `isBudget('areal')=false`.
- UI `/import`: jenis "Areal" → Kategori disembunyikan; **butuh tahun + bulan** (per bulan).
  `backendType` = `areal` (tanpa kategori). Lewat alur async (queue/progress) yang sudah ada.
- `import('areal', $batch, $file, …, $onProgress)` membaca **sheet "DB"** (case-insensitive;
  fallback sheet pertama bila hanya satu). Idempoten: hapus `areal_blok` per `batch_id` lalu
  insert per-chunk; panggil `$onProgress` per chunk.
- `dataRowCount` untuk areal harus menghitung baris sheet DB (bukan sheet pertama). Tambah
  jalur perhitungan total yang sadar-sheet untuk tipe areal (atau hitung saat job mulai dari
  sheet DB). Untuk preview, tampilkan kolom & contoh baris dari sheet DB.

### 4.3 Endpoint pivot
- `GET /report-data/areal?komoditi=KS&year=2026&month=5&unit=5E01` (juga `api/report/areal`
  di grup auth bila perlu). Controller: `App\Http\Controllers\Api\ArealController@index`
  (atau method di ReportController).
- Query: filter `batch(year,month)` + `komoditi` + `plant_code=unit`. Group by
  `status_blok_petak`, `tahun_tanam`, `divisi`; SUM(`luas_tanam`) & SUM(`total_pokok_produktif`).
- Bangun respons:
  ```
  {
    afds: ["AFD01","AFD02", ...],           // dinamis, urut numerik
    rows: [
      { type:"detail", status:"ATTP", tahun_tanam:1983,
        cells: { "AFD01": {luas: 5, pokok: 381}, ... },
        total: { luas: 5, pokok: 381 } },
      ...
      { type:"subtotal", status:"ATTP", label:"ATTP Total", cells:{...}, total:{...} },
      ...
      { type:"grandtotal", label:"Grand Total", cells:{...}, total:{...} }
    ]
  }
  ```
- Subtotal = Σ baris detail status itu; Grand Total = Σ semua detail. Sel/total kosong = 0
  (tampil "-" di klien).

### 4.4 Halaman `/areal`
- Ganti `Route::view('/areal','coming-soon',…)` → `ArealController@page` (atau view + JS).
  Tetap di grup `auth + role:Viewer,Operator,Admin` (semua role lihat).
- Blade `resources/views/areal/index.blade.php` extends `layouts.app`; filter bar
  (Komoditi, Tahun, Bulan, Unit) meniru halaman kebun; tabel via Tabulator dengan
  **grouped columns** dibangun dinamis dari `afds` (tiap AFD = `{title:'AFDxx', columns:[Luas,Jlh Pokok]}`).
- Identitas (Status Blok/Petak, Tahun Tanam) frozen kiri. Luas 2-dec, Pokok int (formatter).
- Sumber opsi filter (unit/komoditi/tahun/bulan): ikut pola kebun (unit dari `ref_unit`,
  batch dari tabel `batch`). Default: periode terbaru yang ada datanya.

## 5. Penanganan error / tepi
- Unit tanpa data pada periode → tabel kosong (pesan "Tidak ada data areal untuk filter ini").
- Tahun Tanam null → dikelompokkan sebagai "(Tanpa Tahun)" di akhir status (jarang).
- Divisi di luar pola AFD → tetap ditampilkan sebagai kolom apa adanya, diurut setelah AFD.
- Sheet DB tak ditemukan saat import → job `failed` dengan pesan jelas.

## 6. Acceptance
- [ ] Jenis import "Areal" muncul di `/import`; upload file (sheet DB) → job sukses → `areal_blok` terisi (mis. 2871 baris untuk file contoh, satu batch).
- [ ] `/areal` menampilkan tabel grouped-header sesuai VIEW: identitas frozen, AFD dinamis per unit, Total di ujung; Luas 2-desimal, Pokok bulat.
- [ ] Urutan status ATTP→TBM→TM→TU, subtotal "{Status} Total", Grand Total di bawah.
- [ ] Uji angka: untuk komoditi KS, Grand Total Luas = 56.113,70 & Pokok = 6.836.640 bila semua unit dijumlah (per-unit cocok dengan subset).
- [ ] Feature test: import areal (file kecil sheet DB) → baris masuk; endpoint pivot mengembalikan afds + rows + subtotal/grandtotal benar.

## 7. Berkas yang disentuh (perkiraan)
- `database/migrations/..._create_areal_blok_table.php` — baru.
- `app/Models/ArealBlok.php` — baru.
- `app/Domain/Import/SpreadsheetImportService.php` — jenis `areal` + baca sheet DB + importAreal + dataRowCount sadar-sheet.
- `app/Jobs/ProcessImport.php` — sudah generik (import('areal',…)) ; pastikan jalur areal jalan.
- `app/Http/Controllers/Api/ArealController.php` (atau ReportController) — endpoint pivot + halaman.
- `resources/views/areal/index.blade.php` — baru (tabel + filter + JS Tabulator).
- `resources/views/import/index.blade.php` — jenis "Areal" (Kategori hidden, month tampil).
- `routes/web.php` — route `/areal` (page) + `/report-data/areal`.

## 8. Risiko & keputusan
1. **Pemilihan sheet "DB".** Importer areal harus menyasar sheet DB; alur import lama selalu
   sheet pertama. Tambah parameter sheet untuk jalur areal saja (tak mengubah wbs/ohc/gc).
2. **Tabulator grouped + dynamic columns.** Kolom dibangun dari respons `afds`; perlu rebuild
   kolom saat filter unit berubah (AFD set berubah).
3. **Asset (JS/CSS) berubah** → wajib `npm run build` + scp `public/build` saat deploy; restart
   worker bila kode Job/Service berubah (areal import).
4. Komoditi saat ini hanya KS; filter tetap disediakan untuk KR di masa depan.
