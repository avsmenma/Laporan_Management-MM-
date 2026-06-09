# Prompt Bertahap — AI Coding Agent
## Sistem Pelaporan LM PTPN IV Regional V

Berkas pendukung (lampirkan ke agent bersama prompt ini):
- `PRD_Sistem_Pelaporan_LM.md` — spesifikasi lengkap (sumber kebenaran).
- `schema_mysql.sql` — DDL + seed master unit & klasifikasi.
- `seed_lm_template_row.sql` — seed struktur baris semua report (594 baris).
- 3 workbook acuan Mei 2026 (untuk uji kecocokan angka).

**Aturan kerja (berlaku semua prompt):**
- Stack: Laravel 12, PHP 8.3, MySQL 8. Frontend: Blade + Alpine.js + Tabulator.js (atau React bila tim lebih nyaman).
- Commit kecil & sering, **pesan commit Bahasa Indonesia**. `git add` per-file (hindari `git add .`).
- Jangan jalankan perintah destruktif tanpa konfirmasi. Jangan mengubah data produksi.
- Patuhi PRD bila ada konflik dengan asumsi agent. **Prinsip utama: tabel hasil harus identik dengan Excel.**
- Setiap akhir prompt: tampilkan ringkasan perubahan + cara uji.

Kerjakan **berurutan**. Jangan lanjut ke prompt berikut sebelum acceptance prompt sekarang lolos.

---

## prompt_00 — Inisialisasi proyek & konvensi

```
Konteks: Lihat PRD_Sistem_Pelaporan_LM.md (bagian 1–6). Kita membangun Report Viewer
biaya produksi kebun & pabrik PTPN IV Regional V berbasis Laravel 12 + MySQL 8.

Tugas:
1. Inisialisasi proyek Laravel 12 baru bernama "lm-reporting". Set timezone Asia/Jakarta,
   locale id. Konfigurasi koneksi MySQL (database: lm_reporting).
2. Pasang dependency: laravel/sanctum, maatwebsite/excel, barryvdh/laravel-dompdf,
   (frontend) alpinejs + tabulator-tables via npm/vite.
3. Siapkan struktur folder domain: app/Domain/Report (Services), app/Domain/Import,
   app/Http/Controllers/{Report,Import,Master}. Tambahkan README singkat.
4. Reuse pola RBAC sederhana: 3 role (Viewer, Operator, Admin) + middleware role.
   Buat tabel users/roles minimal + seeder 1 user tiap role.
5. Buat file CLAUDE.md berisi aturan kerja di atas.

Acceptance:
- `php artisan serve` jalan, halaman login muncul, login 3 role berhasil.
- `npm run dev` build tanpa error; Tabulator & Alpine ter-load di 1 halaman contoh.
Commit: "init: setup proyek laravel lm-reporting + rbac + frontend toolchain".
```

---

## prompt_01 — Skema database, migrasi, seeder master

```
Konteks: Gunakan schema_mysql.sql dan seed_lm_template_row.sql sebagai acuan PERSIS.

Tugas:
1. Ubah schema_mysql.sql menjadi migrasi Laravel (satu migrasi per kelompok tabel:
   master, raw, budget, report). Pertahankan NAMA tabel & kolom, tipe, indeks, FK PERSIS
   seperti di file. Engine InnoDB, charset utf8mb4.
2. Buat model Eloquent untuk tiap tabel + relasi sesuai ERD di PRD (bagian 10):
   RefUnit hasMany ReportLm14/13/16; Batch hasMany db_* & report_*; LmTemplateRow hasMany report_*.
3. Buat seeder:
   - RefUnitSeeder + RefKlasifikasiSeeder (ambil dari blok SEED di schema_mysql.sql).
   - RefUnitKomoditiSeeder: semua kebun -> KS; kebun KR untuk 5E06,5E12,5E13,5E19
     (sesuaikan bila data menunjukkan lain — buat idempotent).
   - LmTemplateRowSeeder: import seed_lm_template_row.sql apa adanya (594 baris).
   - Lm16AccountMapSeeder: import seed_lm16_account_map.sql (tabel lm16_account_map + 80 baris
     pemetaan kode SAP -> baris LM16).
4. CATATAN: pada hasil ekstraksi, sebagian row_type LM13/LM16 perlu dirapikan manual:
   - LM16 "Total Biaya Pabrik" -> row_type 'total'.
   - Baris LM13/LM16 yang uraiannya diawali "Jumlah"/"Total" -> subtotal/total sesuai konteks.
   Rapikan di seeder, sisanya biarkan.

Acceptance:
- `php artisan migrate:fresh --seed` sukses tanpa error.
- SELECT COUNT(*) lm_template_row = 594; ref_unit = 27 (17 kebun + 10 pabrik).
- Cek FK aktif (tidak ada orphan).
Commit: "feat(db): migrasi skema + seeder master unit, klasifikasi, template baris".
```

