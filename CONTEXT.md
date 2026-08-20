# CONTEXT.md — Sistem Pelaporan LM PTPN IV Regional 5

> Ditulis 2026-08-12 sebagai serah-terima konteks untuk AI/developer baru yang **belum pernah**
> melihat project ini. Baca sampai habis sebelum menyentuh kode.
> Tanda **[BELUM PASTI]** = belum terverifikasi dari kode/server, jangan diandalkan mentah-mentah.

---

## 1. RINGKASAN PROJECT

**Apa:** aplikasi web *report viewer* biaya produksi & laba-rugi perkebunan, menggantikan
workbook Excel "LM" (Laporan Manajemen) yang selama ini dikerjakan manual.

**Untuk siapa:** PT Perkebunan Nusantara IV Regional 5 (Kalimantan) — unit KEBUN (kode `5E*`),
PABRIK/PKS (kode `5F*`), dan IPP (`5R00`). Pemakai internal dengan 3 role (Viewer/Operator/Admin).

**Masalah yang diselesaikan:** angka LM sebelumnya dirakit di Excel lintas file dengan
`IMPORTRANGE` dan copy-paste manual. Aplikasi ini mengimpor ekspor mentah SAP (WBS/OHC/GC/GL/
ZSTOCK dll.), menghitung ulang, dan menampilkan tabel yang **bentuknya harus identik** dengan
workbook Excel acuan (grouped header, kolom identitas frozen, urutan baris, blok kolom).

**Status saat ini (per 2026-08-12): DIPAKAI HARIAN DENGAN DATA ASLI** di dua server produksi
(dikonfirmasi pemilik project). Namun secara fitur masih **setengah jadi**:

| Bagian | Status |
|---|---|
| Mesin hitung LM14 / LM13 / LM16 (Kebun & Pabrik) | Jalan, hasil dimaterialisasi ke tabel `report_lm1x` |
| Impor 20+ jenis berkas SAP + antrean job + auto-regenerate | Jalan |
| Halaman Areal, Produksi (PKS/Kebun/Pembelian/Rekap), Investasi | Jalan |
| Halaman Laba Rugi (Penjualan, LM 34, Beban Usaha, Persediaan) | Jalan sebagian — banyak kolom masih `-` karena sumber datanya belum ada |
| LM-27A | Hanya baris Penjualan→Lokal yang terisi; sisanya `-` |
| Alokasi Biaya Olah | Halaman + tabel `produksi_cpo_inti` ada, **nilai biaya masih placeholder** |
| Export Excel/CSV/PDF, PKR (pabrik karet 5F20) | **Belum dibangun sama sekali** |

⚠️ **Data di server adalah data operasional nyata.** Jangan pernah menjalankan
`migrate:fresh`, `migrate --seed` ulang, `db:wipe`, atau menghapus baris tabel di server
tanpa persetujuan eksplisit pemilik project.

---

## 2. STACK & VERSI

Dari `lm-reporting/composer.json` & `lm-reporting/package.json`:

- **PHP** `^8.2` (server 157 menjalankan php8.4-fpm; server lama disebut PHP 8.5 **[BELUM PASTI]**)
- **Laravel 12** (`laravel/framework ^12.0`), Tinker, Sanctum ^4.3 (tabel
  `personal_access_tokens` ada, tetapi **tidak dipakai** untuk otentikasi endpoint saat ini)
- **MySQL 8** untuk dev & produksi; **SQLite in-memory** untuk test (lihat `.env.testing`)
- **Frontend: Blade + Alpine.js 3.15 + Tabulator.js 6.4**, dibundel Vite 6 + Tailwind 4
  (`resources/js/app.js`, `resources/css/app.css`)
- **Import spreadsheet:** `openspout/openspout ^5.7` (pembacaan streaming, jalur utama) +
  `maatwebsite/excel ^3.1` (dipakai `app/Domain/Import/Support/RawWorkbookImport.php`) +
  PhpSpreadsheet (dibawa maatwebsite; dipakai untuk unduh template & beberapa pembacaan)
- **`barryvdh/laravel-dompdf ^3.1` terpasang tetapi TIDAK dipakai di mana pun** (sisa rencana
  export PDF di PRD)

**Pilihan non-obvious:**
- **Tabulator, bukan komponen tabel React/Vue** — laporan butuh grouped header banyak tingkat,
  kolom frozen, dan virtual scroll dengan ratusan baris; Tabulator memberi itu tanpa SPA.
- **JS halaman ditulis INLINE di dalam Blade** (`@push('scripts')`), bukan di `resources/js/`.
  Alasannya operasional: server produksi **tidak punya `node_modules`** (build Vite meng-OOM VPS
  lama), jadi setiap perubahan `resources/js/app.js` mengharuskan build lokal + `scp` bundel.
  Halaman ber-JS inline bisa di-deploy hanya dengan `git pull` + `view:clear`.
- **openspout** dipilih karena file WBS bisa 80.000 baris × 48 kolom (~158 MB XML terurai);
  PhpSpreadsheet penuh tidak kuat.

---

## 3. CARA MENJALANKAN

### Letak folder (PENTING)

Git repository root = folder yang memuat **dua** hal:

```
Project_LM/                 <- git root (remote origin ada di sini)
├── CONTEXT.md              <- file ini
├── CLAUDE.md               <- aturan kerja tingkat repo
├── docs/                   <- PRD, prompt tahapan, workbook Excel acuan (.xlsx)
└── lm-reporting/           <- APLIKASI LARAVEL ADA DI SINI (artisan, composer.json)
    └── CLAUDE.md           <- aturan kerja tingkat aplikasi
```

Semua perintah `php artisan`/`composer`/`npm` dijalankan dari **`lm-reporting/`**, bukan dari
git root. Di server strukturnya identik: git root `/var/www/lm-reporting`, aplikasi di
`/var/www/lm-reporting/lm-reporting`.

### Setup dari nol

```bash
git clone <remote> Project_LM
cd Project_LM/lm-reporting
composer install
npm install
cp .env.example .env
php artisan key:generate
# isi kredensial DB di .env, buat database kosong lebih dulu
php artisan migrate
php artisan db:seed          # role, 3 user contoh, master unit, template baris, account map
npm run build                # wajib sekali agar public/build/ ada
php artisan serve            # atau nginx/apache ke public/
```

