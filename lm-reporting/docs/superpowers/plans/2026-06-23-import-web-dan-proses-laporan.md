# Import Web (periode otomatis) + Tombol "Proses Laporan" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** User production (Operator/Admin) bisa upload semua data (WBS/OHC/GC + RKO/RKAP per BKU/OHC/GC) lewat web dengan periode terbaca otomatis, lalu mengisi tabel laporan cukup dengan satu tombol "Proses Laporan" — tanpa CLI.

**Architecture:** Logika import budget & orkestrasi generate dipindah dari `routes/console.php` ke service (`SpreadsheetImportService::importBudget`, `ReportGenerateService::generateBatch`) agar dipakai bersama web & CLI. Import realisasi mendeteksi bulan dari kolom periode file. Tombol web memanggil `ReportGenerateService::generateBatch` sinkron.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, Blade + Alpine.js, OpenSpout/PhpSpreadsheet, PHPUnit.

## Global Constraints

- RKO/RKAP disimpan **rupiah penuh** (tanpa ×1000); nilai disalin identik ke `budget_rko` & `budget_rkap`.
- `git add` per-file; pesan commit Bahasa Indonesia; commit kecil & sering.
- Jangan jalankan perintah destruktif (drop/wipe/fresh) tanpa konfirmasi.
- Multi-periode wajib; tiap batch (year+month) berdiri sendiri.
- Idempotensi budget per-sumber: hapus `where year + report_type='LM14' + source` lalu insert.
- Logika tidak boleh terduplikasi: CLI & web memanggil service yang sama.
- Spec acuan: `docs/superpowers/specs/2026-06-23-import-web-dan-proses-laporan-design.md`.

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_06_24_000000_add_source_to_budget_and_flag_to_batch.php` | (baru) tambah `source` di budget_rko/rkap; `needs_regenerate` di batch. |
| `app/Domain/Import/SpreadsheetImportService.php` | (ubah) 6 jenis; `detectPeriods`; `importBudget`. |
| `app/Domain/Report/ReportGenerateService.php` | (baru) `generateBatch` orkestrasi semua report 1 batch. |
| `app/Http/Controllers/Import/ImportController.php` | (ubah) dukung budget & periode auto; tandai `needs_regenerate`. |
| `app/Http/Controllers/Report/ProsesLaporanController.php` | (baru) jalankan generateBatch dari web. |
| `resources/views/import/index.blade.php` | (ubah) UI adaptif tahun/bulan per kelompok jenis. |
| `resources/views/batches/index.blade.php` | (ubah) tombol "Proses Laporan" + badge status. |
| `routes/web.php` | (ubah) route proses-laporan. |
| `routes/console.php` | (ubah) `budget:import-test` & `report:generate` jadi pemanggil tipis. |
| `tests/Feature/*` | (baru/ubah) test untuk tiap unit. |

---

## Task 1: Migrasi kolom `source` (budget) & `needs_regenerate` (batch)

**Files:**
- Create: `database/migrations/2026_06_24_000000_add_source_to_budget_and_flag_to_batch.php`
- Test: `tests/Feature/BudgetSourceColumnTest.php`

**Interfaces:**
- Produces: kolom `budget_rko.source` (string 8, nullable), `budget_rkap.source` (string 8, nullable), `batch.needs_regenerate` (boolean, default true).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BudgetSourceColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_and_batch_have_new_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('budget_rko', 'source'));
        $this->assertTrue(Schema::hasColumn('budget_rkap', 'source'));
        $this->assertTrue(Schema::hasColumn('batch', 'needs_regenerate'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BudgetSourceColumnTest`
Expected: FAIL — kolom belum ada.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_rko', function (Blueprint $table) {
            $table->string('source', 8)->nullable()->after('kode');
        });
        Schema::table('budget_rkap', function (Blueprint $table) {
            $table->string('source', 8)->nullable()->after('kode');
        });
        Schema::table('batch', function (Blueprint $table) {
            $table->boolean('needs_regenerate')->default(true)->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('budget_rko', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('budget_rkap', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('batch', fn (Blueprint $t) => $t->dropColumn('needs_regenerate'));
    }
};
```

- [ ] **Step 4: Run migration on the test DB & verify the test passes**

Run: `php artisan test --filter=BudgetSourceColumnTest`
Expected: PASS (RefreshDatabase menjalankan migrasi).

- [ ] **Step 5: Apply to dev DB**

Run: `php artisan migrate`
Expected: "Migrating: 2026_06_24_000000_add_source_to_budget_and_flag_to_batch ... DONE".

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_24_000000_add_source_to_budget_and_flag_to_batch.php tests/Feature/BudgetSourceColumnTest.php
git commit -m "feat(db): kolom source pada budget_rko/rkap & needs_regenerate pada batch"
```

---

## Task 2: Perluas jenis import jadi 6 + deteksi periode

**Files:**
- Modify: `app/Domain/Import/SpreadsheetImportService.php` (method `types()` sekitar baris 66; tambah method baru)
- Modify: `tests/Feature/ImportServiceTest.php` (test `test_import_types_only_wbs_ohc_gc` baris 19)

**Interfaces:**
- Produces:
  - `SpreadsheetImportService::types(): array` → `[key => label]` untuk 6 jenis.
  - `SpreadsheetImportService::isBudget(string $type): bool`
  - `SpreadsheetImportService::budgetSource(string $type): ?string` (BKU/OHC/GC)
  - `SpreadsheetImportService::detectPeriods(string $path, string $type): array` (daftar bulan int distinct dari kolom periode; hanya jenis realisasi)

- [ ] **Step 1: Update the existing type test + add behavior tests**

Ganti isi `test_import_types_only_wbs_ohc_gc` dan tambah test baru di `tests/Feature/ImportServiceTest.php`:

```php
    public function test_types_now_include_realisasi_and_budget(): void
    {
        $this->assertSame(
            ['wbs', 'ohc', 'gc', 'rko_bku', 'rko_ohc', 'rko_gc'],
            array_keys(SpreadsheetImportService::types())
        );
        $this->assertFalse(SpreadsheetImportService::isBudget('wbs'));
        $this->assertTrue(SpreadsheetImportService::isBudget('rko_ohc'));
        $this->assertSame('OHC', SpreadsheetImportService::budgetSource('rko_ohc'));
        $this->assertNull(SpreadsheetImportService::budgetSource('wbs'));
    }

    public function test_detect_periods_reads_distinct_month_from_gc_file(): void
    {
        $path = $this->buildGcFile(); // baris H2=5, H3=5 → kolom Period (H) = bulan 5
        $this->assertSame([5], app(SpreadsheetImportService::class)->detectPeriods($path, 'gc'));
        unlink($path);
    }
```

Hapus method lama `test_import_types_only_wbs_ohc_gc` (digantikan di atas).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ImportServiceTest`
Expected: FAIL — `isBudget`/`detectPeriods` belum ada & types masih 3.

- [ ] **Step 3: Implement in SpreadsheetImportService**

Ganti method `types()` (baris 66-73) dan tambah method baru tepat di bawahnya:

```php
    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'wbs' => 'DB WBS',
            'ohc' => 'DB OHC',
            'gc' => 'DB GC',
            'rko_bku' => 'RKO/RKAP — BKU',
            'rko_ohc' => 'RKO/RKAP — OHC',
            'rko_gc' => 'RKO/RKAP — GC',
        ];
    }

    /** Jenis realisasi (per bulan) vs anggaran (per tahun). */
    public static function isBudget(string $type): bool
    {
        return str_starts_with($type, 'rko_');
    }

    /** Sumber budget (BKU/OHC/GC) untuk jenis rko_*, atau null. */
    public static function budgetSource(string $type): ?string
    {
        return match ($type) {
            'rko_bku' => 'BKU',
            'rko_ohc' => 'OHC',
            'rko_gc' => 'GC',
            default => null,
        };
    }

    /**
     * Bulan distinct (1..12) dari kolom periode file realisasi. Dipakai untuk
     * mengisi dropdown bulan otomatis (asumsi domain: 1 file = 1 bulan).
     *
     * @return array<int, int>
     */
    public function detectPeriods(string $path, string $type): array
    {
        $periodIndex = match ($type) {
            'wbs' => array_search('period', self::WBS_COLUMNS, true),
            'ohc' => array_search('period', self::OHC_COLUMNS, true),
            'gc' => array_search('period', self::GC_COLUMNS, true),
            default => false,
        };
        if ($periodIndex === false) {
            return [];
        }

        $found = [];
        foreach ($this->dataRows($path) as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }
            $raw = $values[$periodIndex] ?? null;
            if (is_numeric($raw)) {
                $m = (int) $raw;
                if ($m >= 1 && $m <= 12) {
                    $found[$m] = true;
                }
            }
        }
        ksort($found);

        return array_keys($found);
    }
