# Desain: Import Web (periode otomatis) + Tombol "Proses Laporan"

- Tanggal: 2026-06-23
- Status: Draft untuk direview user
- Konteks: menyiapkan alur upload data ke tahap production agar user (Operator/Admin)
  tidak perlu menjalankan perintah CLI sama sekali.

---

## 1. Masalah yang diselesaikan

Saat ini, untuk mengisi angka di tabel laporan, dibutuhkan langkah-langkah manual lewat
terminal:

1. Import data mentah hanya tersedia untuk **WBS / OHC / GC** (lewat `/import`).
   **RKO/RKAP belum ada di web** — baru ada `budget:import-test` (CLI, baca 1 folder).
2. Pemilihan periode di import memakai **dropdown Batch** (gabungan tahun+bulan).
   User ingin: **Tahun manual** + **Bulan otomatis dibaca dari kolom periode di file**.
3. Setelah import, tabel laporan (`report_lm14/13/16`) **tidak otomatis terisi**.
   Harus jalankan `php artisan report:generate ...` secara manual di CLI.
   Di production ini tidak boleh — user tidak akan membuka terminal.

Tujuan: (a) tambah import RKO/RKAP di web, (b) periode otomatis dari file untuk realisasi,
(c) ganti regenerate manual dengan **tombol "Proses Laporan"** di UI.

---

## 2. Lingkup

**Termasuk:**
- Import web untuk **6 jenis**: realisasi (`wbs`, `ohc`, `gc`) + anggaran (`rko_bku`,
  `rko_ohc`, `rko_gc`).
- Deteksi periode otomatis dari file untuk jenis realisasi.
- Tombol "Proses Laporan" yang menjalankan generate semua report untuk satu periode.
- Refactor logika generate dari `routes/console.php` ke service agar dipakai web & CLI.
- Penanda status "perlu diproses ulang" pada batch.

**Tidak termasuk (fase berikutnya):**
- Pemetaan kode GC (AP/AR) ke baris LM14 — tetap tidak terpetakan (audit saja).
- Antrian/queue worker (proses dijalankan sinkron dulu; lihat §8 Risiko).
- PKR (pabrik karet).

---

## 3. Dua kelompok jenis import

### 3.1 Kelompok Realisasi (per bulan)

| Jenis | Tabel tujuan | Periode |
|---|---|---|
| `wbs` | `db_wbs_raw` | dibaca dari file |
| `ohc` | `db_ohc` | dibaca dari file |
| `gc`  | `db_gc`  | dibaca dari file |

- User memilih **Tahun (manual)**.
- **Bulan diisi otomatis**: saat preview, sistem membaca kolom periode setiap baris file,
  mengambil bulan distinct.
  - Tepat 1 bulan → set dropdown bulan ke bulan itu (boleh di-override user bila keliru).
  - >1 bulan terdeteksi → tampilkan peringatan ("file berisi banyak bulan: 4,5"); user
    harus konfirmasi/menolak. Asumsi domain: **1 file = 1 bulan**.
  - 0 bulan terdeteksi (kolom periode kosong/aneh) → minta user pilih bulan manual.
- Batch (year+month) **diturunkan** dari Tahun (manual) + Bulan (auto). Bila batch belum
  ada → dibuat otomatis (status `draft`), seperti yang dilakukan `lm:import-raw` di CLI.
- Idempotensi: tetap seperti sekarang — hapus data batch+tabel itu, lalu insert.

### 3.2 Kelompok Anggaran (per tahun)

| Jenis | Sumber kode | Tabel tujuan |
|---|---|---|
| `rko_bku` | Aktifitas → kode WBS (KEBUN) | `budget_rko` + `budget_rkap` + `budget_source` |
| `rko_ohc` | Kode CC (BT01..) → kode BTL (KEBUN; pabrik 5F dilewati) | `budget_rko` + `budget_rkap` + `budget_source` |
| `rko_gc`  | Kode GC (AP/AR) — **tak terpetakan LM14** | hanya `budget_source` (audit) + statistik |

- User memilih **Tahun saja** (tidak ada bulan — rincian per bulan ada di kolom Period
  dalam file, kolom D).