**Env yang wajib terisi** (nama saja — jangan pernah menuliskan nilainya ke file yang ikut
commit): `APP_KEY`, `APP_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`, `QUEUE_CONNECTION` (produksi = `database`), `SESSION_DRIVER`.

`db:seed` membuat user contoh `viewer@lm.test`, `operator@lm.test`, `admin@lm.test`
(lihat `database/seeders/DatabaseSeeder.php`) — **hanya untuk lokal/dev**.

### Antrean (queue)

Impor berjalan **asinkron**. Di lokal jalankan `php artisan queue:work`
(atau `composer dev` yang menyalakan serve + queue + vite + pail sekaligus).
Di server ada systemd unit `lm-reporting-worker`.
⚠️ **Setiap deploy yang mengubah Job/Service/Model wajib `systemctl restart lm-reporting-worker`**
— worker memegang kode lama di memori.

### Test

```bash
php artisan test                                   # seluruh suite
php artisan test --filter="PersediaanPenyesuaianTest|PersediaanApiTest"
```

Test memakai `.env.testing` → **SQLite `:memory:`**, jadi aman terhadap database dev.
Konsekuensinya: query khas MySQL (`LEFT()`, `SUBSTR`, fungsi tanggal MySQL) bisa lulus di
MySQL tapi gagal di test — pola yang dipakai di repo ini adalah **memotong string di PHP,
bukan di SQL** (contoh `PersediaanController::penjualanPerPlant()`).

Kondisi terakhir yang diukur (2026-08-12): **142 lulus, 3 gagal** — ketiganya usang, lihat §10.

### Build aset frontend

Hanya perlu bila mengubah `resources/js/app.js` atau `resources/css/app.css`:
`npm run build` **di lokal**, lalu salin `public/build/manifest.json` + `public/build/assets/*`
ke server dan hapus berkas hash lama. ⚠️ Kelas Tailwind **baru** di Blade juga menuntut rebuild
(Tailwind hanya meng-compile kelas yang terlihat saat build).

---

## 4. PETA KODE

```
lm-reporting/
├── routes/
│   ├── web.php          (133 baris) SEMUA rute halaman + /report-data/* + /api/report/*
│   ├── api.php          (18)  hanya /api/units & /api/batches (sengaja tanpa auth)
│   └── console.php      (991) 13 perintah artisan impor/generate — lihat daftar di bawah
├── app/
│   ├── Domain/Report/   MESIN HITUNG
│   │   ├── Lm14Service.php          (347) biaya kebun per baris template
│   │   ├── Lm13Service.php          (401) laporan biaya per satuan
│   │   ├── Lm16Service.php          (522) biaya pabrik (PKS)
│   │   ├── InvestasiService.php     (613) LM Investasi TBM
│   │   ├── ProduksiCpoIntiService.php (151)
│   │   └── ReportGenerateService.php (85)  orkestrator per batch
│   ├── Domain/Import/
│   │   ├── SpreadsheetImportService.php (2823) ★ SEMUA logika impor, satu kelas
│   │   ├── ImportTemplateService.php     (332) unduh template .xlsx per jenis
│   │   └── Support/RawWorkbookImport.php
│   ├── Domain/Admin/DataPurgeService.php  hapus data per target/periode
│   ├── Http/Controllers/
│   │   ├── Api/ReportController.php  (2998) ★ LM13/14/16 + drill-down + presentasi
│   │   ├── Api/*.php                 satu controller per halaman data
│   │   ├── Api/Concerns/AuthorizesReportRequests.php  auth endpoint report-data
│   │   ├── Import/ImportController.php  alur unggah → pratinjau → konfirmasi
│   │   ├── Admin/{DataPurgeController,DatabaseViewerController}.php
│   │   ├── BebanUsahaController.php     halaman beban usaha + tab PROPORSI (input manual)
│   │   └── PersediaanPenyesuaianController.php  tab PENYESUAIAN (input manual)
│   ├── Jobs/{ProcessImport,RegenerateReports}.php
│   ├── Models/  (24 model, sebagian besar tanpa timestamps, `$guarded = []`)
│   └── Http/Middleware/EnsureUserHasRole.php   alias `role:`
├── resources/views/
│   ├── layouts/app.blade.php     sidebar + tema hijau + helper JS global (lmToast dll.)
│   ├── laba-rugi/*.blade.php     halaman laba rugi (JS INLINE di sini)
│   ├── kebun|pabrik|produksi|areal|import|admin/…
│   └── laba-rugi/_drill-popup.blade.php  popup drill-down 2 tahap (dipakai ulang)
├── database/
│   ├── migrations/   36 berkas, penamaan tanggal `2026_MM_DD_HHMMSS_*`
│   └── seeders/sql/  schema_mysql.sql, seed_lm_template_row.sql (594 baris struktur laporan),
│                     seed_lm16_account_map.sql (peta kode SAP → baris LM16)
└── tests/Feature/    48 berkas — satu per fitur/endpoint
```

**Mudah salah cari:**
- Logika tampilan laporan **tidak** semuanya di service. Banyak kolom (LM13 produksi/luas/per-Ha,
  capaian % LM16) dihitung **saat dibaca** di `Api/ReportController.php`, bukan saat generate.
  Kalau angka di layar tidak cocok dengan isi tabel `report_lm1x`, cari di controller itu.
- Perintah artisan tidak di `app/Console/Commands`, tapi **ditulis inline di `routes/console.php`**:
  `lm:import-raw`, `report:generate`, `budget:import-test`, `lm:tahunlalu-wbs`, `lm:tahunlalu-ohc`,
  `alokasi:import-areal`, `alokasi:import-produksi`, `produksi:import`, `produksi-kebun:import`,
  `produksi:cpo-inti`, `pembelian-tbs:import`, `penjualan-produk:import`, `rbb:pivot`.
- Konfigurasi rute halaman kebanyakan `Route::view(...)` — halaman mengambil data sendiri lewat
  `fetch('/report-data/...')` dari JS inline.

---

## 5. MODEL DATA

Tiga lapis: **master** → **mentah (raw)** → **hasil hitung (report)**, plus tabel-tabel
"halaman baru" yang berdiri sendiri.

### Master (`2026_06_09_092000_create_lm_master_tables.php`)

