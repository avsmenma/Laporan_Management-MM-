# Halaman Areal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman menu Areal: tabel pivot luas & jumlah pokok per Status Blok/Petak × Tahun Tanam (baris) × AFD/Divisi (kolom), dengan import file (sheet DB) lewat alur /import yang sudah ada.

**Architecture:** Tabel `areal_blok` (per blok, per batch tahun+bulan) diisi import jenis baru `areal` (baca sheet "DB"). Endpoint `/report-data/areal` mem-pivot data → `{afds, rows}` dengan subtotal per status + grand total. Halaman `/areal` (Blade+Alpine+Tabulator) membangun kolom AFD dinamis.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, OpenSpout/PhpSpreadsheet, Blade + Alpine.js + Tabulator (global `window.Tabulator`), PHPUnit. Build: Vite/Tailwind.

## Global Constraints

- Luas = DB kol J "Luas Tanam (Ha)" → **2 desimal**. Jlh Pokok = DB kol N "Total Pokok Produktif" → **bulat**. (Terverifikasi: ΣJ=56113.698, ΣN=6836640 = Grand Total VIEW.)
- Status order baris: **ATTP, TBM, TM, TU** (lalu status lain alfabetis). Tiap status → baris Tahun Tanam asc → "{Status} Total". Ditutup "Grand Total".
- Kolom AFD **dinamis** (hanya yang ada utk unit terpilih), urut numerik; Total Luas/Pokok di ujung.
- Areal disimpan **per batch (tahun+bulan)**; idempoten per batch.
- DB sheet kolom 0-based: A0=status, B1=status_blok_petak, C2=plant, D3=divisi, E4=kode_blok, F5=tgl_mulai, G6=tgl_sampai, H7=project_def, I8=deskripsi, J9=luas_tanam, K10=tahun_tanam, L11=total_pokok, M12=luas_ha, N13=total_pokok_produktif, O14=kondisi, P15=jenis_tanah, Q16=gis_id, R17=unit_kerja, S18=komoditi.
- `git add` per-file; commit Bahasa Indonesia. Perubahan JS/CSS → `npm run build` + scp saat deploy; restart worker bila kode Job/Service berubah.
- Spec: `docs/superpowers/specs/2026-06-23-halaman-areal-design.md`.

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_06_26_000000_create_areal_blok_table.php` | (baru) tabel areal per blok. |
| `app/Models/ArealBlok.php` | (baru) model. |
| `app/Domain/Import/SpreadsheetImportService.php` | (ubah) jenis `areal`; baca sheet "DB"; importAreal; preview & rowCount sadar-sheet. |
| `app/Jobs/ProcessImport.php` | (ubah kecil) total via `rowCountForType`. |
| `app/Http/Controllers/Api/ArealController.php` | (baru) endpoint pivot + halaman. |
| `resources/views/areal/index.blade.php` | (baru) halaman + JS Tabulator dinamis. |
| `resources/views/import/index.blade.php` | (ubah) jenis "Areal" (Kategori hidden, bulan tampil). |
| `routes/web.php` | (ubah) `/areal` page + `/report-data/areal`. |

---

## Task 1: Tabel & model `areal_blok`

**Files:**
- Create: `database/migrations/2026_06_26_000000_create_areal_blok_table.php`
- Create: `app/Models/ArealBlok.php`
- Test: `tests/Feature/ArealBlokModelTest.php`

**Interfaces:**
- Produces: tabel `areal_blok` + model `App\Models\ArealBlok` (table `areal_blok`, guarded=[], casts luas_tanam/luas_ha→decimal:2, tahun_tanam/total_pokok/total_pokok_produktif→integer).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArealBlokModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_model(): void
    {
        foreach (['batch_id', 'status_blok_petak', 'plant_code', 'divisi', 'tahun_tanam', 'luas_tanam', 'total_pokok_produktif', 'komoditi'] as $c) {
            $this->assertTrue(Schema::hasColumn('areal_blok', $c), "kolom {$c}");
        }
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $row = ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => 'TM', 'plant_code' => '5E01',
            'divisi' => 'AFD07', 'tahun_tanam' => 2012, 'luas_tanam' => 7.2,
            'total_pokok_produktif' => 647, 'komoditi' => 'KS',
        ]);
        $this->assertSame('TM', $row->status_blok_petak);
        $this->assertSame(647, $row->total_pokok_produktif);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArealBlokModelTest` → FAIL (tabel/model belum ada).

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areal_blok', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->string('status', 20)->nullable();
            $table->string('status_blok_petak', 20)->nullable();
            $table->string('plant_code', 12)->nullable();
            $table->string('divisi', 20)->nullable();
            $table->string('kode_blok', 40)->nullable();
            $table->string('tanggal_mulai', 30)->nullable();
            $table->string('tanggal_sampai', 30)->nullable();
            $table->string('project_definition', 60)->nullable();
            $table->string('deskripsi', 150)->nullable();
            $table->decimal('luas_tanam', 16, 2)->default(0);
            $table->smallInteger('tahun_tanam')->nullable();
            $table->integer('total_pokok')->nullable();
            $table->decimal('luas_ha', 16, 2)->nullable();
            $table->integer('total_pokok_produktif')->nullable();
            $table->string('kondisi_areal', 30)->nullable();
            $table->string('jenis_tanah', 30)->nullable();
            $table->string('gis_id', 60)->nullable();
            $table->string('unit_kerja', 120)->nullable();
            $table->string('komoditi', 10)->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'komoditi', 'plant_code', 'status_blok_petak', 'divisi', 'tahun_tanam'], 'idx_areal');
            $table->foreign('batch_id')->references('id')->on('batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areal_blok');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArealBlok extends Model
{
    protected $table = 'areal_blok';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'luas_tanam' => 'decimal:2',
            'luas_ha' => 'decimal:2',
            'tahun_tanam' => 'integer',
            'total_pokok' => 'integer',
            'total_pokok_produktif' => 'integer',
        ];
    }
}
```

- [ ] **Step 5: Run test + migrate**

Run: `php artisan test --filter=ArealBlokModelTest` → PASS. `php artisan migrate`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_26_000000_create_areal_blok_table.php app/Models/ArealBlok.php tests/Feature/ArealBlokModelTest.php
git commit -m "feat(areal): tabel & model areal_blok"
```

