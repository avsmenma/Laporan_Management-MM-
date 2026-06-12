# Migrasi Sumber Data LM14 & LM13 → db_wbs_raw / db_ohc / db_gc

Dokumen ini hasil rekayasa-balik (reverse engineering) rumus pada workbook acuan
`Lampiran_LM_Kebun_Sawit_Mei_2026.xlsx` dan pemetaan ke tabel mentah baru.
Tujuan: memindahkan sumber LM14 & LM13 dari tabel ringkas lama (`db_wbs`, `db_btl`)
ke tabel mentah besar (`db_wbs_raw`, `db_ohc`, `db_gc`).

## 1. Rumus LM14 di Excel (sheet `*-A`, mis. `Gunme-A`)

Untuk satu baris detail (kolom B = kode baris LM, mis. `99-01`, `41-01`):

```
Real Bulan Ini (E) = SUMIFS('DB WBS'.Nilai;
                            'DB WBS'.Budidaya = komoditi (KS/KR);
                            'DB WBS'.Period   = bulan ini;
                            'DB WBS'.Plant    = kode unit (5E11);
                            'DB WBS'.Aktifitas= kode baris LM)
Real Bulan Lalu (F) = SUMIFS(... Period = bulan-1 ...)
Real Tahun Lalu (G) = HLOOKUP ke sheet 'Tahun Lalu'         → tabel realisasi_tahun_lalu
RKO (H) / RKAP (I)  = HLOOKUP ke sheet RKO/RKAP  × 1000      → budget_rko / budget_rkap
% (J..M)            = IFERROR(E/F.. ×100; 0)
Real s.d Bulan Ini (N) = E + (Σ realisasi bulan < bulan ini, tahun sama)
Subtotal/Total      = SUM(range)                            → lm_template_row.formula (u..)
```

Baris "Gaji Staf dari WBS" (`99-01`) memakai **DB BTL** (Kode CC `SP01`), bukan DB WBS.
→ inilah 88 baris template ber-`source = 'BTL'`.

## 2. Pemetaan kolom: tabel lama/acuan → tabel mentah baru

### DB WBS (acuan 11 kolom) → `db_wbs_raw` (48 kolom)
| Acuan "DB WBS" | Peran SUMIFS | `db_wbs_raw` | Kolom file |
|---|---|---|---|
| A Budidaya | kriteria komoditi | `komoditi` | H Komoditi |
| B Plant | kriteria unit | `plant_code` (`plant`) | B Plant |
| D Period | kriteria bulan | `period` | I Period |
| E Aktifitas | **kriteria kode baris** | `aktivitas` | P Aktifitas |
| J Nilai | **sum range** | `value` | Y Value |
| I Klasifikasi | dipakai LM13 | `klasifikasi` | AQ Klasifikasi |
| K Fisik | fisik/qty | `qty` | AC Qty |

Verifikasi nilai nyata `db_wbs_raw`: `aktivitas` berisi `90-01/99-01/41-xx` (sama
seperti acuan), `value` = nilai rupiah, `komoditi=KS`, `plant_code=5E01`, `period=5`. ✅

### DB BTL (acuan 10 kolom) → `db_ohc`  (PERLU KONFIRMASI)
| Acuan "DB BTL" | Peran | `db_ohc` (kandidat) |
|---|---|---|
| A Kode | komoditi | `komoditi` |
| B Plant | unit | `plant_code` |
| D Period | bulan | `period` |
| E Kode CC (BT01/SP01) | **kriteria** | `lock`  ← perlu dicek (nilai `lock`=`BT01`) |
| G Cost Element | (511..) | `cost_element` |
| I Klasifikasi | LM13 | `klasifikasi` |
| J Nilai | sum range | `value_obj_crcy` |

> Catatan: di data nyata, kolom **`lock`** db_ohc berisi `BT01` (sama gaya `Kode CC`),
> sedangkan `cost_center` berisi kode SAP penuh (`5E01BT01KS`). Mana yang dipakai
> untuk kriteria `SP01`/`BT01` **wajib divalidasi** terhadap angka workbook.

### DB GC → `db_gc`
Pada model **saat ini**, baris "ALOKASI GC *" memakai `source = 'WBS'` (aktivitas `90-xx`),
bukan tabel GC terpisah. Peran `db_gc` pada LM14/LM13 baru **belum pasti** dan perlu
ditetapkan bersama (kemungkinan untuk perbaikan alokasi GC atau untuk LM13).

## 3. Query target LM14 (sumber `db_wbs_raw`)

```sql
-- Real Bulan Ini (baris ber-source WBS)
SELECT COALESCE(SUM(value),0)
FROM   db_wbs_raw
WHERE  batch_id   = :batch_id
  AND  komoditi   = :komoditi      -- KS / KR
  AND  plant_code = :plant_code    -- 5E11
  AND  period     = :month
  AND  aktivitas  = :kode;         -- 99-01, 41-01, ...

-- Real Bulan Lalu / Real s.d : sama, ganti filter period
--   bulan lalu   : period = :month-1  (tahun sama → JOIN batch.year)
--   s.d bln ini  : period <= :month   (tahun sama)
```

RKO/RKAP/Tahun Lalu tetap dari `budget_rko`, `budget_rkap`, `realisasi_tahun_lalu`
(tidak berubah).

## 4. Tabel lama: hapus atau tidak?

**Yang DIGANTIKAN (boleh dihapus SETELAH mesin hitung baru cocok dgn workbook Mei 2026):**
- `db_wbs`  → digantikan `db_wbs_raw`
- `db_btl`  → digantikan `db_ohc`

**JANGAN dihapus (masih dipakai):**
- `budget_rkap`, `budget_rko`, `realisasi_tahun_lalu` (kolom RKO/RKAP/Thn Lalu)
- `alokasi_areal`, `alokasi_produksi` (LM13)
- `lm_template_row` (struktur baris — inti), `lm16_account_map`
- `report_lm13`, `report_lm14`, `report_lm16` (output — cukup di-regenerate)
- `ref_unit`, `ref_unit_komoditi`, `ref_klasifikasi`, `batch`
- `db_pks`, `pks_biaya`, `pks_produksi` (PABRIK/LM16 — di luar lingkup perubahan ini)
- infrastruktur: `users`, `roles`, `sessions`, `cache*`, `jobs*`, dst.

### SQL (jalankan HANYA setelah validasi angka)
```sql
-- regenerate output (atau biarkan report:generate menimpa):
TRUNCATE TABLE report_lm14;
TRUNCATE TABLE report_lm13;

-- buang tabel sumber lama yang sudah digantikan:
DROP TABLE IF EXISTS db_wbs;
DROP TABLE IF EXISTS db_btl;
```
> Cara rapi (disarankan): buat migration `drop_obsolete_raw_tables` agar skema tetap
> terlacak, bukan DROP manual.

## 5. Status & yang perlu ditetapkan sebelum mengubah mesin hitung
1. Konfirmasi `db_ohc` = pengganti `DB BTL`, dan kolom kriteria `Kode CC` (`lock` vs `cost_center`).
2. Tetapkan peran `db_gc` pada LM14/LM13 baru.
3. Pastikan `db_wbs_raw`/`db_ohc`/`db_gc` SUDAH di-import untuk batch Mei 2026 agar
   hasil `report:generate` bisa diadu (selisih = 0) dengan workbook acuan — **gerbang kritis**.
