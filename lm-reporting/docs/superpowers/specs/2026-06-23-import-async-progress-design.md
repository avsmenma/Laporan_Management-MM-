# Desain: Import Async (queue) + Popup Progress & Notifikasi

- Tanggal: 2026-06-23
- Status: Disetujui user (verbal), siap direview tertulis
- Konteks: production. User biasa (Operator/Admin) TIDAK punya akses CLI — semua aksi
  harus lewat web. Saat ini aksi berat (import file besar, hapus data) hanya "loading lama"
  tanpa kejelasan, dan import file besar (~8-10 mnt) berisiko timeout di request web.

---

## 1. Masalah

1. **Import file besar** (WBS/OHC/GC realisasi ~30MB, ~200rb baris) butuh 8-10 menit di
   VPS 1-core. Dijalankan inline di request web → browser macet & berisiko timeout. CLI
   bukan opsi (hanya admin server yang punya akses).
2. **Tanpa umpan balik**: hapus data / pratinjau / import hanya menampilkan loading panjang
   tanpa status; user tidak tahu sedang jalan, berhasil, atau gagal.

## 2. Tujuan

- Import dijalankan di **latar belakang (queue worker)** → request web balas cepat; proses
  berjalan tanpa timeout; browser bebas.
- **Popup progress** untuk hapus / pratinjau / import; **notifikasi berhasil/gagal** saat
  tiap aksi selesai.

## 3. Lingkup

**Termasuk:**
- Import (konfirmasi) jadi asinkron via queue `database` + worker systemd.
- Tabel `import_jobs` untuk status + progres; endpoint polling status.
- Progress bar (baris X / total, %) untuk import; overlay sinkron untuk pratinjau & hapus;
  komponen toast notifikasi berhasil/gagal global.

**Tidak termasuk (fase lain):**
- Hapus data dibuat asinkron (tetap sinkron + overlay + toast; biasanya cepat).
- Upload chunked / resume upload (upload tetap satu request; limit sudah dinaikkan 128M).
- Multi-worker / Redis (cukup 1 worker driver database).

## 4. Arsitektur

### 4.1 Bagian A — Import asinkron

**Konfigurasi**
- `.env`: `QUEUE_CONNECTION=database` (server & lokal). Tabel `jobs`/`failed_jobs` sudah ada.

**Tabel `import_jobs` (migrasi baru)**
| kolom | tipe | ket |
|---|---|---|
| id | bigint PK | |
| user_id | bigint nullable | pengunggah |
| type | string | wbs/ohc/gc/rko_bku/rko_ohc/rko_gc |
| year | smallint | |
| month | tinyint nullable | null utk anggaran |
| filename | string | nama asli |
| staged_path | string | path relatif file staging |
| ext | string(8) | xlsx/xls/csv |
| status | enum('queued','processing','done','failed') default 'queued' | |
| total | int default 0 | jumlah baris data (deteksi cepat) |
| processed | int default 0 | baris terproses (diperbarui per chunk) |
| row_count | int default 0 | baris tersimpan akhir |
| error | text nullable | pesan gagal (truncated) |
| created_at / updated_at | timestamp | |

**Job `ProcessImport` (queued)**
- Konstruktor: `import_jobs.id`.
- `handle()`: set status `processing`; panggil layanan import dengan **callback progres**
  `fn(int $processed)` yang meng-update `import_jobs.processed` (throttle: tiap chunk 500
  baris). Untuk realisasi → `SpreadsheetImportService::import($type,$batch,$path,$uid,$onProgress)`;
  anggaran → `importBudget($year,$type,$path,$uid,$onProgress)`. Sukses → status `done`,
  `row_count`. Gagal (catch Throwable) → status `failed`, `error`. `finally`: hapus file staging.
- `tries=1`, `timeout=3600`. Import idempoten (hapus data batch/sumber dulu sebelum insert),
  jadi menjalankan ulang manual aman.

**Perubahan `SpreadsheetImportService`**
- Tambah parameter opsional `?callable $onProgress = null` pada `import()`/`importRaw()` dan
  `importBudget()`. Panggil `$onProgress($insertedSoFar)` setelah tiap chunk insert.
  Tanpa callback → perilaku lama (dipakai test & CLI).

**Perubahan `ImportController`**
- `confirm()` → **AJAX (JSON)**: validasi (sama), buat baris `import_jobs` (status `queued`,
  `total` = `totalDataRows`/`detectPeriods`-style hitung cepat untuk realisasi; untuk anggaran
  total = jumlah baris file), **jangan hapus file staging di sini**, dispatch `ProcessImport`,
  balas `{ job_id }`. (Tetap dukung non-JS fallback? Tidak — popup wajib; form via fetch.)
- Endpoint baru `GET /import/status/{importJob}` → JSON `{status, processed, total, row_count, error}`.
  Otorisasi: pemilik/Operator/Admin.