---

## prompt_02 — Modul Import data mentah & master pembanding

```
Konteks: PRD bagian 12. Data masuk dari export Excel SAP (DB WBS, DB BTL),
form pabrik (LM625Fxx -> db_pks), dan master pembanding (RKAP/RKO/Tahun Lalu) yang
di-upload BERKALA (mingguan/bulanan) via UI, bersifat upsert. Multi-periode WAJIB.

Tugas:
1. Halaman "Batch": buat/kelola batch (year, month 1-12, status draft/final/locked).
2. Import (role Operator), pakai maatwebsite/excel:
   - Import DB WBS -> db_wbs (map kolom: Budidaya->komoditi, Plant->plant_code,
     Period->period, Aktifitas->aktivitas, Cost Element, Klasifikasi->klasifikasi_code, Nilai, Fisik).
   - Import DB BTL -> db_btl (Kode->komoditi, Plant, Period, Kode CC->kode_cc, Nilai, dst).
   - Import data pabrik:
       * Biaya pabrik (sheet "Summary") -> pks_biaya (plant, period, cost_center=kolom D,
         cost_element/GL=kolom F, klasifikasi, nilai=kolom I).
       * Produksi pabrik (sheet "LM625F01") -> pks_produksi (plant, period, uraian=kolom B,
         nilai_bi=kolom E, nilai_sd=kolom F; blok bulan lalu Q/T bila perlu).
   - Import master kebun->pabrik (sheet "Alokasi") -> alokasi_produksi (year, month,
     kebun_code=kolom B, pabrik_code per kolom C..K, produk=kolom N, jumlah=kolom L) dan
     alokasi_areal (blok "III. Areal" F236:J252: kebun_code, real_thn_lalu, real_thn_ini, rko, rkap).
   - Import master RKAP & RKO -> budget_rkap/budget_rko: **kalikan 1000** saat simpan.
     Upsert per (year,komoditi,plant_code,report_type,kode).
   - Import Tahun Lalu -> realisasi_tahun_lalu (upsert per kunci + period).
3. Setiap import terikat ke batch terpilih; impor ulang = replace data batch+jenis itu.
4. Simpan log upload (siapa, kapan, jenis, jumlah baris, batch).
5. Validasi: tolak baris dengan plant_code/komoditi tak dikenal; tampilkan ringkasan error.

Acceptance:
- Import sheet "DB WBS" & "DB BTL" dari workbook Mei 2026 sukses; jumlah baris cocok.
- Re-import tidak menggandakan (replace per batch+jenis).
- RKAP/RKO tersimpan sudah ×1000.
Commit: "feat(import): batch + import DB WBS/BTL/PKS + master RKAP/RKO/Tahun Lalu (upsert)".
```

---

## prompt_03 — Service perhitungan LM14 (KEBUN, rincian akun) + validasi