| Tabel | Isi & field bermakna khusus |
|---|---|
| `ref_unit` | unit kerja. `code` (5E01, 5F08, 5R00…), `type` enum **KEBUN\|PABRIK**, `komoditi` enum **KS\|KR** (KS=Kelapa Sawit, KR=Karet), `olah_status` enum **Olah\|Non Olah** → menentukan kolom Olah vs KSO di LM16, `profit_center` |
| `ref_unit_komoditi` | satu unit bisa punya 2 komoditi (LM14/LM13 digenerate per komoditi) |
| `batch` | **satu batch = satu periode (year, month)**, unik. `status` enum **draft\|final\|locked**, `processed_at`, `needs_regenerate` (bool) |
| `lm_template_row` | ★ **struktur baris semua laporan** (594 baris di seeder). `report_type` LM14\|LM13\|LM16, `komoditi`, `urutan` (nomor baris — dipakai formula), `kode` (kode aktivitas/CC SAP), `row_type` **header\|detail\|subtotal\|total**, `source` **WBS\|BTL\|PKS\|CALC**, `formula` string gaya `u12+u13+u14` yang merujuk `urutan` baris lain, `indent` |
| `lm16_account_map` | peta kode SAP → baris LM16. `match_type` **cost_center\|cost_element** |
| `ref_klasifikasi` | kode klasifikasi biaya |

### Hasil hitung (`2026_06_09_092300_create_lm_report_tables.php`)

- `report_lm14` — kunci (`batch_id`,`unit_id`,`komoditi`,`template_id`); kolom nilai
  `real_bulan_ini/real_bulan_lalu/real_tahun_lalu/rko/rkap/real_sd_bulan_ini/rko_sd/rkap_sd`
  + kolom capaian `cap_*` (persen, 2 desimal).
- `report_lm13` — sama + kolom `blok` enum **OLAH_JUAL\|OLAH\|JUAL** (blok laporan, bukan blok kebun).
- `report_lm16` — kolom berpasangan `bi_*` (bulan ini) dan `sd_*` (s.d bulan ini), masing-masing
  `olah`, `kso`, `jumlah` (**jumlah = olah + kso**), plus `rp_kg_tbs`, `rp_kg_mi`.

Selain itu `rbb_pivot` — agregat halaman RBB yang dibangun ulang dari `rbb_gl`
(lihat §7 no.16).

### Mentah / sumber

`db_wbs_raw` (+`db_wbs_tahun_lalu`), `db_ohc`, `db_gc`, `db_wbs`, `db_btl`, `db_pks`,
`pks_biaya`, `pks_produksi`, `budget_rko`, `budget_rkap`, `budget_source` (`jenis` = rko|rkap),
`realisasi_tahun_lalu`, `areal_blok`, `alokasi_areal`, `alokasi_produksi`, `produksi_pks`,
`produksi_kebun_wb`, `produksi_cpo_inti`, `pembelian_tbs`, `penjualan_produk`, `beban_usaha_gl`,
`persediaan_nilai`, `investasi_wbs`, `investasi_asset`, `rbb_gl` (±240 rb baris/bulan).

### Input manual (tidak ada di SAP)

- `beban_usaha_proporsi` — persentase pembagian beban Sawit/Karet (tab PROPORSI).
- `persediaan_penyesuaian` — tab PENYESUAIAN halaman Persediaan. Kolom nilai:
  `transfer_masuk`, `transfer_keluar`, `susut`, `sto_gr`, `sto_gi` (**satuan Kg**) dan
  `nilai_akhir` (**satuan Rupiah**, ditambahkan 2026-08-12).
  Nilai magic pada `plant_code`/`product`: string **`'Penyesuaian Nilai Akhir'`** menandai baris
  khusus yang mengisi baris "Penyesuaian atas nilai persediaan akhir" (dikumpulkan ke kunci
  `_penyes` oleh `Api\PersediaanController::penyesuaian()`).
  Kode plant **`5R00-1`** = IPP Tayan, **`5R00-2`** = Tanah Merah — dua unit berbagi satu kode
  SAP `5R00`, dipecah **hanya di tabel ini** (lihat §7).

### Operasional

`import_jobs` (status antrean impor), `import_upload_logs` (riwayat + error), `users`/`roles`
(`Role::VIEWER|OPERATOR|ADMIN`).

---

## 6. ALUR UTAMA

### A. Impor berkas SAP (paling sering dipakai)

1. `GET /import` → `Import\ImportController@index`, form pilih **jenis** (daftar di
   `SpreadsheetImportService::types()`), tahun, bulan, berkas.
2. `POST /import` → `store()`: berkas disimpan ke `storage/app/private/import-staging/{uuid}.xlsx`,
   lalu **pratinjau** (`preview()` + `detectPeriods()`) ditampilkan. Belum masuk database.
3. `POST /import/confirm` → membuat baris `import_jobs` dan `dispatch(ProcessImport)`; respons
   **202 + status_url**, halaman melakukan polling ke `GET /import/status/{importJob}`.
4. `Jobs\ProcessImport@handle` memanggil `SpreadsheetImportService` sesuai jenis → menulis ke tabel
   mentah (**pola hapus-ganti per periode/sumber, idempoten**) → menghapus berkas staging →
   `RegenerateReports::dispatch($affectedBatchIds)`.
5. `Jobs\RegenerateReports` → `ReportGenerateService::generateBatch()` untuk tiap batch.

Jenis impor terbagi tiga penjaga periode berbeda (`usesMonthGuard()`): realisasi (batch),
anggaran (tahun), dan berkas tanpa kolom periode seperti ZSTOCK (bulan+tahun pilihan user
**menjadi** periodenya).

### B. Generate laporan

`ReportGenerateService::generateBatch(Batch)`:
1. Untuk tiap `ref_unit` type=KEBUN × tiap komoditi → `Lm14Service::generate()` lalu `Lm13Service::generate()`.
2. Untuk tiap `ref_unit` type=PABRIK → `Lm16Service::generate()`.
3. `ProduksiCpoIntiService::generate(year, month)`.
4. Set `batch.processed_at = now()`, `needs_regenerate = false`.

Di dalam `Lm14Service::generate()`: baca `lm_template_row` (urut `urutan`) → baris `detail`
dijumlah dari tabel sumber sesuai `source`/`kode`; baris `subtotal`/`total` **tidak** menghitung
ulang dari data mentah melainkan menjumlah baris lain sesuai `formula` (`u12+u13`) → hasil
dihapus-dan-diinsert ulang per (batch, unit, komoditi) dalam satu transaksi.

Manual: tombol "Proses Laporan" (`POST /proses-laporan`) atau
`php artisan report:generate --type=LM14 --batch=9`.

