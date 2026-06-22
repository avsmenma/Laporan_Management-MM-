# Spec — Popup Detail Sumber RKO & RKAP (LM14 Kebun)

Status: disetujui 2026-06-22. Implementasi mengikuti dokumen ini.

## Tujuan
Saat angka kolom **RKO**/**RKAP** (Bulan Ini & s.d Bulan Ini) di tabel LM14 Kebun diklik,
popup **langsung menampilkan detail sumber data per-baris** (baris mentah BKU/OHC yang
menyusun nilai sel) — tanpa langkah pivot perantara yang dipakai kolom realisasi.

Format isi popup = sama dengan tampilan "rincian lebih dalam" (deep) yang sudah ada:
section per sumber, kolom file asli, subtotal per section, grand total = nilai sel.

## Kenapa perlu tabel baru
`budget:import-test` saat ini hanya menyimpan **agregat** (`budget_rko`/`budget_rkap`,
satu nilai per komoditi|plant|kode). Detail per-baris hilang. Maka diperlukan tabel
mentah `budget_source` yang diisi importer.

## 1. Tabel `budget_source`
Kolom: `id, year, komoditi(KS/KR), plant_code, report_type(LM14), kode, source(BKU/OHC),
period, object_name, cost_element, cost_element_desc, klasifikasi, nilai(20,2), fisik(20,2)`.
Index `(year, komoditi, plant_code, report_type, kode)`.

Baris disimpan **hanya** untuk baris yang lolos filter sama dengan agregat (unit KEBUN,
kode ada di template LM14; OHC pabrik 5F dilewati) → grand total detail = nilai sel.

## 2. `budget:import-test`
Selain mengisi `budget_rko`/`budget_rkap` (agregat, tetap), juga menyimpan tiap baris
BKU/OHC yang diterima ke `budget_source`. Kolom file (indeks 0-based, sama BKU & OHC):
`0 komoditi · 1 plant · 3 period · 4 kode · 5 object_name · 6 cost_element ·
7 cost_element_desc · 8 klasifikasi · 9 nilai`; BKU tambah `10 fisik`.
Hapus-lalu-isi per (year, report_type) → idempoten.

## 3. Backend — `ReportController::drilldown`
Untuk `column ∈ {bi_rko, bi_rkap, sd_rko, sd_rkap}` pada LM14: kembalikan `detail`
(format `buildRawDetail`: sections + rows + subtotal + grand_total) **langsung**, plus
`context.direct_detail = true`. Tidak ada pivot (RKO & RKAP identik; budget tahunan →
bi & sd sama). Query `budget_source` difilter `year=batch.year, komoditi, report_type=LM14,
kode IN (kode baris detail penyusun), plant_code=unit` (atau semua bila unit=ALL). Grand
total = nilai sel. Section per sumber (BKU/OHC). Kolom popup: Sumber, Kode, Period,
Pekerjaan, Cost Element, Cost Element Desc, Klasifikasi, Fisik, Nilai.

## 4. Frontend — `kebun/index.blade.php`
`handleCellClick`: bila respons berisi `context.direct_detail`, set `drill.direct=true`,
`drill.view='deep'`, render via `buildDeepHtml(data.detail)` (tanpa fetch deep terpisah).
Sembunyikan tombol "kembali ke pivot" saat `drill.direct`. Breadcrumb deep menampilkan
label kolom (bukan PB7›PB712) saat direct. Sel RKO/RKAP sudah clickable (tak diubah).

## Cakupan & batas
LM14 Kebun (BKU+OHC). RKO=RKAP (sumber sama). GC tetap tanpa popup. LM13/LM16 di luar
lingkup (tidak ada data testing).

## Deploy
Push → server: pull, `php artisan migrate`, jalankan ulang `budget:import-test`
(mengisi `budget_source`), scp `public/build`. **Tidak** perlu `report:generate`
(nilai sel tidak berubah; hanya detail baru).

## Acceptance
- Klik RKO/RKAP (Bulan Ini & s.d) baris detail → popup langsung tampil baris mentah
  BKU/OHC; grand total = angka sel.
- Klik baris subtotal/total → gabungan baris seluruh kode penyusun; grand total = angka sel.
- Sel bernilai 0 → popup memberi keterangan "tidak ada baris".
- Kolom realisasi (Real Bln Ini/Lalu/Thn Lalu) tetap memakai pivot seperti sebelumnya.