- Nilai disimpan **rupiah penuh** (tanpa ×1000), sesuai `budget:import-test`.
- Nilai disalin ke **`budget_rko` dan `budget_rkap`** (identik) — file sumber tidak
  memisahkan keduanya (satu kolom "Nilai").
- **Idempotensi diperbaiki (kunci perubahan):** hapus-lalu-insert difilter
  `year + report_type='LM14' + source` (BKU/OHC/GC), **bukan** hapus seluruh tahun.
  Dengan begitu upload `rko_bku` → `rko_ohc` → `rko_gc` satu per satu tidak saling menimpa.
  - Implikasi: kolom `source` harus tersimpan di `budget_rko`/`budget_rkap` agar bisa
    di-filter per-sumber saat hapus. Saat ini `source` hanya ada di `budget_source`.
    **Perlu migrasi**: tambah kolom `source` (nullable) ke `budget_rko` & `budget_rkap`.
    (Alternatif tanpa migrasi dibahas di §8.)

---

## 4. Arsitektur & komponen

### 4.1 Import (kelompok realisasi & anggaran)

- `SpreadsheetImportService::types()` diperluas jadi 6 jenis (dikelompokkan realisasi vs
  anggaran), dengan metadata: butuh-bulan? (realisasi=ya, anggaran=tidak).
- Tambah method `detectPeriods(stagedFile, type): int[]` untuk membaca bulan distinct dari
  kolom periode (jenis realisasi). Dipakai di langkah preview.
- Tambah handler import anggaran yang **memecah** logika `budget:import-test` per-file:
  `importBudget(stagedFile, year, source)` di mana `source ∈ {BKU,OHC,GC}`.
  Logika pemetaan kode (BKU/OHC/GC), parsing angka, dan `budget_source` dipindahkan dari
  `routes/console.php` ke service ini agar dipakai bersama oleh web & CLI.
- `routes/console.php` (`budget:import-test`) dirombak jadi pemanggil tipis ke service
  (tetap bisa baca folder, panggil `importBudget` per file). Tidak ada logika ganda.

### 4.2 Generate laporan (tombol "Proses Laporan")

- Buat `ReportGenerateService::generateBatch(Batch $batch): array` yang menjalankan
  materialisasi untuk **semua** kombinasi:
  - LM14 & LM13: semua unit KEBUN × komoditi yang relevan.
  - LM16: semua unit PABRIK.
  - Mengembalikan ringkasan (jumlah baris per report/unit) untuk ditampilkan ke user.
- Logika orkestrasi yang sekarang ada di `report:generate` (`routes/console.php`) dipindah
  ke service ini; command CLI jadi pemanggil tipis (web & CLI berbagi satu sumber).
- Controller baru: `ProsesLaporanController@store(year, month)` (role Operator/Admin) yang
  memuat batch, memanggil `generateBatch`, lalu menandai batch `last_generated_at` = now
  dan `needs_regenerate=false`, dan menampilkan ringkasan.

### 4.3 Penanda status batch

- Migrasi: tambah kolom ke tabel `batch`:
  - `last_generated_at` (nullable timestamp)
  - `needs_regenerate` (boolean, default true)
- Setiap import realisasi yang menyentuh batch → set `needs_regenerate=true`.
  Import anggaran (year-scoped) → set `needs_regenerate=true` untuk semua batch tahun itu.
- UI menampilkan badge: "Perlu diproses" (kuning) atau "Terakhir diproses: <waktu>" (hijau).

---

## 5. Perubahan UI

### 5.1 Halaman Import (`resources/views/import/index.blade.php`)
- Dropdown jenis import jadi 6 (2 grup: "Realisasi (per bulan)" & "Anggaran (per tahun)").
- Field periode adaptif (Alpine.js):
  - Jenis realisasi → tampil **Tahun** (input) + **Bulan** (dropdown, terisi otomatis
    setelah preview membaca file; ada catatan "terbaca dari file").
  - Jenis anggaran → tampil **Tahun** saja (bulan disembunyikan).
- Preview menampilkan bulan terdeteksi + peringatan bila >1 bulan.