### C. Halaman laporan membaca data

Blade (mis. `resources/views/laba-rugi/persediaan.blade.php`) berisi komponen Alpine yang
`fetch('/report-data/laba-rugi/persediaan?year=&month=')` → controller di `app/Http/Controllers/Api/`
→ JSON → dirender Tabulator dengan kolom yang didefinisikan di JS inline.
Filter bulan/tahun memanggil ulang `load()`; saat `init()` halaman memanggil `load(true)` yang
**mengadopsi periode data terbaru** dan **mengabaikan query string URL** (jebakan saat pengujian).

### D. Input manual (tab PROPORSI & PENYESUAIAN)

Tabel Tabulator dengan editor sel. Setiap `cellEdited` → `row.update()` (**jangan** menulis
langsung ke objek data Alpine — objeknya reaktif dan akan memicu render ulang di tengah edit) →
`POST` ke endpoint simpan → sukses memicu pemuatan ulang nilai periode terkait.

### E. Drill-down

Klik sel angka → popup 2 tahap: pivot (`/report-data/laba-rugi/drilldown` atau
`/report-data/drilldown`) lalu detail baris posting (`…/drilldown-deep`).
Blade bersama: `resources/views/laba-rugi/_drill-popup.blade.php`.

---

## 7. KEPUTUSAN ARSITEKTUR & ALASANNYA

**1. Nilai laporan dimaterialisasi ke `report_lm14/13/16`, bukan dihitung saat request.**
Ditolak: view SQL / hitung on-the-fly. Alasan: satu batch = ratusan baris × puluhan unit ×
sumber puluhan ribu baris; hitung saat request tidak sanggup.
*Konsekuensi:* menghapus/menambah data sumber **tidak langsung terlihat** sampai regenerate —
itulah sebabnya ada `batch.needs_regenerate` dan auto-dispatch `RegenerateReports`.

**2. Subtotal & total mengikuti `lm_template_row.formula`, bukan agregasi ulang.**
Ditolak: `SUM` per kelompok. Alasan: Excel acuan punya subtotal yang tidak persis sama dengan
jumlah baris di bawahnya (baris dilewati/dobel disengaja). Formula `u{n}+u{n}` menyalin perilaku
Excel apa adanya. *Konsekuensi:* mengubah `urutan` baris template = merusak semua formula.

**3. Struktur baris laporan disimpan sebagai DATA (seed 594 baris), bukan kode.**
Alasan: layout laporan berubah menurut kebijakan perusahaan, bukan menurut rilis aplikasi.
*Konsekuensi:* penambahan baris laporan = migrasi/seed, bukan `if` di service.

**4. JS halaman inline di Blade (lihat §2).** *Konsekuensi:* ada duplikasi helper format angka
antara `resources/js/app.js` dan tiap halaman. Disengaja — harga yang dibayar agar deploy murah.

**5. Kolom yang belum punya sumber ditampilkan `-`, bukan disembunyikan.**
Alasan: tabel wajib identik dengan Excel; kolom yang hilang membuat pengguna kehilangan orientasi.

**6. Endpoint `/report-data/*` berada DI LUAR middleware `auth` di `routes/web.php`,**
perlindungan dilakukan di controller lewat trait `AuthorizesReportRequests`
(sesi web, atau fallback header `X-LM-Report-User` + `X-LM-Report-Token` HMAC dari `APP_KEY`).
Ke-13 controller `report-data` sudah memanggil `authenticateReportRequest()`.
*Konsekuensi:* controller baru di grup itu **wajib** memanggilnya, kalau tidak endpoint terbuka.
`/api/units` & `/api/batches` sengaja terbuka (dropdown).

**7. Batasan Viewer di level data, bukan hanya menu:** `checkBatchAccess()` menolak Viewer
membaca batch berstatus `draft` (hanya `final`/`locked`).

**8. Kode plant `5R00` dipecah `5R00-1`/`5R00-2` hanya di `persediaan_penyesuaian`.**
Ditolak: memecah `ref_unit`/mengubah kode SAP (akan merusak pencocokan produksi/penjualan/ZSTOCK
yang memang memakai `5R00`). Alasan: dua unit kerja berbagi satu kode, sehingga isian manual
selalu jatuh ke baris pertama. Pemetaan ada di array `units` pada blade Persediaan (elemen ke-3
= kode form) dan `PersediaanPenyesuaianController::PLANT_LABELS`.

**9. Kunci unik (year, month, plant, product) di `persediaan_penyesuaian` DILEPAS** (migrasi
`2026_08_12_100000`). Alasan: pengguna butuh beberapa baris per plant/periode (baris transfer
terpisah dari baris susut); nilai dijumlahkan saat dibaca.
**Trade-off yang disadari:** entri kembar tidak sengaja kini akan **dihitung dua kali** tanpa
peringatan. Jangan pasang kembali kunci itu tanpa menyediakan cara lain menampung baris ganda.

**10. Beberapa kolom laporan diisi di lapisan presentasi (`Api\ReportController`), bukan mesin
hitung** — mis. LM13 Luas Area TM, blok produksi, baris "per Ha", capaian % LM16.
Alasan: menghindari regenerasi ulang seluruh batch untuk perubahan tampilan.
*Konsekuensi:* dua tempat harus dicek saat menelusuri angka; `report_lm13` sengaja menyimpan 0
pada baris-baris itu.

**11. Impor bersifat idempoten hapus-ganti per (periode, sumber)**, bukan append.
*Konsekuensi:* mengunggah ulang berkas yang sama aman; tetapi baris lama dengan `source = NULL`
(data sebelum kolom `source` ada) tidak ikut terhapus dan bisa menyebabkan angka dobel.

**12. RKO dan RKAP diimpor terpisah** (`rko_*` → `budget_rko`, `rkap_*` → `budget_rkap`).
Sebelumnya satu berkas mengisi keduanya sehingga RKO selalu = RKAP.

