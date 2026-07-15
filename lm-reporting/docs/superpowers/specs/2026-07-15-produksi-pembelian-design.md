# Spec Desain — Submenu Produksi → Pembelian (Pembelian TBS)

Tanggal: 2026-07-15. Status: disetujui user (layout Rincian melebar semua pabrik; RKAP tampil "-" dulu).

## Tujuan

Memindahkan informasi pembelian TBS dari tab "Pembelian" di `/produksi/kebun` (yang hanya punya
kuantum timbangan) ke halaman baru `/produksi/pembelian` dengan data pembelian SAP lengkap
(kuantum + rupiah), mereplikasi sheet **TEMPLATE** workbook
`docs/produksi/pembelian/Pembelian TBS Tahun 2026.xlsx`.

## Sumber data

Sheet **Data** workbook di atas = ekspor SAP pembelian TBS, 131.348 baris tahun 2026 periode 1–6.
Kolom: Post. Date, Period, Plant, Plant Desc., Batch (PHTG/PLSM), Vendor, Vendor Name, UOM (KG),
Qty TBS, Prelim Val, Price Diff, Actual Value, Price, Curr., Jenis (Good Receipt/Invoice),
Contract, Purch. Order, Mat. Doc, Year, Inv. Doc, Item Inv, Year Inv.

Aturan nilai (tervalidasi selisih 0 vs pivot "Summary Pabrik Batch"):
- **Qty** = Σ `Qty TBS`; **Value** = Σ `Actual Value` — semua baris dijumlahkan
  (Good Receipt DAN Invoice; nilai minus = koreksi, ikut dijumlahkan apa adanya).
- `Batch` adalah pengelompokan resmi: PHTG = Kebun Pihak 3, PLSM = Kebun Plasma.
- 8 plant: 5F01, 5F04, 5F07, 5F08, 5F09, 5F14, 5F15, 5F22.

## Komponen

### 1. Tabel `pembelian_tbs`
Satu baris = satu baris sheet Data. Kolom: posting_date, year, period, plant_code, plant_desc,
batch, vendor_code, vendor_name, uom, qty, prelim_val, price_diff, actual_value, price, jenis,
contract, purch_order, mat_doc. Index: (year, period), plant_code, batch, vendor_code.

### 2. Impor
- `SpreadsheetImportService::importPembelianTbs()` — baca sheet **Data** secara chunked
  (ReadFilter per blok baris, file 131 ribu baris), idempoten **hapus-ganti per (year, period)**
  yang muncul di file (satu file bisa berisi banyak periode sekaligus).
- Jenis impor baru **"Pembelian TBS"** di `/import` + template unduhan (header persis sheet Data).
- CLI `php artisan pembelian-tbs:import --file=`.

### 3. API `GET /api/produksi/pembelian?year=&month=`
Controller `PembelianTbsController` (pola ProduksiKebunController, on-the-fly, tanpa materialisasi):
- `periods` dari distinct (year, period); default periode terbaru.
- **summary**: baris 8 pabrik urut template (Gunung Meliau 5F01, Rimba Belian 5F04, Ngabang 5F07,
  Parindu 5F08, Kembayan 5F09, Pelaihari 5F15, Long Pinang 5F22, Pamukan 5F14), tiap pabrik
  3 baris {Kebun Pihak 3, Kebun Plasma, Jumlah} + baris Total. Blok nilai: Bulan Lalu (period−1,
  tahun sama; Januari → 0), Bulan Ini, Sd Bulan Ini (period ≤ bulan), masing-masing {qty, rpkg,
  value}; RKAP Bulan Ini & RKAP Sd {qty, rpkg, value} = null (belum ada sumber; struktur payload
  disiapkan). Rasio: BI/BL {qty=G/D, value=I/F}, BI/RKAP BI {G/M, I/O}, SBI/RKAP SBI {J/P, L/R} —
  penyebut 0/null → 0.
- **rincian**: grup **PHTG** → baris vendor (urut kode asc) → subtotal "PHTG Total"; grup **PLSM**
  → … → "PLSM Total"; "Grand Total". Nilai per plant {bi:{qty,rpkg,value}, sd:{qty,rpkg,value}} +
  Total {bi,sd}×{qty,value} per baris.
- `rpkg = value/qty` (qty 0 → 0). Auth: trait AuthorizesReportRequests (sama halaman produksi lain).

### 4. Halaman `/produksi/pembelian`
- Submenu **PEMBELIAN** di sidebar Produksi (setelah KEBUN); route web `produksi.pembelian`.
- Pola blade `pabrik/alokasi-biaya-olah.blade.php`: kartu header, dropdown Bulan+Tahun, 2 tab
  (**Summary**, **Rincian**), Tabulator grouped header, kolom identitas frozen, header frozen
  (maxHeight), format angka id-ID, rasio dalam %.
- Summary: kolom identitas {Nama Pabrik, Pembelian}; grouped {Bulan Lalu|Bulan Ini|Sd Bulan Ini|
  RKAP Bulan Ini|RKAP Sd. Bulan Ini} × {Qty, Rp/Kg, Value} + {BI/BL|BI/RKAP BI|SBI/RKAP SBI} ×
  {Qty, Value}. Sel RKAP tampil "-".
- Rincian: kolom identitas {Pembelian, Kode Supplier, Nama Supplier} frozen; per pabrik (urut
  template, label nama pendek Pagun/Parba/…) grup {Bulan Ini, SD Bulan Ini} × {Qty, Rp/Kg, Value};
  ujung: Total (Bulan Ini & SD) × {Qty, Nilai}.

### 5. `/produksi/kebun`
Tab **Pembelian dihapus** — halaman tinggal tabel Kebun Sendiri. Impor Produksi Kebun
(`produksi_kebun_wb`) tidak berubah.

## Acceptance (selisih 0)
- Juni 2026: 5F01 PHTG 3.839.670 / 12.753.605.700; 5F01 PLSM 6.999.360 / 23.320.667.300;
  Grand 42.504.620 / 138.451.720.735 (pivot Summary Pabrik & Summary Pabrik Batch).
- Rincian Juni: subtotal PHTG Total qty 32.372.330 / 104.492.557.500; PLSM Total 10.132.290 /
  33.959.163.235 (sheet RINCIAN).
- Bulan Lalu (Juni → Mei): 5F01 jumlah 8.600.420 / 27.796.617.150 (kolom 5 pivot).

## Di luar lingkup
- Sumber & impor RKAP pembelian TBS (kolom dirender "-", wiring menyusul).
- Drill-down sel. Export tombol mengikuti pola halaman produksi yang ada.