```
Konteks: PRD bagian 7.1, 8. LM14 = sheet "-A". Replikasi rumus Excel ke service.

Tugas: app/Domain/Report/Lm14Service.php yang memmaterialisasi tabel report_lm14
untuk (batch, unit, komoditi). Untuk tiap baris template (report_type=LM14, komoditi terkait,
urut by urutan):
- row_type 'detail':
    real_bulan_ini  = SUM nilai db_wbs (komoditi,plant,period=bulan, aktivitas=kode);
                      bila source='BTL' -> dari db_btl (match kode_cc=kode).
    real_bulan_lalu = sama, period = bulan-1.
    real_tahun_lalu = realisasi_tahun_lalu (year-1, kode, period=bulan).
    rko/rkap        = budget_rko/budget_rkap (year, kode).  (sudah ×1000)
    real_sd_bulan_ini = real_bulan_ini + SUM realisasi period < bulan (tahun sama).
    real_sd_tahunlalu/rko_sd/rkap_sd = kumulatif s.d bulan untuk tahun lalu/RKO/RKAP.
- row_type 'subtotal'/'total': nilai tiap kolom = jumlah baris sesuai field `formula`
    (format 'u{urutan}+u{urutan}...'). Terapkan ke SEMUA kolom nilai (E..I, N..Q).
- Kolom capaian (semua, IFERROR/0):
    cap_bi_lalu=E/F*100; cap_bi_thnlalu=E/G*100; cap_bi_rko=E/H*100; cap_bi_rkap=E/I*100;
    cap_sd_thnlalu=N/O*100; cap_sd_rko=N/P*100; cap_sd_rkap=N/Q*100.
- Header: nilai 0/null.
Endpoint/command: `php artisan report:generate --type=LM14 --batch=.. ` mengisi report_lm14.

Acceptance (PENTING):
- Generate LM14 untuk Kebun Gunung Meliau (5E01), komoditi KS, periode 5.
- Bandingkan dengan sheet Gunme-A kolom E (Real Bulan Ini) & N (Real sd): selisih = 0
  untuk baris kunci (Jumlah Gaji, JUMLAH BIAYA TANAMAN, TOTAL, dan ≥10 detail).
- Persentase capaian cocok ±0,1.
Commit: "feat(report): Lm14Service + generator + validasi vs Gunme-A Mei 2026".
```

---

## prompt_04 — Service perhitungan LM13 (KEBUN, laporan Lampiran)

```
Konteks: PRD bagian 7.2, 8. LM13 = sheet "-B" (format Lampiran), 3 blok kolom
(OLAH_JUAL / OLAH / JUAL) × (Bulan Ini, s.d Bulan Ini) × {Real Th Lalu, Real Th Ini, RKO TW, RKAP}.
Baris produksi (kg) & beban (Rp) + indikator (per Ha, Rp/Kg).

Tugas: Lm13Service.php memmaterialisasi report_lm13 (kolom blok sesuai PRD 9.4).
- Produksi (kg) dari tabel master `alokasi_produksi` (SUMIFS jumlah per kebun + kategori produk):
    Saldo Awal TBS         <- produk 'Stok Awal TBS'
    Diterima dari Lapangan <- produk 'TBS Diterima'
    TBS Olah (Hasil Olah)  <- produk 'TBS Olah'
    Saldo Akhir TBS        <- produk 'TBS Restan Loading Ramp'
    Minyak Sawit (CPO)     <- produk 'CPO'
    Inti Sawit (Kernel)    <- produk 'Kernel'
    Jumlah MS+IS           <- produk 'CPO + Kernel'
  Filter: kebun_code=unit, month=periode (kumulatif s.d = SUM month <= periode).
- Luas Area TM dari `alokasi_areal` (Real Th Lalu/Th Ini/RKO/RKAP) per kebun_code.
- Blok OLAH vs JUAL via alokasi: 'Di Olah' = produk yang dialokasikan ke pabrik (kolom pabrik
  terisi); 'Di Jual' = sisanya; 'OLAH_JUAL' = total.
- Beban (Rp): agregasi dari report_lm14 per kategori — petakan ke baris LM14:
    Gaji & Tunjangan Karpim Tanaman <- LM14 'Jumlah Gaji'
    Pemeliharaan TM                 <- LM14 'JUMLAH BIAYA PEMELIHARAAN'
    Pemupukan                       <- LM14 'JUMLAH BIAYA PEMUPUKAN'
    Panen                           <- LM14 'JUMLAH BIAYA PANEN'
    Pengangkutan ke Pabrik          <- LM14 'JUMLAH BIAYA PENGANGKUTAN'
    Beban Overhead                  <- LM14 'Jumlah Overhead (Biaya Tidak Langsung)'
    Beban Penyusutan                <- LM14 'Jumlah Depresiasi'
- Indikator: Biaya per Ha = beban / luas TM; Harga Pokok Rp/Kg = beban / produksi MS+IS (IFERROR/0).
- "Bulan Ini" vs "s.d Bulan Ini" pakai logika kumulatif yang sama (multi-periode).
- subtotal/total sesuai struktur template LM13.

Acceptance:
- Generate LM13 Kebun Danau Salak (5E11) KS periode 5.
- Cocokkan dengan sheet Dasal-B (mis. Jumlah Biaya Produksi, Harga Pokok Rp/Kg MS+IS,
  Biaya Produksi per Ha) — selisih wajar (pembulatan) atau 0.
Commit: "feat(report): Lm13Service (Lampiran 3-blok) + validasi vs Dasal-B".
```