**13. Angka & struktur yang dipakai lebih dari satu halaman disimpan di kelas PHP, bukan
konstanta JavaScript di blade** — `app/Domain/Report/PersediaanAwalTahun.php` (saldo PERSEDIAAN
AWAL TAHUN) dan `app/Domain/Report/PersediaanStruktur.php` (daftar produk & unit tiap tab),
keduanya dipakai halaman Persediaan **dan** baris "Persediaan Awal"/"Persediaan Akhir" LM-27A.
*Alternatif yang ditolak:* menyalin angkanya ke dua tempat (dulu memang begitu, dan keduanya
harus disamakan manual setiap kali user mengoreksi).
*Konsekuensi:* halaman Persediaan menerima keduanya sebagai argumen
`persediaanApp(@js($psdPenyesuaian), @js($psdAwalTahun), @js($psdStruktur))` — kalau menambah
data serupa yang dipakai dua halaman, ikuti pola ini. Nama unit & label produk di
`PersediaanAwalTahun` WAJIB sama persis dengan `PersediaanStruktur`; kalau meleset, nilainya
tetap masuk `jumlahRp()` (LM-27A) tetapi hilang dari baris rincian → dua halaman berselisih
tanpa peringatan.

**14. Baris total LM-27A hanya dihitung bila SELURUH komponennya bersumber** — `values.hpp_kolom`
dari `Api\Lm27aController` menyebut kolom budidaya mana yang boleh dijumlahkan; kolom lain
dirender `-`. *Alasan:* menjumlahkan blok yang separuh baris­nya belum ada sumber menghasilkan
total yang terlihat sah tetapi salah (pernah terjadi: periode tanpa batch LM13 menampilkan Laba
Kotor 1,13 T). *Konsekuensi:* setiap kali sebuah baris komponen mendapat sumber baru, perbarui
juga daftar itu.

**15. Kolom klasifikasi RBB DISIMPAN APA ADANYA dari berkas, tidak dihitung ulang** — sheet
"Data" workbook RBB punya empat kolom bantu (`Klasifikasi`, `Klasifikasi 2`, `Jenis Beban`,
`Segmen`) yang di Excel berupa rumus VLOOKUP berjenjang ke sheet "Lock" (peta 1.860 cost center,
peta account, peta khusus stasiun pabrik, prefix WBS, plus aturan Profit Center `5F` → Pabrik,
WBS `PC` → Investasi, Account `55` → Variance). Importer menyalin hasilnya, bukan menirukan
rumusnya. *Alasan:* menirukan rantai VLOOKUP itu di PHP berarti menebak-nebak pemetaan yang
pemiliknya bisa ubah kapan saja di workbook, dan setiap selisih kecil langsung merusak angka
laporan; menyalin hasil membuat halaman dijamin sama dengan pivot acuan (terbukti selisih 0 untuk
seluruh 241.643 baris Januari 2026). *Konsekuensi:* berkas yang diunggah HARUS berisi keempat
kolom itu — ekspor SAP mentah tanpa kolom bantu akan menghasilkan baris tanpa klasifikasi.
Kalau suatu saat pemetaan perlu dihitung sendiri, sheet "Lock" itulah sumbernya.

**16. Halaman RBB membaca `rbb_pivot`, bukan `rbb_gl`** — `RbbPivotService` memateri­alisasi
agregat terdalam (Klasifikasi 2 × Jenis Beban × Account × Klasifikasi × Segmen) setiap kali impor
selesai. *Alasan:* `SUM`+`GROUP BY` langsung atas ±240 rb baris per bulan memakan ±2 detik tiap
muat halaman, sedangkan hasilnya hanya ratusan baris. *Konsekuensi:* kalau `rbb_gl` diubah di
luar importer (mis. hapus manual), jalankan `php artisan rbb:pivot` supaya halaman ikut berubah.
Subtotal tingkat 1 & 2 sengaja TIDAK disimpan — dijumlahkan dari agregat ini di controller,
supaya total tidak mungkin berbeda dari rinciannya.

---

## 8. JANGAN DIUBAH (terlihat aneh, tetapi disengaja)

1. **`Lm14Service::STAF_GAJI_KODE = '99-01'` mengambil dari `db_ohc` lock `SP01`/`SR01`, bukan
   dari `db_wbs_raw`** — `app/Domain/Report/Lm14Service.php:12-31`.
   Aktivitas 99-01 di WBS mencampur beberapa klasifikasi sehingga menghasilkan grand-total
   (2.366.107.451) alih-alih gaji staf (217.189.151). Docblock di file itu memuat alasannya.
2. **`detectPeriods()` hanya memindai 2000 baris pertama** —
   `app/Domain/Import/SpreadsheetImportService.php` (`PERIOD_SCAN_ROWS`).
   Sapuan penuh membuat `POST /import` menembus `max_execution_time` (pernah 504 pada berkas
   16 MB). Asumsi domain: 1 berkas = 1 bulan, dan bulan masih bisa diubah pengguna.
3. **`memory_limit` server harus bernilai TERBATAS (mis. 1024M/2048M), bukan `-1`.**
   openspout memilih strategi cache berbasis berkas (sangat lambat) bila limit tak terbaca —
   `-1` justru memicu jalur lambat itu.
4. **Pemotongan 4 digit `profit_center` dilakukan di PHP, bukan `LEFT()`/`SUBSTR` di SQL** —
   `Api/PersediaanController::penjualanPerPlant()`. SQLite (test) tidak punya `LEFT`.
5. **Emulasi colspan memakai `table.getColumn(f).getWidth()`, bukan `offsetWidth` sel** —
   `resources/views/laba-rugi/persediaan.blade.php` (`rowFormatter`). Virtual DOM Tabulator
   memanggil formatter berulang; sel yang sudah `display:none` punya `offsetWidth` 0 → label
   terpotong.
6. **CSS `white-space: normal` untuk judul kolom ditulis dengan selektor 6 kelas** (dan beberapa
   judul memakai `<br>`) — spesifisitas bawaan Tabulator lebih tinggi; kalau "dirapikan" jadi
   selektor pendek, grup header melebar melebihi jumlah anaknya dan muncul celah gelap.
7. **Sentinel persen `100,01` berwarna merah** saat anggaran 0 tetapi realisasi > 0 —
   `resources/js/app.js:147-183`. Ini konvensi tampilan yang diminta pengguna, bukan bug
   pembulatan.
8. **Baris pemisah antarproduk menampilkan `-` hanya pada kolom "Persediaan per …"**
   (flag `_dash`) — artefak formula Excel yang direplikasi sengaja.
9. **Persentase LM-27A memakai basis "Jumlah baris itu", berbeda dari file Excel acuan** —
   keputusan pengguna 2026-08-10. Jangan "dikembalikan" ke pola Excel.
