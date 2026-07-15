# Spec Desain — Menu Laba Rugi → Penjualan (Penjualan Produk)

Tanggal: 2026-07-15. Status: disetujui user (angka tampil NEGATIF dalam kurung, seperti pivot Excel).

## Tujuan

Menu top-level baru **LABA RUGI** (di sidebar, di atas menu KEBUN) dengan submenu **PENJUALAN**
→ halaman `/laba-rugi/penjualan` berisi 3 tab yang mereplikasi sheet template workbook
`docs/laba_rugi/penjualan/Penjualan Produk Tahun 2026.xlsx`:

| Tab | Sheet acuan | Bentuk |
|---|---|---|
| BUYER | `temp Buyer` | grup produk → baris customer; blok BULAN INI + SD BULAN INI |
| PLANT | `temp Plant` | grup produk → baris profit center; blok BULAN INI + SD BULAN INI |
| ALL | `temp Buyer Plant` | grup produk → baris customer; kolom per plant + JUMLAH; **BULAN INI saja** |

Tiap blok nilai = {QTY, RP/KG, NILAI}; RP/KG = NILAI/QTY (penyebut 0 → 0).

## Sumber data

Sheet **Data** = ekspor GL SAP pendapatan (5.484 baris, periode 1–6/2026). Kolom kunci:
Posting Date (B), Posting Period (C), Account (D), Profit Center (I) + Description Prctr (J),
Amount in Local Currency (O), GL Account Desc (S), Material (Y), Material Description (Z),
Quantity (AA), UOM (AB), Customer (AF), Customer Name (AG).

Aturan nilai (tervalidasi selisih 0 vs pivot Summary-BUYER):
- QTY = Σ Quantity; NILAI = Σ Amount — SEMUA baris & SEMUA akun dijumlahkan
  (41100000 Sawit, 41100006 Karet, 42000030 Sertifikasi; baris koreksi positif ikut).
- Nilai tersimpan **NEGATIF** (kredit pendapatan) dan **ditampilkan apa adanya** —
  negatif dirender dalam tanda kurung merah, mis. `(13.875.885)`. RP/KG otomatis positif.
- Grup produk = Material Description: **CPO, INTI SAWIT, Lump, TBS (TANDAN BUAH SEGAR)**
  (urutan template; material lain menyusul di akhir bila muncul).
- Baris (customer / profit center) **dinamis dari data** (union Bulan Ini + SD), urut kode.
  Catatan: "KEBUN LONGKALI" (kode INF di template) belum ada di data → muncul otomatis nanti.
- Kolom plant tab ALL: urut 5F dulu lalu 5E, kode menaik (sama dengan urutan template)
  + kolom JUMLAH.

## Komponen

1. **Tabel `penjualan_produk`** — satu baris = satu posting GL: document_number, posting_date,
   year, period, account, gl_account_desc, profit_center, profit_center_desc, material_code,
   material_desc, qty, uom, amount, customer_code, customer_name, document_type, reference.
   Index (year, period), profit_center, customer_code, material_desc.
2. **Impor** — jenis `penjualan_produk` ("Penjualan Produk", kategori UI `[LABA RUGI] PENJUALAN
   PRODUK`) pola Pembelian TBS: file multi-periode → hapus-ganti per (year, period), tahun
   sebagai penjaga, streaming; template unduhan header persis sheet Data; CLI
   `php artisan penjualan-produk:import --file= [--year=]`; purge target di /data.
3. **API `GET /report-data/laba-rugi/penjualan?year&month`** (`PenjualanProdukController`,
   on-the-fly): `periods`, `plants` (utk kolom ALL), `buyer`, `plant`, `all` — tiap tab:
   groups[{material, rows[{code, name, bi{qty,rpkg,nilai}, sd{...}}], jumlah, ...}] + total.
   Tab ALL: rows membawa nilai per plant {qty,rpkg,nilai} + jumlah (Bulan Ini saja).
4. **Halaman `/laba-rugi/penjualan`** — pola halaman Pembelian TBS: dropdown Bulan+Tahun
   (default KOSONG, tidak auto-pilih), 3 tab, Tabulator grouped header, identitas frozen,
   header frozen (maxHeight), id-ID; negatif = kurung merah; 0/null = '-'.
5. **Menu** — LABA RUGI (induk, ikon 📉/💰) di ATAS menu KEBUN; submenu PENJUALAN.

## Acceptance (selisih 0, tanda negatif)
- Juni: CPO qty −13.875.885 / nilai −206.040.828.960; Grand Total −16.840.772 /
  −230.403.056.338; SD Juni = setahun −83.963.451 / −1.104.788.325.803.
- Sampel: CPO customer 11007177 Juni −4.602.022 / −70.281.955.150.
- Tab PLANT & ALL: Jumlah per grup = angka grup di BUYER (invariant Σ sama).

## Di luar lingkup
- Submenu Laba Rugi lain (fase berikutnya). Drill-down sel. RKAP/anggaran penjualan.