**Worker (server)**
- systemd service `lm-reporting-worker.service`:
  `php artisan queue:work database --sleep=3 --tries=1 --max-time=3600` (User=www-data,
  WorkingDirectory=/var/www/lm-reporting/lm-reporting, Restart=always). Mirror service
  `agenda-online` yang sudah ada. `systemctl enable --now`.

### 4.2 Bagian B — Popup progress & notifikasi

**Komponen Toast (global, `layouts.app`)**
- Area toast pojok kanan-atas. API JS `lmToast(message, type='ok'|'err')`. Saat load,
  jika ada `session('status')`/`session('toast_error')` → tampilkan otomatis (untuk aksi
  sinkron yang reload, mis. hapus & pratinjau-error).

**Import — modal progress**
- Form konfirmasi disubmit via `fetch` (Alpine). Tampilkan modal: judul "Mengimpor {type}",
  **progress bar** lebar = `processed/total*100`, teks "X / total baris (P%)", status.
- Polling `GET /import/status/{id}` tiap 2 dtk: update bar; saat `done` → tutup modal,
  `lmToast('Import berhasil: {row_count} baris','ok')`, refresh tabel Riwayat (atau reload);
  `failed` → tutup modal, `lmToast(error,'err')`.

**Pratinjau — overlay sinkron**
- Saat submit form pratinjau → tampilkan overlay "Memproses pratinjau…". Halaman reload
  menampilkan pratinjau (atau blok error validasi yang sudah ada). Tak perlu polling.

**Hapus data — overlay + toast**
- Form `/data/purge` submit → overlay "Menghapus data…". Saat reload, flash `status`
  ditampilkan sebagai toast (berhasil). Gagal validasi → toast error.

## 5. Alur data (import)

```
[Pratinjau] POST /import (sinkron) → simpan staging → tampil pratinjau
[Konfirmasi] fetch POST /import/confirm → buat import_jobs(queued) + dispatch → {job_id}
   → modal progress polling GET /import/status/{job_id}
[Worker] ProcessImport: processing → (update processed per chunk) → done/failed → hapus staging
   → polling melihat done → toast + refresh Riwayat
```

## 6. Penanganan error

- Job gagal → `import_jobs.status=failed` + `error`; modal → toast merah; baris Riwayat tidak
  dibuat (atau dibuat dengan error_count). File staging tetap dihapus.
- Worker mati → job tetap di tabel `jobs`; service `Restart=always` menghidupkan lagi.
- File staging hilang sebelum job jalan → job set `failed` "berkas staging tidak ditemukan".
- Polling timeout di klien (mis. > 20 mnt) → modal tampilkan "masih diproses, cek Riwayat".

## 7. Acceptance

- [ ] `QUEUE_CONNECTION=database`; worker systemd aktif di server (`systemctl status`).
- [ ] Konfirmasi import membuat `import_jobs` (queued) & men-dispatch job; request balas cepat (<2s) walau file besar.
- [ ] Worker memproses; `import_jobs.processed` naik; selesai `done` + `row_count` benar; data masuk DB.
- [ ] Popup import menampilkan progress bar yang bergerak; selesai → toast berhasil; gagal → toast error.
- [ ] Pratinjau & hapus menampilkan overlay saat diproses + toast hasil.
- [ ] Test: `Queue::fake` memverifikasi confirm men-dispatch `ProcessImport`; job memproses file kecil → status done + data tersimpan; endpoint status mengembalikan progres.
- [ ] Import file besar via web tidak timeout (jalan di latar) — verifikasi manual di server.

## 8. Berkas yang disentuh (perkiraan)

- `database/migrations/..._create_import_jobs_table.php` — baru.
- `app/Models/ImportJob.php` — baru.
- `app/Jobs/ProcessImport.php` — baru.
- `app/Domain/Import/SpreadsheetImportService.php` — tambah `?callable $onProgress`.
- `app/Http/Controllers/Import/ImportController.php` — `confirm()` AJAX + `status()`.
- `routes/web.php` — route status.
- `resources/views/import/index.blade.php` — modal progress + fetch konfirmasi + overlay pratinjau.
- `resources/views/admin/purge.blade.php` — overlay submit.
- `resources/views/layouts/app.blade.php` — komponen toast global + helper JS.
- `.env` (server & lokal) — `QUEUE_CONNECTION=database`.
- Server: `/etc/systemd/system/lm-reporting-worker.service` — worker.

## 9. Risiko & keputusan

1. **Sumber daya VPS (1-core/236MB).** 1 worker streaming aman; import tetap 8-10 mnt tapi
   off-request. Tidak menambah worker lain.
2. **`QUEUE_CONNECTION` diubah ke database.** Job lain (jika ada) kini butuh worker untuk
   jalan; saat ini tak ada job lain di lm-reporting. Worker menangani semua.
3. **confirm jadi AJAX.** Butuh JS aktif (Alpine sudah dipakai). Tanpa JS, tombol tak
   berfungsi — dapat ditambah fallback nanti bila perlu (di luar lingkup).
4. **Asset (JS/CSS) berubah** → wajib `npm run build` + scp `public/build` saat deploy
   (lihat memory deploy: kelas/JS baru perlu rebuild).