---

## Task 2: Import jenis `areal` (baca sheet DB)

**Files:**
- Modify: `app/Domain/Import/SpreadsheetImportService.php`
- Modify: `app/Jobs/ProcessImport.php`
- Test: `tests/Feature/ArealImportTest.php`

**Interfaces:**
- Consumes: `ArealBlok` (Task 1); existing `streamRows`/`isEmptyRow`/`rawCell`.
- Produces:
  - `types()` termasuk `'areal' => 'Areal'`.
  - `import('areal', $batch, $file, ?userId, ?onProgress)` → `importAreal(...)` (match branch baru).
  - `rowCountForType(string $type, string $path): int` (publik) — areal hitung sheet "DB", lainnya `totalDataRows`.
  - `preview('areal', $path)` membaca kolom + sampel dari sheet "DB".
  - ProcessImport memakai `rowCountForType($job->type, $path)` untuk `total`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ArealImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_areal_reads_DB_sheet_not_first_sheet(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $path = $this->buildArealFile();

        // total dihitung dari sheet DB (2 baris data), bukan VIEW.
        $this->assertSame(2, app(SpreadsheetImportService::class)->rowCountForType('areal', $path));

        $res = app(SpreadsheetImportService::class)->import('areal', $batch, $path, null);
        $this->assertSame(2, $res->rowCount);
        $this->assertSame(2, DB::table('areal_blok')->where('batch_id', $batch->id)->count());

        $r = ArealBlok::query()->where('divisi', 'AFD07')->where('tahun_tanam', 2012)->first();
        $this->assertNotNull($r);
        $this->assertEqualsWithDelta(7.20, (float) $r->luas_tanam, 0.001);   // kol J
        $this->assertSame(647, $r->total_pokok_produktif);                    // kol N
        $this->assertSame('TM', $r->status_blok_petak);
        $this->assertSame('5E01', $r->plant_code);
        $this->assertSame('KS', $r->komoditi);

        // Re-import idempoten per batch.
        app(SpreadsheetImportService::class)->import('areal', $batch, $path, null);
        $this->assertSame(2, DB::table('areal_blok')->where('batch_id', $batch->id)->count());

        unlink($path);
    }

    /** File 2 sheet: VIEW (umpan jebakan) + DB (data sebenarnya). */
    private function buildArealFile(): string
    {
        $ss = new Spreadsheet;
        $view = $ss->getActiveSheet();
        $view->setTitle('VIEW');
        $view->setCellValue('A1', 'JEBAKAN VIEW'); // bukan data

        $db = $ss->createSheet();
        $db->setTitle('DB');
        $headers = ['Status', 'Status Blok/Petak', 'Plant', 'Divisi', 'Kode Blok/Petak', 'Tanggal Mulai', 'Sampai', 'Project Definition', 'Deskripsi Blok/Petak', 'Luas Tanam (Ha)', 'Tahun Tanam', 'Total Pokok', 'Luas (Ha)', 'Total Pokok Produktif', 'Kondisi Areal', 'Jenis Tanah', 'GIS ID', 'Unit Kerja', 'Komoditi'];
        foreach ($headers as $i => $h) {
            $db->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $h);
        }
        $rows = [
            ['@5B@', 'TM', '5E01', 'AFD07', '511', 42370, 50040, 'SM-5E01', 'AFD07-511', 7.2, 2012, 952, 7, 647, '01', '01', '5E-511', 'KEBUN GUNUNG MELIAU', 'KS'],
            ['@5B@', 'TU', '5E01', 'AFD08', '701', 42370, 50040, 'SM-5E01', 'AFD08-701', 11.77, 2025, 1600, 6.76, 1468, '01', '01', '5E-701', 'KEBUN GUNUNG MELIAU', 'KS'],
        ];
        $r = 2;
        foreach ($rows as $row) {
            foreach ($row as $i => $val) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$r;
                if (in_array($i, [0, 1, 2, 3, 4, 7, 8, 14, 15, 16, 17, 18], true)) {
                    $db->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
                } else {
                    $db->setCellValue($cell, $val);
                }
            }
            $r++;
        }

        $path = tempnam(sys_get_temp_dir(), 'areal').'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArealImportTest` → FAIL (jenis areal & importAreal belum ada).