---

## prompt_05 — Service perhitungan LM16 (PABRIK)

```
Konteks: PRD bagian 7.3, 8. LM16 = sheet "Pagun". Kolom: Realisasi Bulan Lalu;
Bulan Ini (Olah/KSO/Jumlah); RKO/RKAP BI; s.d Bulan Ini (Olah/KSO/Jumlah); RKO/RKAP sd;
4 capaian. (Breakdown per-jenis prototype TIDAK dipakai.)

Tugas: Lm16Service.php memmaterialisasi report_lm16:
- PRODUKSI (baris I,II,III) dari `pks_produksi` (asal sheet LM625F01) via lookup uraian:
    TBS dari Lapangan  <- uraian 'Jumlah Produksi TBS'
    TBS Diolah         <- uraian 'Jumlah TBS Diolah'
    Sisa Buah/Stok Akhir <- uraian 'Jumlah Sisa Buah di Pabrik'
    Minyak Sawit       <- uraian 'Jlh. Prod. Minyak Sawit'
    Inti Sawit         <- uraian 'Jumlah Produksi Inti Sawit'
  Bulan Ini = nilai_bi; s.d Bulan Ini = nilai_sd; Bulan Lalu = nilai bulan-1.
  Stok Awal = StokAkhir(bulan lalu); Stok Akhir = restan (rumus: awal + masuk - olah).
- BIAYA (Pengolahan & Overhead) dari `pks_biaya` (asal sheet Summary):
    Biaya pengolahan (akun GL) : SUM nilai WHERE cost_element(GL)=kode akun, plant, period.
    Biaya overhead (BT)        : SUM nilai WHERE cost_center=kode CC (BT01..), plant, period.
  Kelompokkan ke baris template LM16 via tabel `lm16_account_map`:
    join pks_biaya.cost_center  = map.kode WHERE map.match_type='cost_center'
    join pks_biaya.cost_element = map.kode WHERE map.match_type='cost_element'
    -> map.lm16_uraian menunjuk baris LM16 yang dijumlah. Kode di luar map -> kategori 'Lain-lain'.
- Pisah Olah vs KSO: jika unit olah_status='Olah' -> seluruh nilai ke kolom Olah, KSO=0;
  jika 'Non Olah' -> ke kolom Tidak Olah/KSO, Olah=0. bi_jumlah=bi_olah+bi_kso; sd_jumlah=sd_olah+sd_kso.
- real_bln_lalu, bi_rko/bi_rkap (×1000), sd_rko/sd_rkap dari budget + kumulatif.
- Rendemen: MS/TBS_diolah*100; IS/TBS_diolah*100.
- rp_kg_tbs = biaya/TBS_diolah; rp_kg_mi = biaya/Jumlah_M+I (IFERROR/0).
- 4 capaian (IFERROR/0):
    cap_bi_lalu = bi_jumlah/real_bln_lalu*100
    cap_bi_rkap = bi_jumlah/bi_rkap*100
    cap_bi_sd   = bi_jumlah/sd_jumlah*100
    cap_sd_rkap = sd_jumlah/sd_rkap*100
- subtotal/total (Jumlah Biaya Pengolahan/Overhead, Total Biaya Pabrik).

Acceptance:
- Generate LM16 PKS Gunung Meliau (5F01) periode 5.
- Cocokkan dengan sheet Pagun (Total Biaya Pabrik, Jumlah Biaya Pengolahan, Rendemen,
  kolom Jumlah X & AK) — selisih = 0 / pembulatan.
- Status Olah (5F01) -> kolom KSO = 0; uji 1 unit Non Olah memunculkan nilai di KSO.
Commit: "feat(report): Lm16Service (Olah/KSO/Jumlah + 4 capaian) + validasi vs Pagun".
```