10. **Konstanta `awalTahun` (Persediaan) & angka penyesuaian nilai (+5.110.916.401 sawit /
    −2.293.498.392 karet) ditulis manual di Blade**, bukan dari database. Angka itu sudah
    dicocokkan dengan baris "Persediaan Awal" LM-27A. Mengubahnya = mengubah laporan.
11. **`spacerDash`, urutan `units`, dan label produk berawalan `- `** mengikuti workbook; fungsi
    `norm()` di server & klien membuang awalan `-` saat mencocokkan. Jangan menghapus awalan itu
    dari label.
12. **Judul tab Karet tanpa kata "KELAPA"** meski sheet aslinya "KELAPA KARET" (permintaan
    pengguna).

---

## 9. KONVENSI

**Bahasa:** UI, pesan, dan komentar domain dalam **Bahasa Indonesia**; nama kelas/fungsi/variabel
dalam **English**. Komentar menjelaskan *kenapa* (aturan domain/keputusan pengguna), bukan *apa*.

**Git:**
- Commit **kecil & sering**, satu commit = satu perubahan logis.
- **Pesan commit Bahasa Indonesia** dengan awalan tipe: `feat(persediaan): …`, `fix(import): …`.
- **`git add` per berkas** — dilarang `git add .` / `git add -A` (repo memuat banyak berkas
  kerja yang tidak boleh ikut: `docs/*.xlsx` besar, `.claude/`, `check_data.php`, dll.).
- Branch kerja: `main` (langsung), remote `origin`.

**Format respons API:** JSON polos tanpa pembungkus, kunci `snake_case`, plus metadata periode
(`periods`, `year`, `month`) pada endpoint yang punya filter. Nilai kosong dikirim `null`/`0` dan
diformat menjadi `-` di klien, **bukan** string kosong.

**Error handling:**
- Validasi lewat `$request->validate()` → 422 otomatis; pesan divalidasi dengan daftar `in:`
  yang diambil dari konstanta controller (contoh `PLANTS`, `PRODUCTS`).
- Impor tidak melempar exception ke pengguna: kesalahan per baris dikumpulkan di `ImportResult`
  dan ditampilkan di Riwayat Upload.
- Di klien, kegagalan `fetch` memanggil `window.lmToast(pesan, 'err')` dan **baris tetap
  ditampilkan** agar ketikan pengguna tidak hilang.

**Angka:** rupiah/kg tanpa desimal, format `id-ID`, negatif dalam kurung gaya akuntansi pada
halaman yang meniru template Excel; persentase 2 desimal. Semua pembagian memakai pola aman
(penyebut 0 → 0 → tampil `-`).

**Penamaan migrasi:** `YYYY_MM_DD_HHMMSS_<verb>_<objek>.php`, satu migrasi satu tujuan.
Migrasi yang mengubah data (bukan skema) tetap harus punya `down()` yang membalik.

**PowerShell (lingkungan pemilik project, Windows):** hindari tanda kutip ganda di argumen
perintah native (mis. pesan commit) — pakai here-string `@'…'@` dengan penutup di kolom 0.

---

## 10. UTANG TEKNIS & MASALAH YANG DIKETAHUI

**Test yang gagal (3, semuanya usang — bukan regresi baru):**
- `ExampleTest` & `AuthenticationRolesTest` masih mengharapkan redirect ke `/kebun`, padahal
  `routes/web.php:9` sekarang mengarahkan `/` ke `/laba-rugi/penjualan`.
- `ProduksiKebunApiTest:70` mengharapkan kunci `short_plant` di respons
  `/report-data/produksi/kebun`; `Api\ProduksiKebunController` sudah tidak mengirimnya
  (kunci itu kini hanya ada di importer). Tab "Pembelian" dipindah ke halaman tersendiri.

**Masalah data / hitungan yang tertunda (menunggu keputusan pemilik project):**
- **Skala RKO/RKAP ×1000.** Aturan domain menyebut anggaran disimpan sudah ×1000, tetapi
  `importBudget` tidak mengalikan, sehingga % RKO/RKAP LM14 bisa puluhan ribu persen.
  Pemilik project memilih **jangan diubah dulu**.
- **Kode RKAP Karet tidak cocok template LM14 Karet** (file memakai 35/42/98-xx, template
  memakai 52/AU-xx) → ~138 baris dilewati saat impor, nilai terlewat ~Rp 1.008.915.
- **Selisih ZSTOCK vs LM-27A** pada Persediaan Akhir Juni (~1,78 M sawit / 1,72 M karet) belum
  ditelusuri.
- **Alokasi Biaya Olah**: nilai biaya masih placeholder.
- Baris ZSTOCK yang tidak punya baris di halaman (mis. `5E13` Kebun Batulicin) **diabaikan diam-diam**.

**Kerapuhan lain:**
- `SpreadsheetImportService` 2.823 baris dan `Api\ReportController` 2.998 baris — keduanya sudah
  terlalu besar; pemecahan tertunda karena risiko regresi angka.
- `storage/app/private/import-staging` tidak pernah dibersihkan otomatis (pernah 141 MB).
- Server lama (163) RAM 957 MB & disk pernah 78% penuh → impor berkas besar sebaiknya di 157.
- Tanpa kunci unik di `persediaan_penyesuaian`, entri ganda tidak terdeteksi (lihat §7 no.9).
- **LM13 Karet kemasukan angka pengolahan sawit.** `AlokasiBiayaOlahController::jlhPerKebunLm13()`
  tidak menerima parameter komoditi (sumbernya `produksi_cpo_inti` + pool LM16 = PKS/sawit),
  tetapi `ReportController::applyAlokasiOlahToLm13()` memakainya untuk komoditi KR juga.
  Akibatnya baris "Beban Langsung/Overhead/Penyusutan Pengolahan" tab Karet bernilai sama persis
  dengan tab Sawit. Bukti (server 157, Juli 2026): KR "Beban Penyusutan Overhead Pengolahan"
  38.875.653.655 = angka Sawit, padahal penyusutan kebun karetnya 2.211.421.100.
  Karena itu LM-27A baris "Penyusutan" kolom Karet sengaja dikosongkan
  (`Lm27aController::PENYUSUTAN_BUDIDAYA`). Perbaikan: pisahkan sumber alokasi per komoditi,
  baru isi kolom Karet.
- Export Excel/CSV/PDF yang dijanjikan PRD belum ada sama sekali (dompdf terpasang, tidak dipakai).
- Beberapa berkas kerja belum ter-commit / tidak di-*gitignore* (`check_data.php`,
  `check_all_tables.php`, `Logo PTPN 4.png`, folder `docs/` yang besar).

