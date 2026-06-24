# Desain — Halaman Produksi (Laporan Produksi PKS)

Tanggal: 2026-06-24
Status: disetujui untuk lanjut ke rencana implementasi

## 1. Tujuan

Membangun halaman **Produksi** (`/produksi`, menggantikan placeholder coming-soon) yang
menampilkan laporan produksi PKS (pabrik kelapa sawit) mengacu **persis** pada sheet
referensi `VIEW1` di workbook `docs/produksi/CONTOH_PRODUKSI_PKS.xlsx`. Sumber data adalah
sheet `ZPTPNHLPP039` (laporan harian SAP), di-upload per tanggal posting dan disimpan
sebagai riwayat.

Lingkup ini TERPISAH dari mesin biaya bulanan (LM14/LM13/LM16). Tidak ada keterkaitan
batch year-month; dimensi waktunya adalah **tanggal posting** harian.

## 2. Keputusan yang sudah disepakati

1. **Alur data**: upload file `ZPTPNHLPP039` per tanggal + riwayat. Tiap upload tersimpan
   per `posting_date`; halaman punya pemilih tanggal untuk melihat tanggal mana pun.
2. **Cakupan**: penuh seperti VIEW1 — 6 tabel pivot + ringkasan + rendemen.
3. **Layout blok**: dua blok berdampingan (kiri "Bulan Ini" = s/d hari ini, kanan
   "S.D Bulan Ini" = s/d bulan), persis VIEW1.

## 3. Struktur sumber `ZPTPNHLPP039`

Header di baris 1, data mulai baris 2. Kolom (indeks 0-based):

| Idx | Kolom file | Makna | Dipakai sebagai |
|----:|---|---|---|
| 1 | B Plant | Kode PKS (5F01..) | `plant_code` (kolom pivot) |
| 2 | C Desc. | Nama PKS | `plant_desc` |
| 3 | D Group Pemilik | Kebun Sendiri / Pihak Ketiga / Plasma | `group_pemilik` |
| 4 | E Kebun | Kode asal TBS (5E.., PHTG, PLSM, PLS, 5F08) | `kebun_code` (baris pivot) |
| 5 | F Nama Kebun | Nama asal | `nama_kebun` |
| 6 | G Sisa Awal di PKS | (disimpan, tidak dipakai VIEW1) | `sisa_awal` |
| 8 | I TBS Diterima s/d Hari Ini | | `tbs_diterima_sdhari` |
| 9 | J TBS Diterima s/d Bulan Ini | | `tbs_diterima_sdbulan` |
| 11 | L TBS Diolah s/d Hari Ini | | `tbs_diolah_sdhari` |
| 12 | M TBS Diolah s/d Bulan Ini | | `tbs_diolah_sdbulan` |
| 13 | N Sisa Akhir di PKS | | `sisa_akhir` |
| 15 | P MS s/d Hari Ini | Minyak Sawit | `ms_sdhari` |
| 16 | Q MS s/d Bulan Ini | | `ms_sdbulan` |
| 18 | S IS s/d Hari Ini | Inti Sawit | `is_sdhari` |
| 19 | T IS s/d Bulan Ini | | `is_sdbulan` |
| 26 | AA Tgl Posting | Serial Excel → tanggal | `posting_date` |
| 27 | AB Tidak Mengolah | flag "X" | `tidak_mengolah` |

Kolom "Hari Ini" (H, K, O, R) dan "Realisasi %" (U..Z) TIDAK dipakai VIEW1 — rendemen
dihitung ulang dari MS/IS dibagi TBS Olah. (Boleh tidak disimpan; disimpan hanya kolom di tabel.)

## 4. Model data — tabel baru `produksi_pks`

Satu baris per (`posting_date`, `plant_code`, `group_pemilik`, `kebun_code`).

Kolom: `id`, `posting_date` (date, index), `plant_code` (string 12, index), `plant_desc`,
`group_pemilik` (string 30), `kebun_code` (string 20, index), `nama_kebun` (string 150),
lalu ukuran `decimal(20,2)`: `sisa_awal`, `tbs_diterima_sdhari`, `tbs_diterima_sdbulan`,
`tbs_diolah_sdhari`, `tbs_diolah_sdbulan`, `sisa_akhir`, `ms_sdhari`, `ms_sdbulan`,
`is_sdhari`, `is_sdbulan`; `tidak_mengolah` (bool/char). Index gabungan
(`posting_date`, `plant_code`, `kebun_code`).

Idempoten: impor menghapus baris dengan `posting_date` yang sama lalu menyisipkan ulang.

## 5. Ingestion — jenis import baru "Produksi"

- **Service**: `SpreadsheetImportService::importProduksi(...)` membaca sheet
  `ZPTPNHLPP039` (bukan sheet pertama), menurunkan `posting_date` dari kolom AA
  (serial Excel → `Date`), menyimpan semua baris non-kosong. Idempoten per `posting_date`.
- **Tanpa Batch**: produksi tidak terikat batch year-month. Karena `import()` saat ini
  selalu butuh `Batch`, impor produksi memakai jalur tersendiri (service + command) yang
  TIDAK membuat Batch; tabel `produksi_pks` tidak punya `batch_id`.
- **Web**: jenis baru "Produksi" pada halaman Import. Tahun/bulan TIDAK diminta (tanggal
  diambil otomatis dari file). Pratinjau menampilkan tanggal terdeteksi + jumlah baris.
- **CLI**: `php artisan produksi:import --file=<path.xlsx>` untuk bulk/uji.

## 6. API — `GET /report-data/produksi?date=YYYY-MM-DD`