---

## prompt_06 — API + read layer laporan

```
Konteks: PRD bagian 11.

Tugas:
1. Endpoint:
   GET /api/units?type&komoditi          -> dropdown unit
   GET /api/batches                      -> dropdown periode/batch
   GET /api/report/lm14?batch&unit&komoditi
   GET /api/report/lm13?batch&unit&komoditi
   GET /api/report/lm16?batch&unit
   GET /api/report/drilldown?type&batch&unit&komoditi&kode&column   -> rincian/konteks 1 sel (lihat prompt_09)
2. Respons report mengembalikan:
   - meta: nama unit, kode, tahun, periode (angka), jumlah hari sebulan/dijalani/sisa,
     status batch, diproses_at.
   - rows: array terurut by urutan, tiap row {kode, uraian, row_type, indent, ...nilai kolom}.
     Tiap sel nilai membawa metadata drill-down {kode_baris, column_key} (untuk prompt_09).
   - columns: definisi grouped header (untuk render multi-baris header sesuai PRD 7).
3. Otorisasi: Viewer hanya batch status final/locked.
4. Hitung KPI hari: jumlah hari bulan; hari dijalani = berdasar tanggal proses; sisa = selisih.

Acceptance:
- 3 endpoint report mengembalikan struktur lengkap utk 5E01(LM14/13) & 5F01(LM16).
- Viewer ditolak untuk batch draft.
Commit: "feat(api): endpoint dropdown + report LM14/LM13/LM16 + meta KPI".
```

---

## prompt_07 — Frontend: layout, sidebar, filter (chrome dari prototype)

```
Konteks: PRD bagian 5,6. Visual mengikuti prototype (hijau #0f4c3a, kartu header, KPI strip,
toolbar export, search, frozen column).

Tugas:
1. Layout global: header hijau (logo PN, judul, badge peran, profil). Sidebar: KEBUN, PABRIK.
2. Halaman KEBUN & PABRIK: bar filter di atas:
   - Komoditas (Sawit/Karet) — di PABRIK sementara hanya Sawit (PKS); Karet/PKR fase berikut.
   - Periode = ANGKA bulan 1–12 (tooltip nama bulan opsional).
   - Unit (Kebun/Pabrik) — isi via /api/units, terfilter komoditas; tidak hardcode.
   - Indikator Batch (year-month).
   Filter saling bergantung (komoditas -> isi unit).
3. Kartu header laporan + 3 KPI (Jlh Hari Sebulan / Hari Dijalani / Sisa Hari).
4. Toolbar: Excel, CSV, PDF, Cetak, Refresh, Search baris.
5. KEBUN: 2 tab (LM 14, LM 13). PABRIK: 1 tab (LM 16).

Acceptance:
- Navigasi sidebar & filter berfungsi; ganti komoditas mengubah daftar unit.
- Tab tampil sesuai halaman; KPI hari benar.
Commit: "feat(ui): layout + sidebar KEBUN/PABRIK + bar filter + tab".
```

---

## prompt_08 — Frontend: tabel LM14/LM13/LM16 (identik Excel) + export & QA