```

Catatan: `import()` & `preview()` memakai `abort_unless(array_key_exists($type, self::types()))`. Untuk jenis budget, `preview()`/`import()` lama TIDAK boleh dipanggil — controller (Task 7) memanggil jalur budget terpisah. Tambahkan guard di awal `import()` (baris 76) & `preview()` (baris 112):

```php
        abort_if(self::isBudget($type), 422, 'Gunakan importBudget() untuk jenis anggaran.');
```

(letakkan tepat setelah baris `abort_unless(...)` di masing-masing method).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ImportServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Import/SpreadsheetImportService.php tests/Feature/ImportServiceTest.php
git commit -m "feat(import): 6 jenis import + deteksi periode realisasi"
```

---

## Task 3: `importBudget()` — pindahkan logika RKO/RKAP ke service

**Files:**
- Modify: `app/Domain/Import/SpreadsheetImportService.php` (tambah `importBudget` + helper privat)
- Test: `tests/Feature/BudgetImportTest.php`

**Interfaces:**
- Consumes: kolom `source` (Task 1); `RefUnit`, `lm_template_row`.
- Produces:
  - `SpreadsheetImportService::importBudget(int $year, string $type, UploadedFile|string $file, ?int $userId = null): ImportResult`
    - Memetakan satu file budget (BKU/OHC/GC) → `budget_rko` + `budget_rkap` + `budget_source`.
    - Idempoten per `(year, report_type='LM14', source)`.
    - `rko_gc` → hanya `budget_source` (audit), tidak ke budget_rko/rkap.
    - `ImportResult.rowCount` = jumlah baris budget_rko yang ditulis (0 untuk GC).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BudgetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bku_then_ohc_do_not_overwrite_each_other(): void
    {
        // Unit kebun + baris template LM14 yang cocok (komoditi KS).
        RefUnit::query()->create(['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => 'BT01', 'urutan' => 2, 'uraian' => 'Y', 'row_type' => 'detail'],
        ]);

        $bku = $this->buildBudgetFile('41-01', 1000.0);   // BKU: aktifitas WBS
        $ohc = $this->buildBudgetFile('BT01', 2000.0);     // OHC: kode CC

        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $bku);
        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_ohc', $ohc);

        // Kedua sumber tetap ada (tidak saling menimpa).
        $this->assertSame(1, DB::table('budget_rko')->where('source', 'BKU')->count());
        $this->assertSame(1, DB::table('budget_rko')->where('source', 'OHC')->count());
        $this->assertEqualsWithDelta(3000.0, (float) DB::table('budget_rko')->sum('nilai'), 0.5);
        // budget_rkap identik dengan budget_rko.
        $this->assertEqualsWithDelta(3000.0, (float) DB::table('budget_rkap')->sum('nilai'), 0.5);

        // Re-import BKU hanya mengganti baris BKU, OHC utuh.
        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $bku);
        $this->assertSame(1, DB::table('budget_rko')->where('source', 'OHC')->count());

        unlink($bku);
        unlink($ohc);
    }

    /** File budget: kolom A=komoditi, B=plant, D=period, E=kode, J=nilai. */
    private function buildBudgetFile(string $kode, float $nilai): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['Komoditi', 'Plant', 'C', 'Period', 'Kode', 'Obj', 'CE', 'CEdesc', 'Klas', 'Nilai'] as $i => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $label);
        }
        $sheet->setCellValueExplicit('A2', 'KS', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B2', '5E11', DataType::TYPE_STRING);
        $sheet->setCellValue('D2', 5);
        $sheet->setCellValueExplicit('E2', $kode, DataType::TYPE_STRING);
        $sheet->setCellValue('J2', $nilai);

        $path = tempnam(sys_get_temp_dir(), 'bud').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BudgetImportTest`
Expected: FAIL — `importBudget` belum ada.

- [ ] **Step 3: Implement `importBudget` in SpreadsheetImportService**

Tambah method berikut (pindahan logika dari `routes/console.php` `budget:import-test`, dipecah per-file). Letakkan setelah `import()`:

```php
    /**
     * Impor satu file anggaran (BKU/OHC/GC) ke budget_rko + budget_rkap + budget_source.
     * Idempoten per (year, report_type=LM14, source). GC tidak dipetakan ke LM14 (audit saja).
     */
    public function importBudget(int $year, string $type, UploadedFile|string $file, ?int $userId = null): ImportResult
    {
        abort_unless(self::isBudget($type), 422, 'Jenis bukan anggaran.');
        $source = self::budgetSource($type); // BKU/OHC/GC
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        $unitType = RefUnit::query()->get(['code', 'type'])
            ->mapWithKeys(fn ($u) => [strtoupper((string) $u->code) => $u->type])->all();
        $lm14 = DB::table('lm_template_row')
            ->where('report_type', 'LM14')->whereNotNull('kode')->where('kode', '<>', '')
            ->get(['komoditi', 'kode'])
            ->mapWithKeys(fn ($r) => [strtoupper((string) $r->komoditi).'|'.$r->kode => true])->all();

        // Indeks kolom 0-based: A=komoditi(0) B=plant(1) D=period(3) E=kode(4)
        // F=obj(5) G=ce(6) H=cedesc(7) I=klas(8) J=nilai(9) K=fisik(10)
        [$C_KOM, $C_PLANT, $C_PERIOD, $C_KODE, $C_OBJ, $C_CE, $C_CEDESC, $C_KLAS, $C_NILAI, $C_FISIK]
            = [0, 1, 3, 4, 5, 6, 7, 8, 9, 10];

        $str = fn ($v, int $len): ?string => ($t = trim((string) ($v ?? ''))) === '' ? null : mb_substr($t, 0, $len);
        $acc = [];      // "KOM|PLANT|period|kode" => nilai
        $rawSrc = [];
        $errors = [];
        $kept = 0;

        foreach ($this->dataRows($path) as $c) {
            if ($this->isEmptyRow($c)) {
                continue;
            }
            $kom = strtoupper(trim((string) ($c[$C_KOM] ?? '')));
            $plant = strtoupper(trim((string) ($c[$C_PLANT] ?? '')));
            $kode = trim((string) ($c[$C_KODE] ?? ''));
            if ($kom === '' || $plant === '' || $kode === '') {
                continue;
            }
            $nilai = $this->numericValue($c[$C_NILAI] ?? 0);
            $period = is_numeric($c[$C_PERIOD] ?? null) ? (int) $c[$C_PERIOD] : null;

            // GC: hanya audit ke budget_source (tak ada baris template LM14).
            $mappable = $source !== 'GC';
            if ($mappable) {
                if (($unitType[$plant] ?? null) !== 'KEBUN') {
                    $errors[] = "Unit non-kebun dilewati: {$plant}";

                    continue;
                }
                if (! isset($lm14[$kom.'|'.$kode])) {
                    $errors[] = "Kode di luar LM14: {$kom}/{$kode}";

                    continue;
                }
                $k = $kom.'|'.$plant.'|'.($period ?? '').'|'.$kode;
                $acc[$k] = ($acc[$k] ?? 0) + $nilai;
                $kept++;
            }

            $rawSrc[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM14', 'kode' => $kode, 'source' => $source, 'period' => $period,
                'object_name' => $str($c[$C_OBJ] ?? null, 250),
                'cost_element' => $str($c[$C_CE] ?? null, 40),
                'cost_element_desc' => $str($c[$C_CEDESC] ?? null, 250),
                'klasifikasi' => $str($c[$C_KLAS] ?? null, 60),
                'nilai' => round($nilai, 2),
                'fisik' => is_numeric($c[$C_FISIK] ?? null) ? (float) $c[$C_FISIK] : null,
            ];
        }

        $rows = [];
        foreach ($acc as $key => $nilai) {
            [$kom, $plant, $period, $kode] = explode('|', $key, 4);
            $rows[] = [
                'year' => $year, 'komoditi' => $kom, 'plant_code' => $plant,
                'report_type' => 'LM14', 'kode' => $kode, 'source' => $source,
                'period' => $period === '' ? null : (int) $period,
                'nilai' => round($nilai, 2),
            ];
        }

        DB::transaction(function () use ($rows, $rawSrc, $year, $source): void {
            // Idempoten per-sumber: hapus hanya baris sumber ini.
            DB::table('budget_rko')->where('year', $year)->where('report_type', 'LM14')->where('source', $source)->delete();
            DB::table('budget_rkap')->where('year', $year)->where('report_type', 'LM14')->where('source', $source)->delete();
            DB::table('budget_source')->where('year', $year)->where('report_type', 'LM14')->where('source', $source)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('budget_rko')->insert($chunk);
                DB::table('budget_rkap')->insert($chunk);
            }
            foreach (array_chunk($rawSrc, 500) as $chunk) {
                DB::table('budget_source')->insert($chunk);
            }
        });

        $result = new ImportResult(rowCount: count($rows), errors: array_slice($errors, 0, 50));

        ImportUploadLog::query()->create([
            'batch_id' => null,
            'user_id' => $userId,
            'jenis' => $type,
            'filename' => $filename,
            'row_count' => $result->rowCount,
            'error_count' => $result->errorCount(),
            'errors' => $result->errors,
            'uploaded_at' => now(),
        ]);

        return $result;
    }