Auth: pengguna terautentikasi (semua peran boleh lihat; produksi bukan data batch, jadi
TIDAK memakai aturan final/locked). Bila `date` kosong → pakai tanggal terbaru.

Respons (JSON):
```
{
  "dates": ["2026-05-31", ...],        // distinct posting_date desc untuk pemilih
  "date": "2026-05-31",                 // tanggal aktif
  "plants": [{code, desc}, ...],        // kolom dinamis, terurut natural (5F01,5F04,..)
  "kebun": [{code, nama}, ...],         // baris dinamis: 5E.. natural lalu PHTG/PLSM/PLS/5F.. di bawah
  "tables": {
     "restan_awal":  { "bulan_ini": matrix, "sd_bulan": matrix, "grand": {...} },
     "tbs_diterima": {...}, "tbs_diolah": {...}, "restan_akhir": {...},
     "minyak_sawit": {...}, "inti_sawit": {...}
  },
  "ringkasan": { per-plant: restan_awal, tbs_masuk, tbs_olah, restan_akhir, ms, is, jumlah,
                 rend_ms, rend_is, rend_total } x {bulan_ini, sd_bulan},
}
```
`matrix[kebun_code][plant_code] = nilai`. Grand Total kolom/baris memakai **round-of-sum**.

### Pemetaan ukuran (persis VIEW1)

| Tabel | Blok "Bulan Ini" (s/d hari ini) | Blok "S.D Bulan Ini" |
|---|---|---|
| TBS Diterima | I | J |
| TBS Diolah | L | M |
| Restan Akhir | N | N (sama) |
| Minyak Sawit | P | Q |
| Inti Sawit | S | T |
| **Restan Awal** (turunan) | Diolah(L) + RestanAkhir(N) − Diterima(I) | Diolah(M) + RestanAkhir(N) − Diterima(J) |

### Ringkasan & rendemen (per plant, dua blok)

- PRODUKSI TBS: Restan Awal = GT tabel restan awal; TBS Masuk = GT TBS Diterima;
  TBS Olah = GT TBS Diolah; Restan Akhir = Restan Awal + Masuk − Olah.
- PRODUKSI MS+IS: Minyak Sawit = GT MS; Inti Sawit = GT IS; Jumlah = MS + IS.
- RENDEMEN: Rend MS = IFERROR(MS / TBS Olah × 100, 0); Rend IS = IFERROR(IS / TBS Olah
  × 100, 0); Rend MS+IS = Rend MS + Rend IS.

### Aturan angka

- Kuantitas (TBS/MS/IS/restan) ditampilkan **bulat** (0 desimal).
- Rendemen ditampilkan **2 desimal**.
- Rasio dengan penyebut 0 → 0 (pola IFERROR/0).
- Grand Total = round-of-sum (jumlah dulu, baru bulatkan).

## 7. Halaman `/produksi`

Mengganti `Route::view('/produksi', 'coming-soon')` dengan view `produksi/index`.

- **Toolbar**: pemilih **Tanggal** (dari `dates`), tombol cetak/expor (opsional, fase
  berikut). Gaya kartu header hijau `#0f4c3a` seperti halaman Kebun/Areal.
- **Ringkasan**: blok PRODUKSI TBS, PRODUKSI MS+IS, RENDEMEN MS+IS — kolom per plant
  (5F01..) + JLH, dua sisi (Bulan Ini & S.D Bulan Ini).
- **6 tabel pivot** (Tabulator), tiap tabel: kolom identitas **Kebun + Nama Kebun** (frozen),
  lalu grup **BULAN INI** (plant 5F01.. + Grand Total) dan grup **S.D BULAN INI**
  (plant 5F01.. + Grand Total) berdampingan; baris Kebun + baris **Grand Total**.
  `headerSort:false` (tanpa ikon sort), gaya `lm-report-table` (header hijau, font sama).
- JS INLINE di blade (seperti Areal) agar `app.js/app.css` tak berubah → hash aset TETAP,
  scp aset TIDAK perlu saat deploy.

## 8. Urutan kolom & baris (dinamis)

- **Plant** (kolom): ambil distinct dari data tanggal itu, urut natural pada kode
  (5F01,5F04,5F07,5F08,5F09,5F14,5F15,5F21,5F22, dst.).
- **Kebun** (baris): distinct `kebun_code`; kode diawali `5E` diurutkan natural di atas,
  lalu sisanya (5F.., PHTG, PLSM, PLS) di bawah mengikuti urutan kemunculan VIEW1.

## 9. Keamanan & peran

- `/produksi` (view) & `/report-data/produksi` (API): semua peran login boleh lihat
  (Viewer/Operator/Admin) — produksi bukan laporan ber-status, jadi tak ada batasan
  final/locked.
- Import jenis "Produksi": Operator/Admin (sama dengan import lain).

## 10. Pengujian

- Unit: `importProduksi` menyimpan baris benar, `posting_date` terkonversi benar dari serial,
  idempoten per tanggal.
- API: matrix nilai untuk beberapa sel cocok dengan VIEW1 (mis. TBS Diterima 5E01/5F01
  Bulan Ini = 3.795.250; S.D = 19.506.780); Grand Total TBS Diterima S.D = 271.474.210;
  Restan Awal turunan cocok; rendemen GT cocok.
- Auth: API menolak tanpa login; menerima semua peran login.
- UI smoke: halaman render, pemilih tanggal bekerja, dua blok tampil.

## 11. Di luar lingkup (fase berikut)

- Sheet `VIEW2` dan `ZESTHLE020` (tidak dipakai; VIEW1 saja sesuai instruksi).
- Expor Excel/PDF/cetak khusus produksi.
- Agregasi lintas-tanggal / tren.
