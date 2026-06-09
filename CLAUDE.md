# CLAUDE.md — Panduan Kerja AI Coding Agent
## Proyek: Sistem Pelaporan LM PTPN IV Regional V

Aplikasi **Report Viewer** biaya produksi Kebun & Pabrik (menggantikan workbook Excel LM).
Baca dokumen acuan SEBELUM menulis kode. Patuhi aturan di bawah sepanjang proyek.

---

## 1. Peta Berkas (baca ini dulu)

| Berkas | Peran |
|---|---|
| `docs/PRD_Sistem_Pelaporan_LM.md` | **Sumber kebenaran** — spesifikasi lengkap (navigasi, filter, struktur tabel, aturan hitung, ERD, API, acceptance). |
| `docs/PROMPTS_AI_Agent_LM.md` | Urutan tahap kerja `prompt_00`..`prompt_09`. Kerjakan berurutan. |
| `database/seeders/sql/schema_mysql.sql` | DDL MySQL + seed master unit & klasifikasi (+ tabel sumber LM13/LM16). |
| `database/seeders/sql/seed_lm_template_row.sql` | Seed struktur baris semua report (594 baris) — kunci agar tabel identik Excel. |
| `database/seeders/sql/seed_lm16_account_map.sql` | Seed pemetaan kode SAP → baris LM16 (80 baris). |
| `docs/reference/Lampiran LM Kebun Sawit Mei 2026.xlsx` | Acuan validasi angka KEBUN (Sawit). |
| `docs/reference/Lampiran LM Kebun Karet Mei 2026.xlsx` | Acuan validasi angka KEBUN (Karet). |
| `docs/reference/Lampiran LM PKS Mei 2026.xlsx` | Acuan validasi angka PABRIK (LM16). |

Jika ada konflik antara asumsi sendiri dan PRD → **PRD menang**.

---

## 2. Prinsip Utama (tidak bisa ditawar)

1. **Tampilan tabel data WAJIB identik dengan Excel** — grouped header, kolom identitas
   frozen, urutan & tipe baris (header/detail/subtotal/total), pemisahan blok kolom.
2. **Kebenaran angka diuji terhadap workbook Mei 2026** pada tahap 03–05 (selisih = 0
   untuk baris kunci). Jangan lanjut tahap berikut bila angka belum cocok.
3. **Nol tebakan**: pemetaan sumber sudah disediakan (template baris, account map, lineage
   di Addendum PROMPTS). Pakai itu, jangan mengarang sumber data.

---

## 3. Stack & Konvensi

- **Backend:** Laravel 12, PHP 8.3, MySQL 8. **Frontend:** Blade + Alpine.js + Tabulator.js.
- **Auth/RBAC:** 3 role — Viewer, Operator, Admin.
- **Bahasa:** UI & komentar domain dalam Bahasa Indonesia. Kode/identifier dalam English.
- **Export:** Excel (maatwebsite), CSV, PDF (dompdf), Cetak.
- **Visual:** ikuti gaya prototype (hijau `#0f4c3a`, kartu header, KPI strip, toolbar, frozen column).

---

## 4. Aturan Git & Eksekusi

- **Commit kecil & sering. Pesan commit Bahasa Indonesia** (mis. `feat(db): migrasi skema + seeder`).
- **`git add` per-file** — JANGAN `git add .` / `git add -A`.
- Satu commit = satu perubahan logis. Sertakan ringkasan di akhir tiap tahap.
- **Jangan** jalankan perintah destruktif (drop db, rm -rf, reset --hard) tanpa konfirmasi eksplisit.
- **Jangan** menyentuh data/server produksi. Semua kerja di environment lokal/dev.
- Jangan menjalankan migrasi `fresh`/`wipe` pada database berisi data tanpa konfirmasi.

---

## 5. Alur Kerja Bertahap (WAJIB)

- Kerjakan **SATU `prompt_xx` per instruksi** dari user. Jangan menggabung beberapa tahap.
- Setelah selesai: tampilkan **ringkasan perubahan + cara uji (acceptance)**, lalu **BERHENTI**
  dan tunggu instruksi "lanjut". Jangan otomatis lanjut ke tahap berikut.
- Urutan: `00 setup → 01 skema+seed → 02 import → 03 LM14 → 04 LM13 → 05 LM16 → 06 API →
  07 UI layout → 08 UI tabel+export → 09 drill-down`.
- **Gerbang kritis:** tahap 03–05 (mesin hitung). Jangan dianggap selesai sebelum hasil
  `report:generate` cocok dengan workbook acuan (lihat acceptance tiap prompt).

---

## 6. Aturan Domain Penting (ringkasan; detail di PRD §8)

- **Kumulatif s.d bulan ini** = bulan ini + Σ realisasi bulan < bulan ini (tahun sama).
  Tidak ada IMPORTRANGE. Multi-periode WAJIB (tiap batch year+month berdiri sendiri).
- **RKAP & RKO** disimpan **sudah ×1000** (Excel menyimpan dalam ribuan).
- **Subtotal/total LM14** = jumlah baris sesuai `lm_template_row.formula` (`u{n}+u{n}...`),
  diterapkan ke SEMUA kolom nilai. Bukan agregasi ulang dari mentah.
- **Semua rasio/persentase** pakai pola aman: bila penyebut 0 → hasil 0 (IFERROR/0).
- **PKS Olah vs Tidak Olah/KSO** dari `ref_unit.olah_status`. Kolom `Jumlah = Olah + KSO`.
- **Lineage sumber:** Kebun → `db_wbs`/`db_btl` → LM14 → (beban) LM13; `Alokasi` → (produksi/luas) LM13.
  Pabrik → `Summary`(`pks_biaya`) + `LM625F01`(`pks_produksi`) → LM16 (pakai `lm16_account_map`).

---

## 7. Batas Lingkup (fase ini)

- **Tidak** membangun: PKR (pabrik karet, mis. 5F20), kolom breakdown klasifikasi prototype.
  Tandai sebagai fase berikutnya bila muncul.
- Bila ada kode GL/CC pabrik di luar `lm16_account_map` → tampung kategori 'Lain-lain' & catat.
- Bila angka LM13/LM16 belum cocok → periksa pemetaan kategori & sumber master; catat sebagai sub-tugas,
  jangan memaksakan angka agar "kelihatan cocok".

---

## 8. Definisi Selesai (per tahap)

Sebuah tahap dianggap selesai bila: kode jalan tanpa error, acceptance di prompt terpenuhi
(termasuk uji angka vs Excel untuk 03–05), sudah di-commit dengan pesan Bahasa Indonesia,
dan ringkasan + cara uji sudah ditampilkan ke user.