- [ ] **Step 3: Implement in SpreadsheetImportService**

1. Tambah `'areal' => 'Areal'` ke array `types()`.

2. Tambah konstanta kolom & method publik `rowCountForType` + helper baca sheet bernama. Letakkan dekat `totalDataRows`/`dataRowCount`:

```php
    /** Indeks kolom 0-based sheet DB Areal. */
    private const AREAL_COLUMNS = [
        0 => 'status', 1 => 'status_blok_petak', 2 => 'plant_code', 3 => 'divisi',
        4 => 'kode_blok', 5 => 'tanggal_mulai', 6 => 'tanggal_sampai', 7 => 'project_definition',
        8 => 'deskripsi', 9 => 'luas_tanam', 10 => 'tahun_tanam', 11 => 'total_pokok',
        12 => 'luas_ha', 13 => 'total_pokok_produktif', 14 => 'kondisi_areal', 15 => 'jenis_tanah',
        16 => 'gis_id', 17 => 'unit_kerja', 18 => 'komoditi',
    ];

    /** Jumlah baris data sesuai jenis: areal pakai sheet "DB", lainnya sheet pertama. */
    public function rowCountForType(string $type, string $path): int
    {
        if ($type !== 'areal') {
            return $this->totalDataRows($path);
        }
        $n = 0;
        foreach ($this->dataRowsSheet($path, 'DB') as $row) {
            if (! $this->isEmptyRow($row)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Baca baris data (tanpa header) dari sheet bernama $sheetName (case-insensitive);
     * fallback ke sheet pertama bila tidak ketemu. Streaming via OpenSpout.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function dataRowsSheet(string $path, string $sheetName): \Generator
    {
        $reader = new XlsxReader(new XlsxReaderOptions);
        $reader->open($path);
        $sheet = $row = null;
        try {
            $target = null;
            $first = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($first === null) {
                    $first = $sheet;
                }
                if (strcasecmp((string) $sheet->getName(), $sheetName) === 0) {
                    $target = $sheet;
                    break;
                }
            }
            // OpenSpout sheet iterator tidak bisa di-rewind dengan andal; baca ulang bila perlu.
            $reader->close();
            $reader = new XlsxReader(new XlsxReaderOptions);
            $reader->open($path);
            $isHeader = true;
            foreach ($reader->getSheetIterator() as $sheet) {
                $matchByName = strcasecmp((string) $sheet->getName(), $sheetName) === 0;
                if ($target !== null ? ! $matchByName : false) {
                    continue;
                }
                if ($target === null) {
                    // fallback: sheet pertama saja
                }
                foreach ($sheet->getRowIterator() as $row) {
                    if ($isHeader) { $isHeader = false; continue; }
                    yield $this->rowToArray($row);
                }
                break;
            }
        } finally {
            $reader->close();
            unset($reader, $sheet, $row);
            gc_collect_cycles();
        }
    }
```

   Catatan: bila implementasi generator di atas terasa rumit, sederhanakan — yang penting:
   (a) pilih sheet yang namanya == "DB" (case-insensitive), fallback sheet pertama;
   (b) lewati baris header; (c) yield array posisional 0-based via `rowToArray`. BACA method
   `streamRows`/`rowToArray` yang ada agar konsisten.

3. Tambah branch areal di `import()` match:

```php
            'areal' => $this->importAreal($batch, $this->dataRowsSheet($path, 'DB'), $onProgress),
```

   (taruh setelah cabang 'gc').