```
Konteks: PRD bagian 7. INI INTI PROYEK: tabel harus PERSIS Excel.

Tugas (pakai Tabulator.js):
1. Tab LM 14 (KEBUN): grouped header 2 grup — "Bulan <X>" (Real Bln Ini/Lalu/Th Lalu/RKO/RKAP +
   Capaian thdp Bln Lalu/Th Lalu/RKO/RKAP) dan "s.d Bulan <X>" (Real sd/Th Lalu/RKO/RKAP +
   Capaian). Kolom Kode & Uraian FROZEN. Baris header/subtotal/total diberi gaya berbeda
   (bold/indent). Format ribuan, nol -> "-", persen 1 desimal.
2. Tab LM 13 (KEBUN): 3 blok kolom (Kebun Sendiri+Pihak III / Di Olah / Di Jual),
   tiap blok 2 sub-grup (Bulan Ini, s.d Bulan Ini) × 4 kolom. Uraian frozen.
3. Tab LM 16 (PABRIK): kolom Realisasi Bln Lalu | Bulan Ini(Olah/KSO/Jumlah) | RKO/RKAP |
   s.d Bln Ini(Olah/KSO/Jumlah) | RKO/RKAP | 4 Capaian + Rp/kg TBS, Rp/kg M+I.
4. Search baris (uraian/kode), horizontal scroll, sticky header.
5. Export Excel/CSV/PDF & Cetak: layout output mengikuti tabel (grouped header).
6. Footer: "Menampilkan N baris · M kolom · Nilai dalam Rupiah · Report final · terkunci".
7. Tiap sel ANGKA dapat diklik (cursor pointer + hover). Klik memicu drill-down (prompt_09);
   tiap sel membawa metadata {report_type, unit, batch, komoditi, kode_baris, column_key}.

Acceptance (QA akhir):
- Tata letak kolom & urutan baris identik dengan Excel untuk LM14(5E01), LM13(5E11), LM16(5F01).
- Angka cocok dengan workbook Mei 2026 (lihat acceptance prompt_03/04/05).
- Export menghasilkan file dengan struktur kolom sama.
- Sel angka klikable membuka panel drill-down.
Commit: "feat(ui): tabel LM14/LM13/LM16 mirip Excel + export + QA Mei 2026".
```

---

## prompt_09 — Drill-down: klik nilai -> konteks/dasar angka

```
Konteks: User ingin tiap nilai di tabel bisa diklik dan diarahkan ke konteks pembentuk
angka tersebut (drill-through). Tingkat rincian tergantung jenis baris & kolom.

Tugas:
1. Endpoint GET /api/report/drilldown?type&batch&unit&komoditi&kode&column
   Mengembalikan:
   - meta: { uraian_baris, label_kolom, nilai_total, penjelasan/rumus }
   - items: [ { label, kode, nilai, next?:{params drill berikutnya}, link?:{report_type,unit,kode} } ]
   Logika per kasus:
   a) LM14 detail, kolom Real Bulan Ini/Lalu:
        items = transaksi db_wbs (atau db_btl bila source=BTL) yang ter-SUM
        (filter komoditi, plant, period, aktivitas=kode / kode_cc=kode).
        Tampilkan: job_name/cost_element_desc, cost_element, nilai, fisik.
   b) LM14 detail, kolom Tahun Lalu/RKO/RKAP:
        items = 1 baris sumber dari realisasi_tahun_lalu / budget_rko / budget_rkap.
   c) LM14 subtotal/total:
        items = daftar baris yang dijumlah (dari lm_template_row.formula 'u..+u..'),
        tiap item punya `next` untuk drill ke baris itu (rekursif).
   d) LM14/LM16 kolom Capaian (%):
        meta.penjelasan = "pembilang / penyebut × 100"; items = 2 nilai (pembilang, penyebut)
        beserta `next` ke masing-masing.
   e) LM13 produksi:
        items = baris alokasi_produksi (per pabrik) penyusun angka (kebun, produk, pabrik, jumlah).
   f) LM13 beban:
        link = ke baris LM14 terkait (cross-report); UI membuka tab LM14 + sorot baris itu.
   g) LM16 biaya:
        items = baris pks_biaya (Summary) yang ter-SUM via lm16_account_map
        (cost_center/cost_element, nilai). subtotal -> daftar baris (next rekursif).
   h) LM16 produksi:
        items = baris pks_produksi (LM625F01) penyusun angka.
2. UI: panel geser (slide-over) kanan / modal "Dasar Nilai".
   - Judul: "Dasar nilai: {uraian_baris} — {label_kolom}", total di atas.
   - Tabel komponen (items); tiap angka komponen JUGA klikable (drill rekursif) bila ada `next`.
   - Breadcrumb antar level + tombol Kembali. Item dgn `link` -> navigasi cross-report (tab + sorot).
   - Tombol export panel (CSV) opsional.
3. Aman: drill-down hanya untuk batch final/locked (role Viewer).

Acceptance:
- Klik nilai "Real Bulan Ini" pada 1 baris detail LM14 (5E01) menampilkan transaksi db_wbs
  yang jumlahnya = nilai sel.
- Klik subtotal "JUMLAH BIAYA TANAMAN" menampilkan baris penyusun; klik salah satunya drill lebih dalam.
- Klik nilai biaya LM16 (5F01) menampilkan baris Summary (cost_element/cost_center) penyusun.
- Klik beban LM13 mengarahkan ke baris LM14 terkait.
Commit: "feat(drilldown): endpoint + panel konteks nilai (LM14/LM13/LM16, rekursif)".
```