---

## 11. YANG SEDANG DIKERJAKAN

Pekerjaan terakhir (selesai & sudah di-deploy): **halaman RBB — Rincian PNL**.

Template: `docs/laba_rugi/rbb/Beban Pokok & Usaha Januari 2026.xlsb`, sheet **`Report I.`**
(perhatikan titiknya) — PivotTable atas sheet `Data` (Table1, 241.643 baris GL SAP, satu berkas
= satu bulan). Sheet lain di workbook itu: `Report` (ringkas PNL), `Report II` (pembelian TBS,
sudah ada padanannya di `/produksi/pembelian`), `Report III` (persediaan, padanannya
`/laba-rugi/persediaan`) — atas keputusan pemilik project **hanya Report I. yang dibuat**.

- `ba4abc9` — tabel mentah `rbb_gl` + jenis impor baru **`rbb_gl`** ("RBB — Rincian PNL (GL)").
  Kolom dipetakan lewat **nama header**, bukan posisi. Importer menerima `.xlsx` maupun **`.csv`**
  (pemisah kolom ditebak dari baris header; angka bergaya Indonesia "1.234.567" ikut terbaca) —
  berkas asli `.xlsb` tidak terbaca pustaka mana pun, jadi harus disimpan ulang lebih dulu.
- `ba8eca8` — agregat `rbb_pivot` + `RbbPivotService` + perintah `rbb:pivot`, `Api\RbbController`,
  halaman `resources/views/laba-rugi/rbb.blade.php`, tes `tests/Feature/RbbApiTest.php`.

Bentuk halaman mengikuti pivot acuan: baris 3 tingkat (Klasifikasi 2 → Jenis Beban → Account,
bisa dibuka/tutup, bawaannya rincian akun tertutup), kolom **dinamis** (blok Klasifikasi ×
sub-kolom Segmen — hanya yang ada datanya, tiap blok ditutup Total, lalu Grand Total), format
akuntansi (minus dalam kurung, nol jadi `-`, sel tanpa posting dibiarkan kosong).

- `028566d` — dropdown **Kategori** (Semua / Sawit / Karet / Pabrik / Regional, isinya diturunkan
  dari segmen yang ada datanya). Saringan berjalan di sisi klien: memilih satu segmen menyisakan
  satu sub-kolom per blok, blok & baris tanpa posting di segmen itu ikut hilang (seperti pivot
  yang difilter), kolom Total per blok disembunyikan karena jadi kembar, dan Grand Total
  **dihitung ulang** dari kolom yang tampil — bukan memakai `__grand` dari server.
  Januari 2026: Sawit (149.255.875.246), Karet 662.649.565, Pabrik 17.202.533.705,
  Regional 18.168.198.942 — jumlahnya persis Grand Total semua (113.222.493.034).

Angka Januari 2026 **cocok selisih 0** dengan workbook pada seluruh sel yang diperiksa —
Grand Total (113.222.493.034), Penjualan (193.747.725.081), Beban Pokok Penjualan 59.927.519.350,
Beban Usaha 21.023.475.715, Pendapatan Lain-lain (425.763.018), termasuk pecahan per Jenis Beban
× Segmen di blok `b. Overhead`. Data Januari 2026 sudah diimpor di kedua server.

Yang belum ada di halaman RBB: **kolom kumulatif "s.d bulan"** (pemilik project memilih bulan
berjalan saja dulu) dan **drill-down ke posting** seperti halaman laba-rugi lain.

Sebelumnya (juga selesai & ter-deploy): **blok Harga Pokok Penjualan sampai baris penutup LM-27A**
— seluruh baris total LM-27A kini berumus, yang tersisa mengisi baris komponennya (Ekspor,
Perubahan Nilai Wajar Aset Biologis, Order Produksi, Administrasi Kebun, Biaya Bunga, Pendapatan
Lain-Lain, Pajak Perseroan, Pajak Tangguhan, tiga baris Pendapatan Komprehensif Lain) serta
keputusan kolom Karet (§10).

**Tidak ada pekerjaan yang tergantung setengah jalan.** Langkah berikutnya menunggu arahan
pemilik project; kandidat terdekat ada di §12.

Halaman Persediaan sendiri masih menyisakan kolom kosong: seluruh kolom tab **KARET**
(produksi & penjualan belum punya sumber). Kolom `PERSEDIAAN AWAL TAHUN` tetap **nilai manual**,
hanya letaknya kini di PHP; nilainya milik tahun buku 2026 dan ditampilkan untuk tahun mana pun
(`PersediaanAwalTahun::TAHUN` masih penanda, belum jadi filter).

---

## 12. RENCANA KE DEPAN

**Prioritas yang dinyatakan pemilik project (2026-08-12): melengkapi LM-27A.**
Yang sudah terisi: blok Penjualan→Lokal (dari `penjualan_produk`, memakai pemetaan yang sama
dengan LM 34 lewat `Lm34Controller::detailKeysOf()`) dan **seluruh blok Harga Pokok Penjualan
kolom Kelapa Sawit** — Persediaan Awal & Persediaan Akhir dari halaman Persediaan, Biaya Produksi
& Penyusutan dari `ReportController::lm13Rows()`, ditutup dua baris total (Harga Pokok Penjualan,
Laba (Rugi) Kotor). Kolom Karet blok itu masih kosong (lihat §10). Blok **Biaya Usaha** juga
lengkap sampai baris "Laba (Rugi) Usaha" — "Biaya Penjualan" & "- Administrasi Kandir" dari
halaman Beban Penjualan & Beban Administrasi, "- Administrasi Kebun" masih `-` (dihitung 0 atas
konfirmasi pemilik project). Sisanya — Order Produksi, Biaya Bunga, Pendapatan/Biaya Lain-lain,
sampai Laba (Rugi) Komprehensif — masih `-`, termasuk baris totalnya (baris total sengaja
dibiarkan `-` selama komponennya belum lengkap — lihat §7 no.14).

**Seluruh baris total LM-27A sudah berumus** (sampai "Laba (Rugi) Komprehensif"); yang tersisa
adalah mengisi baris komponennya. Pola tanda yang sudah disepakati: **setiap baris biaya dikirim
negatif** dan dirender dalam kurung, sehingga semua baris total cukup penjumlahan — jangan
mengubahnya jadi pengurangan. Perkecualian: "Laba (Rugi) setelah Pajak" & "Laba (Rugi) setelah
Pajak Tangguhan" memang MENGURANGKAN baris pajaknya (nilai pajak diisi positif).