4. Tambah method `importAreal`:

```php
    /**
     * Impor sheet DB Areal → areal_blok (idempoten per batch). Luas←J, Pokok←N.
     *
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function importAreal(Batch $batch, iterable $rows, ?callable $onProgress = null): ImportResult
    {
        DB::table('areal_blok')->where('batch_id', $batch->id)->delete();

        $records = [];
        $inserted = 0;
        $flush = function () use (&$records, &$inserted, $onProgress): void {
            if ($records === []) {
                return;
            }
            DB::table('areal_blok')->insert($records);
            $inserted += count($records);
            $records = [];
            if ($onProgress !== null) {
                $onProgress($inserted);
            }
        };

        foreach ($rows as $values) {
            if ($this->isEmptyRow($values)) {
                continue;
            }
            $rec = ['batch_id' => $batch->id];
            foreach (self::AREAL_COLUMNS as $idx => $col) {
                $rec[$col] = $this->arealCell($values[$idx] ?? null, $col);
            }
            $records[] = $rec;
            if (count($records) >= 500) {
                $flush();
            }
        }
        $flush();

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    private function arealCell(mixed $v, string $col): mixed
    {
        $numeric = ['luas_tanam', 'luas_ha'];
        $int = ['tahun_tanam', 'total_pokok', 'total_pokok_produktif'];
        if (in_array($col, $numeric, true)) {
            return is_numeric($v) ? (float) $v : 0;
        }
        if (in_array($col, $int, true)) {
            return is_numeric($v) ? (int) $v : null;
        }
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : mb_substr($t, 0, 250);
    }
```

5. Buat `preview()` sadar-areal: di awal `preview()`, bila `$type === 'areal'`, baca kolom & sampel dari sheet "DB" via `dataRowsSheet` dan kembalikan struktur `['type'=>'areal','label'=>'Areal','columns'=>[...header DB...],'rows'=>[...sampel...],'total'=>rowCountForType]`. (Header DB diambil dari baris pertama sheet DB — tambah varian `streamRowsSheet` bila perlu, atau baca 1 baris pertama.)

6. Pastikan guard `abort_if(self::isBudget($type))` tidak menolak areal (areal bukan budget → lolos). `import()` `abort_unless(array_key_exists($type, self::types()))` kini menerima areal.

- [ ] **Step 4: ProcessImport pakai rowCountForType**

Di `app/Jobs/ProcessImport.php`, ganti `'total' => $service->dataRowCount($path)` menjadi `'total' => $service->rowCountForType($job->type, $path)`.

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter='ArealImportTest|ProcessImportJobTest|ImportServiceTest'` → PASS.
Run penuh: `php artisan test` → hijau.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Import/SpreadsheetImportService.php app/Jobs/ProcessImport.php tests/Feature/ArealImportTest.php
git commit -m "feat(areal): import jenis areal membaca sheet DB ke areal_blok"
```

---

## Task 3: Endpoint pivot `/report-data/areal`

**Files:**
- Create: `app/Http/Controllers/Api/ArealController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ArealPivotEndpointTest.php`