---

### Urutan & catatan
- 00→01→02 menyiapkan fondasi; 03→04→05 mesin hitung (paling kritis, validasi ketat);
  06 API; 07→08 UI. Tabel "mirip Excel" dikunci di 08, tapi kebenaran angka dikunci di 03–05.
- Bila angka LM13/LM16 belum cocok, periksa pemetaan kategori & sumber master (Alokasi/Produksi)
  — mungkin perlu impor sheet master tambahan; catat sebagai sub-tugas.
- PKR (pabrik karet) & breakdown klasifikasi = fase berikutnya, jangan dibangun sekarang.

---

## Addendum — Lineage sumber LM13 & LM16 (eksplisit)

Ditambahkan tabel master pada `schema_mysql.sql`: `alokasi_produksi`, `alokasi_areal`,
`pks_biaya`, `pks_produksi`. Aliran datanya:

```
LM13 (KEBUN, produksi & luas):
  sheet "Alokasi" (matriks kebun x pabrik, kolom N=produk, L=jumlah, blok III. Areal)
     -> alokasi_produksi (produksi kg per kebun+produk+bulan)
     -> alokasi_areal    (luas TM per kebun: Real ThLalu/ThIni/RKO/RKAP)
        -> Lm13Service  (produksi & luas)
  Beban LM13 = agregasi report_lm14 per kategori (lihat prompt_04).

LM16 (PABRIK):
  sheet "Summary"   (transaksi biaya pabrik: D=cost_center, F=cost_element/GL, I=nilai)
     -> pks_biaya    -> Lm16Service (biaya pengolahan via GL, overhead via BT)
  sheet "LM625F01"  (form produksi harian: B=uraian, E=BI, F=SBI)
     -> pks_produksi -> Lm16Service (TBS, Diolah, Minyak, Inti, Sisa Buah)
```

**Kunci lookup penting:**
- Alokasi: filter `kebun_code` + `produk` + `month` (kumulatif = SUM month <= periode).
- Summary: biaya pengolahan match `cost_element` (GL akun, mis. 51100xxx/90042xxx);
  biaya overhead match `cost_center` (BT01..BT16). Filter plant + period.
- LM625F01: match `uraian` persis ('Jumlah Produksi TBS','Jumlah TBS Diolah',
  'Jumlah Sisa Buah di Pabrik','Jlh. Prod. Minyak Sawit','Jumlah Produksi Inti Sawit').

> Saat import (prompt_02), seed pemetaan kode akun GL/CC -> baris template LM16 dari struktur
> sheet Pagun baris 73-176 (sudah teridentifikasi). Bila ada kode GL baru yang belum terpetakan,
> tampung di kategori 'Lain-lain' dan catat untuk ditinjau.