Baris komponen yang belum ada sumbernya (dihitung 0 di semua total): Ekspor, Perubahan Nilai
Wajar Aset Biologis, Order Produksi, "- Administrasi Kebun", Biaya Bunga, Pendapatan Lain-Lain,
Pajak Perseroan, Pajak Tangguhan, dan 3 baris Pendapatan Komprehensif Lain.

Pola yang sudah terbentuk untuk melengkapinya: setiap baris LM-27A ditarik dari **jalur hitung
halaman yang sudah ada** (bukan query mentah), supaya angkanya tidak bisa berbeda dari halaman
sumbernya. `lm13Rows()` adalah contohnya — pisahkan dulu jalur itu jadi metode publik bila perlu.
Saat mengerjakannya: **jangan** membuat sumber data baru sendiri — angka LM-27A harus berasal
dari halaman yang sudah ada (Beban Usaha, Persediaan, LM 34, LM13/LM16) supaya tidak muncul dua
versi kebenaran.

Kandidat berikutnya (belum diprioritaskan): export Excel/PDF, tab KARET & pabrik karet
(PKR 5F20), perhitungan nilai Alokasi Biaya Olah, pembersihan otomatis folder staging.

**Jangan menutup jalan:** pertahankan pola "satu tabel sumber per jenis berkas + satu controller
per halaman + template baris sebagai data". Menggabungkan tabel sumber atau memindahkan struktur
baris ke kode akan menyulitkan penambahan laporan berikutnya.

---

## 13. AKSES SERVER & ALUR GITHUB

### Repository

- Remote: `origin` → `https://github.com/avsmenma/Laporan_Management-MM-.git`
- Branch utama: **`main`** (pekerjaan langsung di `main`; belum memakai PR)
- Alur normal: `git add <berkas satu per satu>` → `git commit -m "<pesan Indonesia>"` →
  `git push origin main` → deploy ke **kedua** server.

### Server

Ada **dua** server dan **keduanya wajib di-deploy setiap update** (dikonfirmasi pemilik project):

| | Server baru | Server lama |
|---|---|---|
| IP:port | `157.245.55.180:8081` | `163.61.58.92:8081` |
| Spesifikasi | 4 vCPU / 8 GB (utamakan untuk impor besar) | ±1 core / 957 MB |
| Database | MySQL 8, **terpisah** dari server lain | MySQL, isi sendiri |

Keduanya punya database masing-masing — **tidak** saling replikasi.

- SSH: `ssh -i ~/.ssh/crypto_bot_vps root@<ip>` (kunci privat ada di mesin pemilik project;
  **jangan menuliskan kata sandi atau isi kunci ke berkas apa pun**).
- Letak aplikasi: `/var/www/lm-reporting/lm-reporting`
  (git root-nya adalah **induknya**, `/var/www/lm-reporting`).
- Web: nginx → php-fpm (server baru: php8.4-fpm), berkas site `/etc/nginx/sites-available/lm-reporting`.
- Worker: systemd `lm-reporting-worker`.

**Perintah deploy standar (jalankan di kedua server):**

```bash
cd /var/www/lm-reporting/lm-reporting
git pull origin main
php artisan migrate --force
php artisan config:clear && php artisan cache:clear
php artisan view:clear   && php artisan route:clear
# HANYA bila Job/Service/Model berubah:
systemctl restart lm-reporting-worker
```

Catatan penting:
- `git pull --ff-only` tanpa nama remote/branch **gagal** di server (branch lokal tidak punya
  upstream). Selalu `git pull origin main`.
- Bila mengubah `resources/js/app.js` / `resources/css/app.css` / menambah kelas Tailwind baru:
  build **di lokal**, `scp` isi `public/build/` (assets + `manifest.json`), hapus berkas hash
  lama, lalu `chown -R www-data:www-data public/build`. Server tidak punya `node_modules`.
- Perubahan hanya-Blade/PHP cukup `git pull` + cache clear.

---

## INSTRUKSI UNTUK AI BERIKUTNYA

1. **Baca CONTEXT.md ini sampai habis sebelum menyentuh kode**, lalu baca juga
   `CLAUDE.md` (root repo) dan `lm-reporting/CLAUDE.md`. Bila ada konflik, aturan di CLAUDE.md
   dan `docs/PRD_Sistem_Pelaporan_LM.md` menang; laporkan konfliknya ke pemilik project.
2. **Jangan mengubah apa pun yang tercantum di §8 JANGAN DIUBAH tanpa konfirmasi eksplisit.**
   Kalau menurut Anda salah satunya keliru, jelaskan dulu alasannya dan minta persetujuan —
   jangan "merapikan" lebih dulu.
3. **Data di server adalah data operasional nyata.** Dilarang menjalankan perintah destruktif
   (`migrate:fresh`, `db:wipe`, `DELETE` massal, `git reset --hard`, `rm -rf`) di mana pun tanpa
   izin eksplisit. Untuk pengujian di server, buat baris uji lalu hapus lagi, dan katakan apa
   yang Anda lakukan.
4. **Angka adalah gerbang kritis.** Perubahan pada LM14/LM13/LM16 dan halaman laba-rugi harus
   dibuktikan cocok dengan workbook acuan di `docs/` (selisih 0 untuk baris kunci) sebelum
   dinyatakan selesai. Jangan pernah "menyesuaikan" angka agar kelihatan cocok.
5. **Ikuti konvensi §9**: commit kecil berbahasa Indonesia, `git add` per berkas, UI/komentar
   Indonesia + identifier English, kolom tanpa sumber ditampilkan `-`.
6. **Uji sebelum melapor selesai**: `php artisan test --filter=<TestTerkait>` minimal, dan
   sebutkan hasil sebenarnya (termasuk bila ada yang gagal).
7. **Setiap update dideploy ke KEDUA server** (§13) — ini kebiasaan yang diminta pemilik project.
8. **Perbarui CONTEXT.md setiap ada keputusan arsitektur baru**, trade-off yang disadari, atau
   kode "aneh tapi disengaja" yang baru dibuat — tambahkan ke §7, §8, atau §10 pada commit yang
   sama dengan perubahan kodenya.