**Interfaces:**
- Consumes: `ArealBlok` (Task 1), `Batch`, `RefUnit`.
- Produces:
  - `GET /report-data/areal?year=&month=&komoditi=&unit=` → JSON `{afds:[...], rows:[{type,status?,tahun_tanam?,label?,cells:{afd:{luas,pokok}},total:{luas,pokok}}]}`.
  - `ArealController@index(Request): JsonResponse`.
  - Urutan status `['ATTP','TBM','TM','TU']` lalu sisanya alfabetis; tahun asc; subtotal per status; grand total.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArealPivotEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_pivot_structure_and_subtotals(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        RefUnit::query()->create(['code' => '5E01', 'name' => 'KEBUN GUNUNG MELIAU', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        $seed = function (string $status, string $afd, int $thn, float $luas, int $pokok) use ($batch) {
            ArealBlok::query()->create([
                'batch_id' => $batch->id, 'status_blok_petak' => $status, 'plant_code' => '5E01',
                'divisi' => $afd, 'tahun_tanam' => $thn, 'luas_tanam' => $luas,
                'total_pokok_produktif' => $pokok, 'komoditi' => 'KS',
            ]);
        };
        $seed('TM', 'AFD01', 2012, 10.5, 100);
        $seed('TM', 'AFD02', 2012, 5.0, 50);
        $seed('TM', 'AFD01', 2013, 2.0, 20);
        $seed('ATTP', 'AFD01', 1983, 1.0, 10);

        $res = $this->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01');
        $res->assertOk();
        $data = $res->json();

        $this->assertSame(['AFD01', 'AFD02'], $data['afds']); // dinamis, urut

        $types = array_column($data['rows'], 'type');
        // ATTP dulu (alfabetis sebelum TM), tiap status ada subtotal, lalu grandtotal.
        $this->assertContains('grandtotal', $types);
        $grand = collect($data['rows'])->firstWhere('type', 'grandtotal');
        $this->assertEqualsWithDelta(18.5, $grand['total']['luas'], 0.001); // 1+10.5+5+2
        $this->assertSame(180, $grand['total']['pokok']);                    // 10+100+50+20

        $tmSub = collect($data['rows'])->first(fn ($r) => ($r['type'] ?? '') === 'subtotal' && ($r['status'] ?? '') === 'TM');
        $this->assertEqualsWithDelta(17.5, $tmSub['total']['luas'], 0.001);
        // urutan status: ATTP sebelum TM
        $statusesInOrder = array_values(array_filter(array_map(fn ($r) => $r['status'] ?? null, $data['rows'])));
        $this->assertTrue(array_search('ATTP', $statusesInOrder) < array_search('TM', $statusesInOrder));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ArealPivotEndpointTest` → FAIL (route/controller belum ada).

- [ ] **Step 3: Implement controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArealController extends Controller
{
    private const STATUS_ORDER = ['ATTP', 'TBM', 'TM', 'TU'];

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->query('year');
        $month = (int) $request->query('month');
        $komoditi = strtoupper((string) $request->query('komoditi', 'KS'));
        $unit = (string) $request->query('unit');

        $batch = Batch::query()->where('year', $year)->where('month', $month)->first();
        if (! $batch) {
            return response()->json(['afds' => [], 'rows' => []]);
        }

        $rows = ArealBlok::query()
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->when($unit !== '' && $unit !== 'ALL', fn ($q) => $q->where('plant_code', $unit))
            ->get(['status_blok_petak', 'divisi', 'tahun_tanam', 'luas_tanam', 'total_pokok_produktif']);

        // AFD dinamis, urut numerik.
        $afds = $rows->pluck('divisi')->filter()->unique()->values()->all();
        sort($afds, SORT_NATURAL);

        // Agregasi: status → tahun → afd → {luas,pokok}
        $agg = [];
        foreach ($rows as $r) {
            $s = (string) $r->status_blok_petak;
            $t = $r->tahun_tanam !== null ? (int) $r->tahun_tanam : 0;
            $a = (string) $r->divisi;
            $agg[$s][$t][$a]['luas'] = ($agg[$s][$t][$a]['luas'] ?? 0) + (float) $r->luas_tanam;
            $agg[$s][$t][$a]['pokok'] = ($agg[$s][$t][$a]['pokok'] ?? 0) + (int) $r->total_pokok_produktif;
        }

        $statuses = array_keys($agg);
        usort($statuses, function ($x, $y) {
            $ix = array_search($x, self::STATUS_ORDER, true);
            $iy = array_search($y, self::STATUS_ORDER, true);
            $ix = $ix === false ? 999 : $ix;
            $iy = $iy === false ? 999 : $iy;

            return $ix === $iy ? strcmp($x, $y) : $ix <=> $iy;
        });

        $emptyCells = fn () => array_fill_keys($afds, ['luas' => 0, 'pokok' => 0]);
        $out = [];
        $grand = ['cells' => $emptyCells(), 'luas' => 0, 'pokok' => 0];

        foreach ($statuses as $s) {
            $years = array_keys($agg[$s]);
            sort($years);
            $sub = ['cells' => $emptyCells(), 'luas' => 0, 'pokok' => 0];
            foreach ($years as $t) {
                $cells = $emptyCells();
                $rowTot = ['luas' => 0, 'pokok' => 0];
                foreach ($afds as $a) {
                    $luas = round($agg[$s][$t][$a]['luas'] ?? 0, 2);
                    $pokok = (int) ($agg[$s][$t][$a]['pokok'] ?? 0);
                    $cells[$a] = ['luas' => $luas, 'pokok' => $pokok];
                    $rowTot['luas'] += $luas;
                    $rowTot['pokok'] += $pokok;
                    $sub['cells'][$a]['luas'] += $luas;
                    $sub['cells'][$a]['pokok'] += $pokok;
                    $grand['cells'][$a]['luas'] += $luas;
                    $grand['cells'][$a]['pokok'] += $pokok;
                }
                $rowTot['luas'] = round($rowTot['luas'], 2);
                $sub['luas'] += $rowTot['luas'];
                $sub['pokok'] += $rowTot['pokok'];
                $out[] = ['type' => 'detail', 'status' => $s, 'tahun_tanam' => $t === 0 ? null : $t, 'cells' => $cells, 'total' => $rowTot];
            }
            $sub['luas'] = round($sub['luas'], 2);
            $out[] = ['type' => 'subtotal', 'status' => $s, 'label' => $s.' Total', 'cells' => $this->roundCells($sub['cells']), 'total' => ['luas' => $sub['luas'], 'pokok' => $sub['pokok']]];
            $grand['luas'] += $sub['luas'];
            $grand['pokok'] += $sub['pokok'];
        }

        if ($statuses !== []) {
            $out[] = ['type' => 'grandtotal', 'label' => 'Grand Total', 'cells' => $this->roundCells($grand['cells']), 'total' => ['luas' => round($grand['luas'], 2), 'pokok' => $grand['pokok']]];
        }

        return response()->json(['afds' => $afds, 'rows' => $out]);
    }

    private function roundCells(array $cells): array
    {
        foreach ($cells as $k => $v) {
            $cells[$k] = ['luas' => round($v['luas'], 2), 'pokok' => (int) $v['pokok']];
        }

        return $cells;
    }
}
```

- [ ] **Step 4: Route**

Di `routes/web.php`, grup `Route::prefix('report-data')`:

```php
    Route::get('/areal', [\App\Http\Controllers\Api\ArealController::class, 'index']);
```

(juga tambahkan di grup `api/report` bila halaman memakai prefiks itu — cek mana yang dipakai kebun; kebun memakai `/report-data/...`, jadi cukup `report-data`.)

- [ ] **Step 5: Run test**

Run: `php artisan test --filter=ArealPivotEndpointTest` → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ArealController.php routes/web.php tests/Feature/ArealPivotEndpointTest.php
git commit -m "feat(areal): endpoint pivot /report-data/areal (subtotal per status + grand total)"
```

---

## Task 4: Halaman `/areal` + jenis import "Areal"

**Files:**
- Create: `resources/views/areal/index.blade.php`
- Modify: `routes/web.php` (route `/areal` page → view)
- Modify: `resources/views/import/index.blade.php` (jenis "Areal")

**Interfaces:**
- Consumes: `/report-data/areal` (Task 3); `/api/units?type=KEBUN&komoditi=`, `/api/batches` (sudah ada); `window.Tabulator`, `window.lmToast`.

- [ ] **Step 1: Route halaman areal**

Ganti baris `Route::view('/areal', 'coming-soon', [...])->name('areal');` (di grup `auth + role:Viewer,Operator,Admin`) menjadi:

```php
    Route::view('/areal', 'areal.index')->name('areal');
```

- [ ] **Step 2: Buat halaman `resources/views/areal/index.blade.php`**

Halaman extends `layouts.app`, filter bar (Komoditi, Tahun, Bulan, Unit) meniru `kebun/index.blade.php`, dan tabel Tabulator dengan kolom dinamis. Inti JS (Alpine `arealApp()`):

```blade
@extends('layouts.app')
@section('title', 'Areal')
@section('content')
<div x-data="arealApp()" x-init="init()">
    <div class="filter-bar"><div class="filter-grid">
        <div class="filter-group"><label class="filter-label">Komoditas</label>
            <select class="filter-select" x-model="filters.komoditi" @change="onKomoditiChange()">
                <option value="KS">Kelapa Sawit</option>
            </select></div>
        <div class="filter-group"><label class="filter-label">Tahun</label>
            <select class="filter-select" x-model="filters.year" @change="syncBatch();load()">
                <template x-for="y in years()" :key="y"><option :value="y" x-text="y"></option></template>
            </select></div>
        <div class="filter-group"><label class="filter-label">Periode (Bulan)</label>
            <select class="filter-select" x-model="filters.month" @change="syncBatch();load()">
                <template x-for="m in months()" :key="m"><option :value="m" x-text="bulanNama(m)"></option></template>
            </select></div>
        <div class="filter-group"><label class="filter-label">Unit Kebun</label>
            <select class="filter-select" x-model="filters.unit" @change="load()">
                <option value="">— pilih unit —</option>
                <template x-for="u in units" :key="u.code"><option :value="u.code" x-text="u.name"></option></template>
            </select></div>
    </div></div>

    <div class="report-card" x-show="hasData" x-cloak>
        <div class="report-header"><h3 class="report-title">Areal — Statement Blok/Petak</h3></div>
        <div id="areal-table"></div>
    </div>
    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem;text-align:center;border-radius:8px">
        <h3 style="color:#666;font-weight:500">Pilih unit & periode untuk melihat data areal</h3>
    </div>
</div>
@endsection

@push('scripts')
<script>
function arealApp() {
    return {
        filters: { komoditi: 'KS', year: '', month: '', unit: '' },
        batches: [], units: [], hasData: false, table: null,
        bulanNama(m){return ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][m]||m;},
        async init(){
            const b = await (await fetch('/api/batches')).json();
            this.batches = b.data ?? b;
            const ys = this.years(); this.filters.year = ys[0] ?? '';
            const ms = this.months(); this.filters.month = ms[0] ?? '';
            await this.loadUnits();
        },
        years(){return [...new Set(this.batches.map(x=>x.year))].sort((a,b)=>b-a);},
        months(){return this.batches.filter(x=>String(x.year)===String(this.filters.year)).map(x=>x.period ?? x.month).sort((a,b)=>a-b);},
        syncBatch(){ const ms=this.months(); if(!ms.includes(Number(this.filters.month))) this.filters.month = ms[0] ?? ''; },
        async loadUnits(){
            const r = await (await fetch(`/api/units?type=KEBUN&komoditi=${this.filters.komoditi}`)).json();
            this.units = r.data ?? r;
        },
        async onKomoditiChange(){ await this.loadUnits(); this.load(); },
        async load(){
            if(!this.filters.unit || !this.filters.year || !this.filters.month){ this.hasData=false; return; }
            const q = `year=${this.filters.year}&month=${this.filters.month}&komoditi=${this.filters.komoditi}&unit=${this.filters.unit}`;
            const data = await (await fetch(`/report-data/areal?${q}`)).json();
            this.render(data);
        },
        render(data){
            const luasFmt = (c)=>{const v=c.getValue();return (v==null||Math.abs(v)<0.005)?'-':Number(v).toLocaleString('id-ID',{minimumFractionDigits:2,maximumFractionDigits:2});};
            const pokokFmt = (c)=>{const v=c.getValue();return (v==null||v===0)?'-':Number(v).toLocaleString('id-ID');};
            const cols = [
                {title:'Status Blok/Petak', field:'status', frozen:true, minWidth:150,
                 formatter:(c)=>{const d=c.getRow().getData(); return d.label ?? (d.tahun_tanam!=null? '' : (d.status??''));}},
                {title:'Tahun Tanam', field:'tahun_tanam', frozen:true, minWidth:100, hozAlign:'center'},
            ];
            (data.afds||[]).forEach(afd=>{
                cols.push({title:afd, headerHozAlign:'center', columns:[
                    {title:'Luas [Ha]', field:`luas_${afd}`, hozAlign:'right', headerHozAlign:'center', formatter:luasFmt, minWidth:90},
                    {title:'Jlh Pokok', field:`pokok_${afd}`, hozAlign:'right', headerHozAlign:'center', formatter:pokokFmt, minWidth:90},
                ]});
            });
            cols.push({title:'Total Luas [Ha]', field:'tluas', hozAlign:'right', headerHozAlign:'center', formatter:luasFmt, minWidth:110});
            cols.push({title:'Total Jlh Pokok', field:'tpokok', hozAlign:'right', headerHozAlign:'center', formatter:pokokFmt, minWidth:110});

            const rows = (data.rows||[]).map(r=>{
                const o = { status:r.status??'', tahun_tanam:r.tahun_tanam??'', label:r.label??null,
                            _type:r.type, tluas:r.total.luas, tpokok:r.total.pokok };
                (data.afds||[]).forEach(a=>{ o[`luas_${a}`]=r.cells[a]?.luas ?? 0; o[`pokok_${a}`]=r.cells[a]?.pokok ?? 0; });
                return o;
            });

            if(this.table){ this.table.destroy(); }
            this.hasData = rows.length>0;
            if(!this.hasData) return;
            this.table = new window.Tabulator('#areal-table', {
                data: rows, columns: cols, layout:'fitDataStretch', height:'70vh',
                rowFormatter:(row)=>{ const t=row.getData()._type; if(t==='subtotal'||t==='grandtotal'){ row.getElement().style.fontWeight='700'; row.getElement().style.background='#eef5f1'; } },
            });
        },
    };
}
</script>
@endpush
```

(Sesuaikan field/format dengan respons Task 3. Pastikan `@stack('scripts')` ada di layout — sudah.)

- [ ] **Step 3: Jenis "Areal" di `/import`**

Di `resources/views/import/index.blade.php`, pada form upload (Alpine `x-data`):
- Tambah opsi `<option value="areal">Areal</option>` di select Jenis (grup tersendiri atau setelah RKAP).
- Perluas `isBudget()` TIDAK berubah; tambah getter `isAreal(){ return this.jenis === 'areal'; }`.
- Saat `jenis==='areal'`: sembunyikan **Kategori** (`x-show="!isAreal()"` pada blok kategori, atau gabung dengan logika), tampilkan **Bulan** (areal butuh bulan), dan `backendType()` mengembalikan `'areal'`.
- Update `backendType()`:
  ```js
  backendType() { if (this.jenis === 'aktual') return this.kategori; if (this.jenis === 'areal') return 'areal'; return 'rko_' + this.kategori; }
  ```
- Update kondisi tampil Kategori menjadi `x-show="jenis !== 'areal'"`; Bulan tampil bila `jenis !== 'rko' && jenis !== 'rkap'` (yaitu aktual ATAU areal). Sesuaikan `isBudget()` pemakaian untuk Bulan: Bulan tampil saat **bukan budget** (aktual & areal). Karena `isBudget()` = `jenis!=='aktual'`, ganti logika Bulan jadi berbasis `!isBudgetType()` di mana `isBudgetType(){return this.jenis==='rko'||this.jenis==='rkap';}`.

  Konkretnya: tambah `isBudgetType(){ return this.jenis === 'rko' || this.jenis === 'rkap'; }`; Bulan `x-show="!isBudgetType()"` & `x-bind:required="!isBudgetType()"`; Kategori `x-show="!isAreal() && true"` → cukup `x-show="jenis !== 'areal'"`. Jenis dropdown: tambahkan optgroup "Lainnya" berisi Areal, atau option langsung.

  BACA file saat ini dulu (struktur Alpine `jenis/kategori/isBudget`) dan terapkan minimal.

- [ ] **Step 4: Build + verifikasi**

Run: `npm run build` (sukses). Run: `php artisan test` (hijau, 50+). Jelaskan uji manual di laporan (pilih jenis Areal → Kategori hilang, Bulan tampil; buka /areal → tabel grouped dinamis).

- [ ] **Step 5: Commit**

```bash
git add resources/views/areal/index.blade.php routes/web.php resources/views/import/index.blade.php
git commit -m "feat(areal): halaman /areal (tabel pivot dinamis) + jenis import Areal"
```

---

## Task 5: Deploy + verifikasi (ops)

**Files:** server. Tidak ada test otomatis baru.

- [ ] **Step 1: Push**

```bash
git push origin main
```

- [ ] **Step 2: Deploy kode + asset**

```bash
# lokal
npm run build
# scp public/build/assets/* + manifest.json ke server (lihat memory deploy-server)
```
Server:
```bash
cd /var/www/lm-reporting/lm-reporting
git pull origin main
php artisan migrate --force            # areal_blok
php artisan config:clear && php artisan route:clear && php artisan cache:clear && php artisan view:clear
chown -R www-data:www-data public/build   # + hapus asset hash lama yg tak ada di manifest
systemctl restart lm-reporting-worker     # kode Service/Job berubah (areal import)
```

- [ ] **Step 3: Verifikasi**

- `/import` → jenis "Areal" muncul; upload file AREAL STEATMENT (sheet DB) untuk satu periode → modal progress → selesai → `areal_blok` terisi.
- `/areal` → pilih unit + periode → tabel tampil; Grand Total cocok dengan subset unit.
- login HTTP 200; worker active.

- [ ] **Step 4: Catat ke memory deploy** (areal_blok migrasi + jenis import areal + restart worker).

---

## Self-Review Notes

- **Spec coverage:** tabel areal_blok (T1) ✓; import jenis areal + sheet DB (T2) ✓; endpoint pivot + subtotal/grandtotal + AFD dinamis + status order (T3) ✓; halaman /areal + grouped header + format luas/pokok + filter (T4) ✓; jenis import UI (T4) ✓; deploy (T5) ✓.
- **Verifikasi sebelum coding:** (a) `streamRows`/`rowToArray` signature untuk `dataRowsSheet` (T2) — baca method asli; sederhanakan generator bila perlu, syaratnya pilih sheet "DB" + skip header. (b) kebun blade `/api/units`,`/api/batches` bentuk respons (`.data`?) — sesuaikan `arealApp` (T4). (c) ImportController `store()` preview untuk areal memakai `preview('areal',...)` yang kini baca sheet DB (T2). (d) RefUnit punya `code`,`name`,`type` (dipakai test T3 & units endpoint).
- **Type consistency:** endpoint shape `{afds:string[], rows:[{type,status?,tahun_tanam?,label?,cells:{[afd]:{luas,pokok}},total:{luas,pokok}}]}` dipakai konsisten T3↔T4. `rowCountForType(type,path)` T2↔ProcessImport. `import('areal',...)`→importAreal T2.
- **Catatan:** areal lewat ProcessImport (async) otomatis karena confirm men-dispatch semua jenis; pastikan `import('areal',...)` ada di match dan `rowCountForType` dipakai job.