```

Verifikasi helper yang dipakai sudah ada di kelas: `isEmptyRow()`, dan tambahkan helper numerik publik/privat `numericValue()` bila belum ada (cek dulu; logika parsing angka Indonesia/Inggris sudah ada di `rawCell`/console). Bila belum ada method `numericValue`, tambahkan:

```php
    private function numericValue($v): float
    {
        if ($v === null) {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = trim((string) $v);
        if ($v === '' || $v === '-') {
            return 0.0;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }

        return (float) preg_replace('/[^0-9.\-]/', '', $v);
    }
```

Catatan: pastikan `import_upload_logs.batch_id` nullable. Bila NOT NULL, tambahkan migrasi kecil di Task 1 untuk membuatnya nullable (cek skema dulu sebelum coding).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BudgetImportTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Import/SpreadsheetImportService.php tests/Feature/BudgetImportTest.php
git commit -m "feat(import): importBudget per-sumber (BKU/OHC/GC) idempoten ke budget_rko/rkap"
```

---

## Task 4: `budget:import-test` jadi pemanggil tipis `importBudget`

**Files:**
- Modify: `routes/console.php` (blok `budget:import-test`, baris 637-905)

**Interfaces:**
- Consumes: `SpreadsheetImportService::importBudget` (Task 3).

- [ ] **Step 1: Replace command body with thin wrapper**

Ganti seluruh isi closure `budget:import-test` (baris 637-905) menjadi:

```php
Artisan::command('budget:import-test {--dir=} {--year=2026}', function (SpreadsheetImportService $service): int {
    $year = (int) $this->option('year');
    if ($year < 2000 || $year > 2100) {
        $this->error('Opsi --year tidak wajar: '.$year);

        return 1;
    }
    $dir = (string) $this->option('dir');
    if ($dir === '') {
        $dir = dirname(base_path()).DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'rko_rkap';
    }
    if (! is_dir($dir)) {
        $this->error("Direktori tidak ditemukan: {$dir}");

        return 1;
    }

    $find = function (string $needle) use ($dir): ?string {
        foreach (glob(rtrim($dir, "/\\").DIRECTORY_SEPARATOR.'*.xlsx') ?: [] as $f) {
            if (str_starts_with(basename($f), '~$')) {
                continue;
            }
            if (stripos(basename($f), $needle) !== false) {
                return $f;
            }
        }

        return null;
    };

    foreach (['BKU' => 'rko_bku', 'OHC' => 'rko_ohc', 'GC' => 'rko_gc'] as $needle => $type) {
        $file = $find($needle);
        if ($file === null) {
            $this->warn("File {$needle} tidak ditemukan (lewati).");

            continue;
        }
        $r = $service->importBudget($year, $type, $file);
        $this->info("{$needle}: {$r->rowCount} baris budget · {$r->errorCount()} dilewati.");
    }

    $this->warn('Jalankan report:generate / tombol Proses Laporan agar RKO/RKAP termaterialisasi.');

    return 0;
})->purpose('Impor RKO/RKAP (docs/rko_rkap) per-sumber via importBudget.');
```

Pastikan `use App\Domain\Import\SpreadsheetImportService;` ada di atas `routes/console.php` (tambahkan bila belum).

- [ ] **Step 2: Verify it runs (manual, against testing folder)**

Run: `php artisan budget:import-test --year=2026`
Expected: tiga baris "BKU/OHC/GC: N baris ..." tanpa error fatal.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "refactor(budget): budget:import-test memanggil importBudget per-sumber"
```

---

## Task 5: `ReportGenerateService::generateBatch`

**Files:**
- Create: `app/Domain/Report/ReportGenerateService.php`
- Test: `tests/Feature/ReportGenerateServiceTest.php`

**Interfaces:**
- Consumes: `Lm13Service::generate(Batch,RefUnit,string)`, `Lm14Service::generate(Batch,RefUnit,string)`, `Lm16Service::generate(Batch,RefUnit)`; `RefUnit` (relasi `komoditis`).
- Produces:
  - `ReportGenerateService::generateBatch(Batch $batch): array` → ringkasan:
    `['lm14' => int, 'lm13' => int, 'lm16' => int, 'units' => int, 'detail' => array<string,int>]`
    (jumlah baris dimaterialisasi per report; `detail` = "TYPE unit komoditi" => count).
  - Menandai `batch.processed_at = now()`, `batch.needs_regenerate = false`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Domain\Report\ReportGenerateService;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportGenerateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_batch_materializes_and_marks_processed(): void
    {
        $this->seed(\Database\Seeders\LmTemplateRowSeeder::class); // jika ada; jika tidak, isi lm_template_row minimal
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5,
            'status' => 'draft', 'needs_regenerate' => true,
        ]);
        RefUnit::query()->create(['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS']);

        $summary = app(ReportGenerateService::class)->generateBatch($batch);

        $this->assertArrayHasKey('lm14', $summary);
        $this->assertArrayHasKey('units', $summary);

        $batch->refresh();
        $this->assertNotNull($batch->processed_at);
        $this->assertFalse((bool) $batch->needs_regenerate);
    }
}
```

(Bila seeder template tidak tersedia di test, ganti dengan insert minimal `lm_template_row` + relasi `ref_unit_komoditi` sesuai skema — cek struktur sebelum menulis.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReportGenerateServiceTest`
Expected: FAIL — kelas belum ada.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Domain\Report;

use App\Models\Batch;
use App\Models\RefUnit;

class ReportGenerateService
{
    public function __construct(
        private Lm13Service $lm13,
        private Lm14Service $lm14,
        private Lm16Service $lm16,
    ) {}

    /**
     * Materialisasi seluruh report (LM14, LM13, LM16) untuk satu batch:
     * LM14/LM13 = semua unit KEBUN per komoditi; LM16 = semua unit PABRIK.
     *
     * @return array{lm14:int, lm13:int, lm16:int, units:int, detail:array<string,int>}
     */
    public function generateBatch(Batch $batch): array
    {
        $detail = [];
        $totals = ['lm14' => 0, 'lm13' => 0, 'lm16' => 0];
        $unitIds = [];

        // KEBUN: LM14 & LM13 per komoditi yang dimiliki unit.
        $kebun = RefUnit::query()->where('type', 'KEBUN')->with('komoditis')->orderBy('code')->get();
        foreach ($kebun as $unit) {
            $unitIds[$unit->id] = true;
            foreach ($unit->komoditis as $km) {
                $kom = strtoupper((string) $km->komoditi);
                $c14 = $this->lm14->generate($batch, $unit, $kom)->count();
                $c13 = $this->lm13->generate($batch, $unit, $kom)->count();
                $totals['lm14'] += $c14;
                $totals['lm13'] += $c13;
                $detail["LM14 {$unit->code} {$kom}"] = $c14;
                $detail["LM13 {$unit->code} {$kom}"] = $c13;
            }
        }

        // PABRIK: LM16.
        $pabrik = RefUnit::query()->where('type', 'PABRIK')->orderBy('code')->get();
        foreach ($pabrik as $unit) {
            $unitIds[$unit->id] = true;
            $c16 = $this->lm16->generate($batch, $unit)->count();
            $totals['lm16'] += $c16;
            $detail["LM16 {$unit->code}"] = $c16;
        }

        $batch->forceFill(['processed_at' => now(), 'needs_regenerate' => false])->save();

        return [...$totals, 'units' => count($unitIds), 'detail' => $detail];
    }
}
```

Catatan: konfirmasi nama relasi `komoditis` pada `RefUnit` & kolomnya (`komoditi`). Console memakai `whereHas('komoditis', ...)` (baris 372) → relasi ini ada. Verifikasi properti `komoditi` di model pivot/relasi sebelum menulis.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ReportGenerateServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Report/ReportGenerateService.php tests/Feature/ReportGenerateServiceTest.php
git commit -m "feat(report): ReportGenerateService.generateBatch materialisasi 1 batch"
```

---

## Task 6: `report:generate` memakai service

**Files:**
- Modify: `routes/console.php` (blok `report:generate`, baris 316-391)

**Interfaces:**
- Consumes: `ReportGenerateService::generateBatch` + service per-type yang sudah ada (untuk mode `--type` spesifik tetap dipertahankan).

- [ ] **Step 1: Tambah mode "semua" lewat service, pertahankan mode per-type**

Sisipkan di awal closure (setelah resolusi `$batch`, sebelum cabang LM16) jalur: bila `--type` kosong → panggil `generateBatch` lalu tampilkan ringkasan & return 0. Tambahkan parameter service ke signature closure:

```php
Artisan::command('report:generate {--type=} {--batch=} {--unit=} {--komoditi=KS}', function (Lm13Service $lm13Service, Lm14Service $lm14Service, Lm16Service $lm16Service, \App\Domain\Report\ReportGenerateService $generator): int {
    $type = strtoupper((string) $this->option('type'));
    $batchInput = (string) $this->option('batch');
    // ... (validasi $batchInput & resolusi $batch tetap seperti sekarang) ...

    if ($type === '') {
        $summary = $generator->generateBatch($batch);
        $this->info("Selesai: LM14={$summary['lm14']} LM13={$summary['lm13']} LM16={$summary['lm16']} ({$summary['units']} unit).");

        return 0;
    }
    // ... sisa logika per-type (LM13/LM14/LM16) TETAP seperti sekarang ...
```

Pertahankan validasi `in_array($type, ['LM13','LM14','LM16'])` hanya untuk cabang per-type (pindahkan setelah cek `$type === ''`).

- [ ] **Step 2: Verify both modes run**

Run: `php artisan report:generate --batch=<kode-batch-dev>`
Expected: "Selesai: LM14=.. LM13=.. LM16=.. (N unit)."
Run: `php artisan report:generate --type=LM14 --batch=<kode>`
Expected: tetap berfungsi seperti sebelumnya.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "refactor(report): report:generate tanpa --type memanggil generateBatch"
```

---

## Task 7: ImportController — dukung budget & periode otomatis

**Files:**
- Modify: `app/Http/Controllers/Import/ImportController.php`
- Test: `tests/Feature/ImportControllerBudgetTest.php`

**Interfaces:**
- Consumes: `SpreadsheetImportService::{types,isBudget,budgetSource,detectPeriods,importBudget,import,preview}`; `Batch`.
- Produces: alur web 2-langkah untuk realisasi (batch dari year+month) & budget (year saja); menandai `needs_regenerate`.

- [ ] **Step 1: Write the failing feature test (budget upload via web)**

```php
<?php

namespace Tests\Feature;

use App\Models\RefUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
// + util build xlsx mirip BudgetImportTest

class ImportControllerBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_confirm_saves_without_batch(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']); // sesuaikan dgn factory/role yg ada
        RefUnit::query()->create(['code' => '5E11', 'name' => 'A', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
        ]);

        // Langkah preview budget → respon berisi token + flag budget.
        $file = UploadedFile::fake()->createWithContent('bku.xlsx', file_get_contents($this->makeBudget('41-01', 1000)));
        $preview = $this->actingAs($admin)->post('/import', ['type' => 'rko_bku', 'year' => 2026, 'file' => $file]);
        $preview->assertOk();

        // (token/ext diambil dari view data 'pending'); lalu confirm.
        // Asersi inti: setelah confirm, budget_rko terisi & source=BKU.
        // ... lakukan confirm dgn token dari $preview->viewData('pending') ...
    }
}
```

(Detail asersi confirm disesuaikan dengan cara test mengambil `pending` dari view; minimal verifikasi `preview` menerima `year` & `type=rko_bku` tanpa `batch_id`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ImportControllerBudgetTest`
Expected: FAIL — validasi lama mewajibkan `batch_id`.

- [ ] **Step 3: Update controller**

Ubah `store()` (validasi & cabang budget vs realisasi), `confirm()` (cabang), tambahkan helper resolusi batch dari year+month. Inti perubahan:

```php
    public function store(Request $request, SpreadsheetImportService $service): View|RedirectResponse
    {
        $type = (string) $request->input('type');
        $isBudget = SpreadsheetImportService::isBudget($type);

        $rules = [
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
        if (! $isBudget) {
            $rules['month'] = ['nullable', 'integer', 'min:1', 'max:12']; // boleh kosong → auto dari file
        }
        $data = $request->validate($rules);

        $file = $request->file('file');
        $token = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $stored = $file->storeAs('import-staging', "{$token}.{$ext}", 'local');
        $path = Storage::disk('local')->path($stored);

        try {
            if ($isBudget) {
                // Budget: tanpa pratinjau tabel berat; cukup ringkasan ringan.
                $preview = ['type' => $type, 'label' => SpreadsheetImportService::types()[$type], 'columns' => [], 'rows' => [], 'total' => 0, 'budget' => true];
                $detectedMonths = [];
            } else {
                $preview = $service->preview($type, $path);
                $detectedMonths = $service->detectPeriods($path, $type);
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);

            return back()->with(['status' => 'Gagal membaca file: '.$e->getMessage(), 'import_errors' => []]);
        }

        // Bulan: pakai input user bila ada; jika kosong & terdeteksi tepat 1 → pakai itu.
        $month = $data['month'] ?? null;
        if (! $isBudget && $month === null && count($detectedMonths) === 1) {
            $month = $detectedMonths[0];
        }

        return view('import.index', [
            ...$this->indexData(),
            'preview' => $preview,
            'detected_months' => $detectedMonths,
            'pending' => [
                'token' => $token, 'ext' => $ext, 'type' => $type,
                'year' => (int) $data['year'], 'month' => $month, 'is_budget' => $isBudget,
                'filename' => $file->getClientOriginalName(),
            ],
        ]);
    }
```

`confirm()`:

```php
    public function confirm(Request $request, SpreadsheetImportService $service): RedirectResponse
    {
        $type = (string) $request->input('type');
        $isBudget = SpreadsheetImportService::isBudget($type);
        $rules = [
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext' => ['required', 'in:xlsx,xls,csv'],
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
        if (! $isBudget) {
            $rules['month'] = ['required', 'integer', 'min:1', 'max:12'];
        }
        $data = $request->validate($rules);

        $stored = "import-staging/{$data['token']}.{$data['ext']}";
        if (! Storage::disk('local')->exists($stored)) {
            return redirect()->route('import.index')->with(['status' => 'Berkas pratinjau kedaluwarsa. Unggah ulang.', 'import_errors' => []]);
        }
        $path = Storage::disk('local')->path($stored);
        $uid = $request->user()->id;

        try {
            if ($isBudget) {
                $result = $service->importBudget((int) $data['year'], $type, $path, $uid);
                // Tandai semua batch tahun itu perlu diproses ulang.
                Batch::query()->where('year', $data['year'])->update(['needs_regenerate' => true]);
                $msg = "Import anggaran {$type} selesai: {$result->rowCount} baris budget.";
            } else {
                $batch = $this->resolveBatch((int) $data['year'], (int) $data['month']);
                $result = $service->import($type, $batch, $path, $uid);
                $batch->forceFill(['needs_regenerate' => true])->save();
                $msg = "Import {$type} ke {$batch->code} selesai: {$result->rowCount} baris.";
            }
        } finally {
            Storage::disk('local')->delete($stored);
        }

        return redirect()->route('import.index')->with(['status' => $msg, 'import_errors' => $result->errors]);
    }

    /** Cari/buat batch (draft) untuk year+month. */
    private function resolveBatch(int $year, int $month): Batch
    {
        return Batch::query()->firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['code' => "Batch #{$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT), 'status' => 'draft', 'needs_regenerate' => true],
        );
    }
```

`cancel()` tetap. `indexData()` tetap (dropdown batch tidak lagi wajib, tapi daftar batch masih dipakai badge — biarkan).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ImportControllerBudgetTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Import/ImportController.php tests/Feature/ImportControllerBudgetTest.php
git commit -m "feat(import): controller dukung anggaran (year) & realisasi (year+bulan auto)"
```

---

## Task 8: ProsesLaporanController + route + RBAC

**Files:**
- Create: `app/Http/Controllers/Report/ProsesLaporanController.php`
- Modify: `routes/web.php` (grup `role:Operator,Admin`)
- Test: `tests/Feature/ProsesLaporanTest.php`

**Interfaces:**
- Consumes: `ReportGenerateService::generateBatch`; `Batch`.
- Produces: `POST /proses-laporan` (name `proses-laporan.store`) → jalankan generate, redirect dgn ringkasan.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesLaporanTest extends TestCase
{
    use RefreshDatabase;

    public function test_button_generates_and_marks_processed(): void
    {
        $op = User::factory()->create(['role' => 'Operator']);
        RefUnit::query()->create(['code' => '5E11', 'name' => 'A', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft', 'needs_regenerate' => true]);

        $res = $this->actingAs($op)->post('/proses-laporan', ['batch_id' => $batch->id]);
        $res->assertRedirect();

        $batch->refresh();
        $this->assertFalse((bool) $batch->needs_regenerate);
        $this->assertNotNull($batch->processed_at);
    }

    public function test_viewer_forbidden(): void
    {
        $viewer = User::factory()->create(['role' => 'Viewer']);
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $this->actingAs($viewer)->post('/proses-laporan', ['batch_id' => $batch->id])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProsesLaporanTest`
Expected: FAIL — route belum ada (404/405).

- [ ] **Step 3: Implement controller**

```php
<?php

namespace App\Http\Controllers\Report;

use App\Domain\Report\ReportGenerateService;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProsesLaporanController extends Controller
{
    public function store(Request $request, ReportGenerateService $generator): RedirectResponse
    {
        $data = $request->validate(['batch_id' => ['required', 'exists:batch,id']]);
        $batch = Batch::query()->findOrFail($data['batch_id']);

        $summary = $generator->generateBatch($batch);

        return back()->with('status', "Proses laporan {$batch->code} selesai: "
            ."LM14={$summary['lm14']}, LM13={$summary['lm13']}, LM16={$summary['lm16']} ({$summary['units']} unit).");
    }
}
```

- [ ] **Step 4: Add route**

Di `routes/web.php`, dalam grup `Route::middleware(['auth', 'role:Operator,Admin'])` (baris 60-70), tambahkan:

```php
    Route::post('/proses-laporan', [\App\Http\Controllers\Report\ProsesLaporanController::class, 'store'])->name('proses-laporan.store');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ProsesLaporanTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Report/ProsesLaporanController.php routes/web.php tests/Feature/ProsesLaporanTest.php
git commit -m "feat(report): tombol Proses Laporan (web) jalankan generateBatch"
```

---

## Task 9: UI Import adaptif (tahun/bulan per kelompok)

**Files:**
- Modify: `resources/views/import/index.blade.php`

**Interfaces:**
- Consumes: `$types`, `$pending`, `$detected_months`, `$preview['budget']` dari controller (Task 7).

- [ ] **Step 1: Ganti form upload dengan versi adaptif (Alpine.js)**

Ganti blok `<form ... route('import.store') ...>` (baris 48-73) menjadi:

```blade
<form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data"
      class="grid gap-4 md:grid-cols-4"
      x-data="{ type: '{{ $pending['type'] ?? 'wbs' }}', isBudget() { return this.type.startsWith('rko_'); } }">
    @csrf
    <div class="field" style="margin-bottom:0">
        <label>Jenis Import</label>
        <select name="type" x-model="type" class="field-control" required>
            <optgroup label="Realisasi (per bulan)">
                @foreach ($types as $key => $label)
                    @if (! \App\Domain\Import\SpreadsheetImportService::isBudget($key))
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endif
                @endforeach
            </optgroup>
            <optgroup label="Anggaran (per tahun)">
                @foreach ($types as $key => $label)
                    @if (\App\Domain\Import\SpreadsheetImportService::isBudget($key))
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endif
                @endforeach
            </optgroup>
        </select>
    </div>
    <div class="field" style="margin-bottom:0">
        <label>Tahun</label>
        <input name="year" type="number" min="2000" max="2100" value="{{ $pending['year'] ?? date('Y') }}" class="field-control" required>
    </div>
    <div class="field" style="margin-bottom:0" x-show="!isBudget()">
        <label>Bulan <span class="field-hint">(otomatis dari file)</span></label>
        <select name="month" class="field-control" x-bind:required="!isBudget()">
            <option value="">— deteksi dari file —</option>
            @foreach (range(1, 12) as $m)
                <option value="{{ $m }}" @selected(($pending['month'] ?? null) === $m)>{{ str_pad((string) $m, 2, '0', STR_PAD_LEFT) }}</option>
            @endforeach
        </select>
    </div>
    <div class="field" style="margin-bottom:0">
        <label>File</label>
        <input name="file" type="file" accept=".xlsx,.xls,.csv" class="field-control" required>
    </div>
    <div class="flex items-end md:col-span-4">
        <button class="btn btn-primary" type="submit">Pratinjau</button>
    </div>
</form>
```

- [ ] **Step 2: Tampilkan info bulan terdeteksi & peringatan >1 bulan**

Tepat di bawah `@isset($preview)` (baris 78), sebelum tabel, tambahkan:

```blade
@if (! empty($detected_months ?? []))
    <div class="alert {{ count($detected_months) > 1 ? 'alert-warn' : 'alert-ok' }}" style="margin:0 0 12px">
        Bulan terdeteksi dari file: <b>{{ implode(', ', array_map(fn ($m) => str_pad((string) $m, 2, '0', STR_PAD_LEFT), $detected_months)) }}</b>.
        @if (count($detected_months) > 1) Asumsi domain "1 file = 1 bulan" tidak terpenuhi — periksa file. @endif
    </div>
@endif
```

- [ ] **Step 3: Update hidden fields di form Konfirmasi**

Ganti hidden `batch_id` (baris 117) pada form `import.confirm` menjadi tahun/bulan:

```blade
<input type="hidden" name="year" value="{{ $pending['year'] }}">
@unless ($pending['is_budget'] ?? false)
    <input type="hidden" name="month" value="{{ $pending['month'] }}">
@endunless
```

- [ ] **Step 4: Manual verify**

Jalankan app, buka `/import`. Pilih jenis realisasi → field Bulan muncul; pilih jenis anggaran → field Bulan hilang. Upload file realisasi → bulan terdeteksi tampil. Confirm → tersimpan.

- [ ] **Step 5: Commit**

```bash
git add resources/views/import/index.blade.php
git commit -m "feat(ui): form import adaptif tahun/bulan + bulan otomatis dari file"
```

---

## Task 10: Tombol "Proses Laporan" + badge status di halaman Batch

**Files:**
- Modify: `resources/views/batches/index.blade.php`
- Modify: `app/Http/Controllers/Master/BatchController.php` (pastikan view dapat field status; cek `index()` mengirim `batches`)

**Interfaces:**
- Consumes: route `proses-laporan.store`; field `batch.needs_regenerate`, `batch.processed_at`.

- [ ] **Step 1: Tambah kolom status & tombol pada daftar batch**

Di `resources/views/batches/index.blade.php`, pada baris tabel batch tambahkan sel status & aksi (sesuaikan dengan struktur tabel yang ada):

```blade
<td>
    @if ($batch->needs_regenerate)
        <span class="pill pill-warn"><span class="dot"></span>Perlu diproses</span>
    @else
        <span class="pill pill-ok"><span class="dot"></span>Terakhir diproses: {{ $batch->processed_at?->format('Y-m-d H:i') ?? '-' }}</span>
    @endif
</td>
<td>
    <form method="POST" action="{{ route('proses-laporan.store') }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Memproses…'">
        @csrf
        <input type="hidden" name="batch_id" value="{{ $batch->id }}">
        <button class="btn btn-primary btn-sm" type="submit">Proses Laporan</button>
    </form>
</td>
```

Tambahkan juga header `<th>Status</th><th>Aksi</th>` pada `<thead>` tabel batch.

- [ ] **Step 2: Manual verify**

Buka `/batches`. Setelah import baru, badge "Perlu diproses" muncul. Klik "Proses Laporan" → status berubah jadi "Terakhir diproses: <waktu>", flash sukses tampil.

- [ ] **Step 3: Commit**

```bash
git add resources/views/batches/index.blade.php app/Http/Controllers/Master/BatchController.php
git commit -m "feat(ui): tombol Proses Laporan & badge status pada halaman Batch"
```

---

## Task 11: Verifikasi end-to-end (skenario reset user)

**Files:** (tidak ada perubahan kode; verifikasi)

- [ ] **Step 1: Jalankan seluruh test**

Run: `php artisan test`
Expected: semua hijau.

- [ ] **Step 2: Skenario reset manual (dev)**

1. `/data` (Admin) → Hapus Semua (ketik `HAPUS`).
2. `/import` → Tahun 2026 → upload WBS (bulan auto) → Pratinjau → Konfirmasi. Ulangi OHC, GC.
3. `/import` → Tahun 2026 → upload RKO BKU, lalu OHC, lalu GC (anggaran).
4. `/batches` → klik "Proses Laporan" untuk Mei 2026.
5. Buka Report Viewer → bandingkan baris kunci dengan workbook acuan Mei 2026 (selisih 0).

- [ ] **Step 3: Commit catatan hasil (opsional)**

Bila ada temuan angka, catat sebagai sub-tugas (jangan paksakan angka agar "cocok").

---

## Self-Review Notes

- **Spec coverage:** 6 jenis import (Task 2,7,9) ✓; periode otomatis (Task 2,7,9) ✓; idempotensi per-sumber + kolom source (Task 1,3) ✓; tombol Proses Laporan + service (Task 5,6,8,10) ✓; badge status (Task 1,5,7,10) ✓; reset flow (Task 11) ✓; no-duplikasi logika (Task 4,6) ✓.
- **Verifikasi sebelum coding:** (a) `import_upload_logs.batch_id` nullable? (b) nama relasi `RefUnit::komoditis` & properti `komoditi`. (c) `lm_template_row` kolom (`urutan`,`uraian`,`row_type`) untuk seed test. (d) `User` factory & cara set `role`. Sesuaikan kode test bila skema berbeda — ini satu-satunya titik asumsi.
- **Type consistency:** `generateBatch(): array{lm14,lm13,lm16,units,detail}` dipakai konsisten di Task 5,6,8. `importBudget(int,string,file,?int): ImportResult` konsisten Task 3,4,7.