### 5.2 Tombol "Proses Laporan"
- Diletakkan di halaman batch/laporan (mis. di `/batches` atau header tabel laporan).
- Konfirmasi ringan ("Proses laporan untuk Mei 2026?") → POST → tampilkan ringkasan hasil.

---

## 6. Alur uji "reset" (skenario user)

1. `/data` (Admin) → **Hapus Semua**, ketik `HAPUS`.
2. Halaman Import → pilih Tahun 2026 → upload **WBS** (bulan terbaca otomatis) → preview →
   konfirmasi. Ulangi untuk **OHC**, **GC**.
3. Pilih Tahun 2026 → upload anggaran **RKO BKU**, lalu **RKO OHC**, lalu **RKO GC**
   (tidak saling menimpa berkat idempotensi per-sumber).
4. Klik **Proses Laporan** untuk Mei 2026.
5. Buka tabel laporan → bandingkan angka dengan workbook acuan Mei 2026.

**Jawaban atas pertanyaan user:** di production, user **tidak** menjalankan regenerate di
CLI. Cukup klik **Proses Laporan** setelah semua file periode itu masuk.

---

## 7. Acceptance

- [ ] Import web menyediakan 6 jenis (3 realisasi + 3 anggaran).
- [ ] Untuk jenis realisasi, bulan terisi otomatis dari kolom periode file; file >1 bulan
      memunculkan peringatan sebelum konfirmasi.
- [ ] Untuk jenis anggaran, hanya tahun yang diminta; upload BKU→OHC→GC berurutan tidak
      saling menghapus (data ketiganya tetap ada di `budget_rko`/`budget_rkap`/`budget_source`).
- [ ] Tombol "Proses Laporan" mengisi `report_lm14/13/16` untuk seluruh unit satu periode
      tanpa CLI; menampilkan ringkasan jumlah baris.
- [ ] Setelah upload baru, batch ditandai "Perlu diproses"; setelah tombol ditekan,
      ditandai "Terakhir diproses: <waktu>".
- [ ] Logika import budget & generate tidak terduplikasi (CLI & web memanggil service yang sama).
- [ ] Skenario reset → upload 1/1 → proses → angka cocok dengan Excel acuan Mei 2026
      (baris kunci selisih 0).

---

## 8. Risiko & keputusan

1. **Migrasi `source` pada budget_rko/budget_rkap. — DISETUJUI (2026-06-23).**
   Tambah kolom `source` (nullable) ke `budget_rko` & `budget_rkap` agar idempotensi
   bisa hapus-per-sumber (`where year+report_type+source`). Alternatif rebuild-dari-
   budget_source ditolak demi kelurusan logika.
2. **Proses sinkron vs queue.** `generateBatch` untuk semua unit bisa makan waktu (puluhan
   unit). Fase ini: jalankan **sinkron** dengan indikator loading; bila terasa lambat di
   production, pindah ke queue job (di luar lingkup sekarang, dicatat).
3. **Override bulan manual.** Bila user meng-override bulan padahal file berisi bulan lain,
   data periode per-baris tetap dari file. Kita pakai bulan untuk konteks batch; perlu
   keputusan apakah memaksa periode baris = bulan batch. **Default: simpan periode apa
   adanya dari file**, batch hanya wadah; validasi memperingatkan bila tidak konsisten.

---

## 9. Berkas yang akan disentuh (perkiraan)

- `app/Domain/Import/SpreadsheetImportService.php` — tambah jenis anggaran, `detectPeriods`,
  `importBudget`.
- `app/Domain/Report/ReportGenerateService.php` — **baru** (orkestrasi generateBatch).
- `app/Http/Controllers/Import/ImportController.php` — dukung 6 jenis & periode auto.
- `app/Http/Controllers/Report/ProsesLaporanController.php` — **baru**.
- `resources/views/import/index.blade.php` — UI adaptif.
- View batch/laporan — tombol "Proses Laporan" + badge status.
- `routes/console.php` — `budget:import-test` & `report:generate` jadi pemanggil tipis.
- `database/migrations/...` — kolom `source` di budget_rko/rkap; kolom status di `batch`.
- `routes/web.php` — route proses-laporan.
