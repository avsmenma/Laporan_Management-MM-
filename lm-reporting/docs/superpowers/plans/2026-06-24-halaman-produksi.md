# Halaman Produksi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun halaman `/produksi` (laporan produksi PKS) yang mereproduksi sheet `VIEW1` dari data sheet `ZPTPNHLPP039`, dengan upload per tanggal + riwayat.

**Architecture:** Tabel baru `produksi_pks` (1 baris per posting_date × plant × kebun, tanpa batch). Impor lewat jenis baru "Produksi" (web async + CLI) yang membaca sheet `ZPTPNHLPP039` dan menurunkan tanggal dari kolom "Tgl Posting". Endpoint `/report-data/produksi?date=` mem-pivot data jadi 6 tabel (baris=Kebun, kolom=Plant PKS) dua blok (Bulan Ini=s/d hari ini, S.D Bulan Ini=s/d bulan) + ringkasan + rendemen. Halaman `/produksi` me-render dengan Tabulator (JS inline di blade, gaya sama Areal/Kebun).

**Tech Stack:** Laravel 12, PHP 8.3+, MySQL 8, Blade + Alpine.js + Tabulator.js, OpenSpout (streaming xlsx), PHPUnit + RefreshDatabase.

## Global Constraints

- Tampilan tabel data harus identik dengan referensi Excel (`VIEW1`): grouped header, kolom identitas frozen, dua blok berdampingan, baris Grand Total.
- Kuantitas (TBS/MS/IS/restan) ditampilkan bulat (0 desimal); rendemen 2 desimal.
- Rasio dengan penyebut 0 → 0 (pola IFERROR/0).
- Grand Total = round-of-sum (jumlah dulu baru dibulatkan), bukan sum-of-rounded.
- Pemetaan kolom sumber (persis VIEW1): TBS Diterima ←I/J · TBS Diolah ←L/M · Restan Akhir ←N (sama dua blok) · Minyak Sawit ←P/Q · Inti Sawit ←S/T · Restan Awal = Diolah + Restan Akhir − Diterima.
- `git add` per-file (jangan `git add .`). Pesan commit Bahasa Indonesia.
- JS produksi INLINE di blade (jangan ubah `resources/js/app.js`/`app.css`) agar hash aset TETAP.
- Produksi TIDAK terikat batch year-month; dimensi waktu = `posting_date`.

---

### Task 1: Migrasi + Model `produksi_pks`

**Files:**
- Create: `database/migrations/2026_06_27_000000_create_produksi_pks_table.php`
- Create: `app/Models/ProduksiPks.php`
- Test: `tests/Feature/ProduksiPksModelTest.php`

**Interfaces:**
- Produces: tabel `produksi_pks` dengan kolom `posting_date` (date), `plant_code`, `plant_desc`, `group_pemilik`, `kebun_code`, `nama_kebun`, `sisa_awal`, `tbs_diterima_sdhari`, `tbs_diterima_sdbulan`, `tbs_diolah_sdhari`, `tbs_diolah_sdbulan`, `sisa_akhir`, `ms_sdhari`, `ms_sdbulan`, `is_sdhari`, `is_sdbulan`, `tidak_mengolah` (bool). Model `App\Models\ProduksiPks` (`$guarded=[]`, `posting_date`→date, ukuran→decimal:2, `tidak_mengolah`→bool).

- [ ] **Step 1: Tulis test gagal**

```php
<?php

namespace Tests\Feature;

use App\Models\ProduksiPks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProduksiPksModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabel_dan_model_produksi_pks(): void
    {
        $this->assertTrue(Schema::hasTable('produksi_pks'));

        $row = ProduksiPks::query()->create([
            'posting_date' => '2026-05-31',
            'plant_code' => '5F01',
            'plant_desc' => 'PABRIK GUNUNG MELIAU',
            'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => '5E01',
            'nama_kebun' => 'KEBUN GUNUNG MELIAU',
            'tbs_diterima_sdhari' => 3795250,
            'tbs_diterima_sdbulan' => 19506780,
            'sisa_akhir' => 0,
            'tidak_mengolah' => false,
        ]);

        $fresh = ProduksiPks::query()->find($row->id);
        $this->assertSame('2026-05-31', $fresh->posting_date->format('Y-m-d'));
        $this->assertSame('5F01', $fresh->plant_code);
        $this->assertEquals(3795250.0, (float) $fresh->tbs_diterima_sdhari);
        $this->assertFalse($fresh->tidak_mengolah);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ProduksiPksModelTest`
Expected: FAIL (tabel/model belum ada).

- [ ] **Step 3: Buat migrasi**

`database/migrations/2026_06_27_000000_create_produksi_pks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produksi_pks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->date('posting_date')->index();
            $table->string('plant_code', 12)->nullable()->index();
            $table->string('plant_desc', 150)->nullable();
            $table->string('group_pemilik', 30)->nullable();
            $table->string('kebun_code', 20)->nullable()->index();
            $table->string('nama_kebun', 150)->nullable();
            $table->decimal('sisa_awal', 20, 2)->default(0);
            $table->decimal('tbs_diterima_sdhari', 20, 2)->default(0);
            $table->decimal('tbs_diterima_sdbulan', 20, 2)->default(0);
            $table->decimal('tbs_diolah_sdhari', 20, 2)->default(0);
            $table->decimal('tbs_diolah_sdbulan', 20, 2)->default(0);
            $table->decimal('sisa_akhir', 20, 2)->default(0);
            $table->decimal('ms_sdhari', 20, 2)->default(0);
            $table->decimal('ms_sdbulan', 20, 2)->default(0);
            $table->decimal('is_sdhari', 20, 2)->default(0);
            $table->decimal('is_sdbulan', 20, 2)->default(0);
            $table->boolean('tidak_mengolah')->default(false);
            $table->timestamps();
            $table->index(['posting_date', 'plant_code', 'kebun_code'], 'idx_produksi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_pks');
    }
};
```

- [ ] **Step 4: Buat model**

`app/Models/ProduksiPks.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduksiPks extends Model
{
    protected $table = 'produksi_pks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'sisa_awal' => 'decimal:2',
            'tbs_diterima_sdhari' => 'decimal:2',
            'tbs_diterima_sdbulan' => 'decimal:2',
            'tbs_diolah_sdhari' => 'decimal:2',
            'tbs_diolah_sdbulan' => 'decimal:2',
            'sisa_akhir' => 'decimal:2',
            'ms_sdhari' => 'decimal:2',
            'ms_sdbulan' => 'decimal:2',
            'is_sdhari' => 'decimal:2',
            'is_sdbulan' => 'decimal:2',
            'tidak_mengolah' => 'boolean',
        ];
    }
}
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ProduksiPksModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_27_000000_create_produksi_pks_table.php app/Models/ProduksiPks.php tests/Feature/ProduksiPksModelTest.php
git commit -m "feat(produksi): tabel + model produksi_pks (laporan produksi PKS per tanggal)"
```

---

### Task 2: Import service `importProduksi` + CLI `produksi:import`

**Files:**
- Modify: `app/Domain/Import/SpreadsheetImportService.php` (tambah `types()` entry, `isProduksi()`, `importProduksi()`, helper, cabang `preview()` & `rowCountForType()`)
- Modify: `routes/console.php` (command `produksi:import`)
- Test: `tests/Feature/ImportProduksiTest.php`

**Interfaces:**
- Consumes: `dataRowsSheet($path, 'ZPTPNHLPP039')` (sudah ada, private — dipakai dari dalam service), `isEmptyRow()`, `ImportResult`.
- Produces:
  - `SpreadsheetImportService::types()` memuat `'produksi' => 'Produksi'`.
  - `SpreadsheetImportService::isProduksi(string $type): bool`.
  - `SpreadsheetImportService::importProduksi(string $path, ?int $userId = null, ?callable $onProgress = null): ImportResult` — idempoten per `posting_date`, return jumlah baris.
  - `preview('produksi', $path)` dan `rowCountForType('produksi', $path)` membaca sheet `ZPTPNHLPP039`.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/ImportProduksiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportProduksiTest extends TestCase
{
    use RefreshDatabase;

    private function contohPath(): string
    {
        // File contoh berada di parent docs (Project_LM/docs/produksi), di luar repo app.
        return base_path('../docs/produksi/CONTOH_PRODUKSI_PKS.xlsx');
    }

    public function test_import_produksi_membaca_sheet_dan_idempoten(): void
    {
        $path = $this->contohPath();
        if (! is_file($path)) {
            $this->markTestSkipped('File contoh produksi tidak tersedia.');
        }

        $service = app(SpreadsheetImportService::class);

        $r1 = $service->importProduksi($path);
        $this->assertSame(62, $r1->rowCount);
        $this->assertSame(62, DB::table('produksi_pks')->count());

        // posting_date dari serial 46173 = 2026-05-31
        $this->assertSame(62, DB::table('produksi_pks')->whereDate('posting_date', '2026-05-31')->count());

        // Sampel nilai: 5F01 / 5E01 / Kebun Sendiri
        $row = DB::table('produksi_pks')->where('plant_code', '5F01')->where('kebun_code', '5E01')->first();
        $this->assertEquals(3795250.0, (float) $row->tbs_diterima_sdhari);
        $this->assertEquals(19506780.0, (float) $row->tbs_diterima_sdbulan);
        $this->assertEquals(3987400.0, (float) $row->tbs_diolah_sdhari);

        // Idempoten: impor ulang tanggal yang sama tidak menggandakan.
        $service->importProduksi($path);
        $this->assertSame(62, DB::table('produksi_pks')->count());
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ImportProduksiTest`
Expected: FAIL (`importProduksi` belum ada). (Bila file contoh tak ada → SKIPPED; salin file ke `../docs/produksi/` agar test berjalan.)

- [ ] **Step 3: Tambah entri `types()` dan `isProduksi()`**

Di `app/Domain/Import/SpreadsheetImportService.php`, pada `types()` tambahkan baris setelah `'areal' => 'Areal',`:

```php
            'areal' => 'Areal',
            'produksi' => 'Produksi',
```

Lalu setelah method `isBudget()` (sekitar baris 93), tambahkan:

```php
    /** Jenis produksi PKS (snapshot harian, tanpa batch). */
    public static function isProduksi(string $type): bool
    {
        return $type === 'produksi';
    }
```

- [ ] **Step 4: Tambah `importProduksi()` + helper**

Tambahkan method berikut di `SpreadsheetImportService` (mis. tepat setelah `importAreal()`/`arealCell()`):

```php
    /** Indeks kolom 0-based sheet ZPTPNHLPP039 yang dipakai. */
    private const PRODUKSI_COLS = [
        'plant_code' => 1, 'plant_desc' => 2, 'group_pemilik' => 3, 'kebun_code' => 4, 'nama_kebun' => 5,
        'sisa_awal' => 6, 'tbs_diterima_sdhari' => 8, 'tbs_diterima_sdbulan' => 9,
        'tbs_diolah_sdhari' => 11, 'tbs_diolah_sdbulan' => 12, 'sisa_akhir' => 13,
        'ms_sdhari' => 15, 'ms_sdbulan' => 16, 'is_sdhari' => 18, 'is_sdbulan' => 19,
        'tgl_posting' => 26, 'tidak_mengolah' => 27,
    ];

    /**
     * Impor sheet ZPTPNHLPP039 → produksi_pks (idempoten per posting_date). Tanggal
     * diturunkan dari kolom "Tgl Posting" (serial Excel atau string Y-m-d). Tanpa Batch.
     */
    public function importProduksi(string $path, ?int $userId = null, ?callable $onProgress = null): ImportResult
    {
        $records = [];
        $dates = [];
        foreach ($this->dataRowsSheet($path, 'ZPTPNHLPP039') as $v) {
            if ($this->isEmptyRow($v)) {
                continue;
            }
            $plant = trim((string) ($v[self::PRODUKSI_COLS['plant_code']] ?? ''));
            $date = $this->produksiDate($v[self::PRODUKSI_COLS['tgl_posting']] ?? null);
            if ($plant === '' || $date === null) {
                continue;
            }
            $dates[$date] = true;
            $records[] = [
                'posting_date' => $date,
                'plant_code' => $plant,
                'plant_desc' => $this->produksiText($v[self::PRODUKSI_COLS['plant_desc']] ?? null),
                'group_pemilik' => $this->produksiText($v[self::PRODUKSI_COLS['group_pemilik']] ?? null, 30),
                'kebun_code' => $this->produksiText($v[self::PRODUKSI_COLS['kebun_code']] ?? null, 20),
                'nama_kebun' => $this->produksiText($v[self::PRODUKSI_COLS['nama_kebun']] ?? null),
                'sisa_awal' => $this->produksiNum($v[self::PRODUKSI_COLS['sisa_awal']] ?? null),
                'tbs_diterima_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diterima_sdhari']] ?? null),
                'tbs_diterima_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diterima_sdbulan']] ?? null),
                'tbs_diolah_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diolah_sdhari']] ?? null),
                'tbs_diolah_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['tbs_diolah_sdbulan']] ?? null),
                'sisa_akhir' => $this->produksiNum($v[self::PRODUKSI_COLS['sisa_akhir']] ?? null),
                'ms_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['ms_sdhari']] ?? null),
                'ms_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['ms_sdbulan']] ?? null),
                'is_sdhari' => $this->produksiNum($v[self::PRODUKSI_COLS['is_sdhari']] ?? null),
                'is_sdbulan' => $this->produksiNum($v[self::PRODUKSI_COLS['is_sdbulan']] ?? null),
                'tidak_mengolah' => trim((string) ($v[self::PRODUKSI_COLS['tidak_mengolah']] ?? '')) !== '',
            ];
        }

        $inserted = 0;
        DB::transaction(function () use ($records, $dates, &$inserted, $onProgress): void {
            if ($dates !== []) {
                DB::table('produksi_pks')->whereIn('posting_date', array_keys($dates))->delete();
            }
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('produksi_pks')->insert($chunk);
                $inserted += count($chunk);
                if ($onProgress !== null) {
                    $onProgress($inserted);
                }
            }
        });

        return new ImportResult(rowCount: $inserted, errors: []);
    }

    private function produksiText(mixed $v, int $len = 150): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : mb_substr($t, 0, $len);
    }

    private function produksiNum(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Serial Excel (mis. 46173) atau string 'Y-m-d' → 'Y-m-d'; null bila tak terbaca. */
    private function produksiDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $days = (int) $v;

            return (new \DateTime('1899-12-30'))->modify("+{$days} days")->format('Y-m-d');
        }
        $t = trim((string) $v);
        $d = \DateTime::createFromFormat('!Y-m-d', substr($t, 0, 10));

        return $d ? $d->format('Y-m-d') : null;
    }
```

- [ ] **Step 5: Tambah cabang `preview()` & `rowCountForType()`**

Di `preview()`, tepat sebelum blok `if ($type === 'areal')`, tambahkan blok produksi:

```php
        if ($type === 'produksi') {
            $total = max(0, $this->rowCountForType('produksi', $path));
            $headers = ['Plant', 'Desc', 'Group Pemilik', 'Kebun', 'Nama Kebun', 'TBS Diterima s/d Hari', 'TBS Diterima s/d Bulan', 'TBS Diolah s/d Hari', 'TBS Diolah s/d Bulan', 'Sisa Akhir', 'Tgl Posting'];
            $idx = [1, 2, 3, 4, 5, 8, 9, 11, 12, 13, 26];
            $rows = [];
            $emitted = 0;
            foreach ($this->dataRowsSheet($path, 'ZPTPNHLPP039') as $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $rows[] = array_map(fn ($i) => $row[$i] ?? null, $idx);
                if (++$emitted >= $sampleSize) {
                    break;
                }
            }

            return ['type' => $type, 'label' => self::types()[$type], 'columns' => $headers, 'rows' => $rows, 'total' => $total];
        }
```

Di `rowCountForType()`, ubah guard awal agar produksi memakai sheet `ZPTPNHLPP039`:

```php
    public function rowCountForType(string $type, string $path): int
    {
        if ($type !== 'areal' && $type !== 'produksi') {
            return $this->totalDataRows($path);
        }
        $sheet = $type === 'areal' ? 'DB' : 'ZPTPNHLPP039';
        $n = 0;
        foreach ($this->dataRowsSheet($path, $sheet) as $row) {
            if (! $this->isEmptyRow($row)) {
                $n++;
            }
        }

        return $n;
    }
```

- [ ] **Step 6: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ImportProduksiTest`
Expected: PASS (atau SKIPPED bila file contoh tak ada).

- [ ] **Step 7: Tambah command CLI `produksi:import`**

Di `routes/console.php` (mis. setelah command `lm:tahunlalu-ohc`), tambahkan:

```php
Artisan::command('produksi:import {--file=}', function (SpreadsheetImportService $service): int {
    $file = (string) $this->option('file');
    if ($file === '' || ! is_file($file)) {
        $this->error('Pakai --file=<path .xlsx berisi sheet ZPTPNHLPP039>.');

        return 1;
    }
    $result = $service->importProduksi($file);
    $this->info("Selesai: {$result->rowCount} baris produksi tersimpan.");

    return 0;
})->purpose('Impor laporan produksi PKS (sheet ZPTPNHLPP039) ke produksi_pks (idempoten per tanggal).');
```

- [ ] **Step 8: Verifikasi command terdaftar & lint**

Run: `php artisan list | grep produksi:import`
Expected: baris command muncul.
Run: `php -l routes/console.php`
Expected: `No syntax errors detected`.

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Import/SpreadsheetImportService.php routes/console.php tests/Feature/ImportProduksiTest.php
git commit -m "feat(produksi): importProduksi (sheet ZPTPNHLPP039) + command produksi:import"
```

---

### Task 3: Wiring impor web (async) untuk jenis Produksi

**Files:**
- Modify: `app/Http/Controllers/Import/ImportController.php` (`confirm()` — kecualikan produksi dari wajib `month`)
- Modify: `app/Jobs/ProcessImport.php` (cabang produksi → `importProduksi`, tanpa Batch)
- Modify: `resources/views/import/index.blade.php` (opsi Jenis "Produksi")
- Test: `tests/Feature/ConfirmProduksiImportTest.php`

**Interfaces:**
- Consumes: `SpreadsheetImportService::isProduksi()`, `importProduksi()`, `ImportJob`, `ProcessImport`.
- Produces: konfirmasi web untuk `type=produksi` men-dispatch `ProcessImport` tanpa memerlukan `month`; job menjalankan `importProduksi` tanpa membuat Batch.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/ConfirmProduksiImportTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessImport;
use App\Models\ImportJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfirmProduksiImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_konfirmasi_produksi_tanpa_bulan_men_dispatch_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $role = Role::query()->firstOrCreate(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $token = '11111111-1111-1111-1111-111111111111';
        Storage::disk('local')->put("import-staging/{$token}.xlsx", 'dummy');

        $resp = $this->actingAs($user)->postJson('/import/confirm', [
            'token' => $token,
            'ext' => 'xlsx',
            'type' => 'produksi',
            'year' => 2026,
            // tanpa month — produksi tidak memerlukannya
        ]);

        $resp->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);
        $this->assertDatabaseHas('import_jobs', ['type' => 'produksi', 'month' => null]);
        Queue::assertPushed(ProcessImport::class);
    }
}
```

(Catatan: bila `User::factory()` / `Role` berbeda di proyek, samakan dengan pola test yang sudah ada — mis. lihat `tests/Feature` lain yang membuat user Operator.)

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ConfirmProduksiImportTest`
Expected: FAIL (confirm masih mewajibkan `month` untuk non-budget → 422).

- [ ] **Step 3: Kecualikan produksi dari wajib `month` di `confirm()`**

Di `app/Http/Controllers/Import/ImportController.php`, method `confirm()`, ubah blok aturan:

```php
        $type = (string) $request->input('type');
        $isBudget = SpreadsheetImportService::isBudget($type);
        $isProduksi = SpreadsheetImportService::isProduksi($type);

        $rules = [
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext'   => ['required', 'in:xlsx,xls,csv'],
            'type'  => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
        if (! $isBudget && ! $isProduksi) {
            $rules['month'] = ['required', 'integer', 'min:1', 'max:12'];
        }
        $data = $request->validate($rules);
```

Dan saat membuat `ImportJob`, set `month` null untuk produksi:

```php
            'month'       => ($isBudget || $isProduksi) ? null : (int) $data['month'],
```

- [ ] **Step 4: Tambah cabang produksi di `ProcessImport::handle()`**

Di `app/Jobs/ProcessImport.php`, di dalam `try {`, ubah pencabangan menjadi:

```php
            if (SpreadsheetImportService::isProduksi($job->type)) {
                $result = $service->importProduksi($path, $job->user_id, $onProgress);
            } elseif ($isBudget) {
                $result = $service->importBudget((int) $job->year, $job->type, $path, $job->user_id, $onProgress);
                Batch::query()->where('year', $job->year)->update(['needs_regenerate' => true]);
            } else {
                $batch = Batch::query()->firstOrCreate(
                    ['year' => $job->year, 'month' => $job->month],
                    ['code' => "Batch #{$job->year}-".str_pad((string) $job->month, 2, '0', STR_PAD_LEFT), 'status' => 'draft', 'needs_regenerate' => true],
                );
                $result = $service->import($job->type, $batch, $path, $job->user_id, $onProgress);
                $batch->forceFill(['needs_regenerate' => true])->save();
            }
```

(Variabel `$isBudget` sudah didefinisikan di awal `handle()`.)

- [ ] **Step 5: Tambah opsi Jenis "Produksi" di blade import**

Di `resources/views/import/index.blade.php`:

(a) Pada blok `@php` deteksi pending (sekitar baris 36-45), tambahkan penanganan produksi setelah cabang areal:

```php
                if ($pType === 'areal') {
                    $pJenis = 'areal';
                    $pKategori = 'wbs';
                } elseif ($pType === 'produksi') {
                    $pJenis = 'produksi';
                    $pKategori = 'wbs';
                } elseif ($pIsBudget) {
```

(b) Pada x-data form, tambahkan helper `isProduksi()` dan sesuaikan `backendType()`:

```php
                      isAreal() { return this.jenis === 'areal'; },
                      isProduksi() { return this.jenis === 'produksi'; },
                      kategoriOptions() {
                          return this.isBudgetType()
                              ? [{ v: 'bku', t: 'BKU' }, { v: 'ohc', t: 'OHC' }, { v: 'gc', t: 'GC' }]
                              : [{ v: 'wbs', t: 'WBS' }, { v: 'ohc', t: 'OHC' }, { v: 'gc', t: 'GC' }];
                      },
                      backendType() {
                          if (this.jenis === 'aktual') return this.kategori;
                          if (this.jenis === 'areal') return 'areal';
                          if (this.jenis === 'produksi') return 'produksi';
                          return 'rko_' + this.kategori;
                      }
```

(c) Tambah opsi pada select Jenis:

```php
                        <option value="areal">Areal</option>
                        <option value="produksi">Produksi</option>
```

(d) Sembunyikan Kategori & Bulan untuk produksi. Ubah atribut `x-show` dua field:

Kategori (baris ~80):
```php
                <div class="field" style="margin-bottom:0" x-show="jenis !== 'areal' && jenis !== 'produksi'">
```

Bulan (baris ~96):
```php
                <div class="field" style="margin-bottom:0" x-show="!isBudgetType() && !isProduksi()">
```

(Year tetap tampil & terkirim; `importProduksi` mengabaikannya — tanggal diambil dari file. Tidak perlu mengubah tabel `import_jobs`.)

- [ ] **Step 6: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ConfirmProduksiImportTest`
Expected: PASS.

- [ ] **Step 7: Lint blade**

Run: `php artisan view:clear && php -l resources/views/import/index.blade.php`
Expected: tidak ada error fatal (blade dikompilasi saat dipakai; `php -l` pada blade mentah hanya cek PHP-tag — abaikan bila tak relevan, pastikan `view:clear` sukses).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Import/ImportController.php app/Jobs/ProcessImport.php resources/views/import/index.blade.php tests/Feature/ConfirmProduksiImportTest.php
git commit -m "feat(produksi): jenis import Produksi (async, tanpa batch, tanggal dari file)"
```

---

### Task 4: Endpoint `/report-data/produksi` (mesin pivot)

**Files:**
- Create: `app/Http/Controllers/Api/ProduksiController.php`
- Modify: `routes/web.php` (route `/report-data/produksi`)
- Test: `tests/Feature/ProduksiApiTest.php`

**Interfaces:**
- Consumes: tabel `produksi_pks`, trait `AuthorizesReportRequests::authenticateReportRequest()`.
- Produces: `GET /report-data/produksi?date=YYYY-MM-DD` → JSON `{dates, date, plants:[{code,desc}], kebun:[{code,nama}], tables:{restan_awal,tbs_diterima,tbs_diolah,restan_akhir,minyak_sawit,inti_sawit}, ringkasan:{bi,sd}}`. Tiap tabel: `{rows:[{kebun,nama,bi:{<plant>:n,...,grand:n},sd:{...}}], grand:{bi:{<plant>:n,grand:n},sd:{...}}}`.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/ProduksiApiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProduksiApiTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): void
    {
        // Dua kebun (5E01, 5E02) di dua plant (5F01, 5F04), satu tanggal.
        $base = ['posting_date' => '2026-05-31', 'tidak_mengolah' => false];
        DB::table('produksi_pks')->insert([
            $base + ['plant_code' => '5F01', 'plant_desc' => 'PKS A', 'group_pemilik' => 'Kebun Sendiri', 'kebun_code' => '5E01', 'nama_kebun' => 'KEBUN A',
                'tbs_diterima_sdhari' => 100, 'tbs_diterima_sdbulan' => 1000, 'tbs_diolah_sdhari' => 80, 'tbs_diolah_sdbulan' => 800,
                'sisa_akhir' => 5, 'ms_sdhari' => 16, 'ms_sdbulan' => 160, 'is_sdhari' => 4, 'is_sdbulan' => 40],
            $base + ['plant_code' => '5F04', 'plant_desc' => 'PKS B', 'group_pemilik' => 'Kebun Sendiri', 'kebun_code' => '5E02', 'nama_kebun' => 'KEBUN B',
                'tbs_diterima_sdhari' => 50, 'tbs_diterima_sdbulan' => 500, 'tbs_diolah_sdhari' => 40, 'tbs_diolah_sdbulan' => 400,
                'sisa_akhir' => 2, 'ms_sdhari' => 8, 'ms_sdbulan' => 80, 'is_sdhari' => 2, 'is_sdbulan' => 20],
        ]);
    }

    private function actingViewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_butuh_auth(): void
    {
        $this->seed();
        $this->getJson('/report-data/produksi')->assertStatus(401);
    }

    public function test_pivot_nilai_dan_grand_total(): void
    {
        $this->seed();
        $user = $this->actingViewer();

        $resp = $this->actingAs($user)->getJson('/report-data/produksi?date=2026-05-31');
        $resp->assertOk();
        $data = $resp->json();

        $this->assertSame(['2026-05-31'], $data['dates']);
        $this->assertSame(['5F01', '5F04'], array_column($data['plants'], 'code'));
        $this->assertSame(['5E01', '5E02'], array_column($data['kebun'], 'code'));

        // TBS Diterima: 5E01/5F01 Bulan Ini=100, S.D=1000; Grand bulan ini=150, sd=1500
        $td = $data['tables']['tbs_diterima'];
        $row01 = collect($td['rows'])->firstWhere('kebun', '5E01');
        $this->assertEquals(100, $row01['bi']['5F01']);
        $this->assertEquals(1000, $row01['sd']['5F01']);
        $this->assertEquals(100, $row01['bi']['grand']);   // hanya 1 plant terisi
        $this->assertEquals(150, $td['grand']['bi']['grand']);
        $this->assertEquals(1500, $td['grand']['sd']['grand']);

        // Restan Awal turunan (bi) 5E01/5F01 = diolah(80) + akhir(5) - diterima(100) = -15
        $ra = collect($data['tables']['restan_awal']['rows'])->firstWhere('kebun', '5E01');
        $this->assertEquals(-15, $ra['bi']['5F01']);

        // Ringkasan bi 5F01: olah=80, ms=16 → rend_ms=20.00
        $this->assertEquals(20.0, round($data['ringkasan']['bi']['5F01']['rend_ms'], 2));
        // Ringkasan bi JLH olah=120, ms=24 → rend_ms=20.00
        $this->assertEquals(20.0, round($data['ringkasan']['bi']['JLH']['rend_ms'], 2));
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ProduksiApiTest`
Expected: FAIL (controller/route belum ada).

- [ ] **Step 3: Buat controller**

`app/Http/Controllers/Api/ProduksiController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProduksiController extends Controller
{
    use AuthorizesReportRequests;

    /** Ukuran tabel → [kolom blok "Bulan Ini" (s/d hari ini), kolom blok "S.D Bulan Ini"]. */
    private const MEASURES = [
        'tbs_diterima' => ['tbs_diterima_sdhari', 'tbs_diterima_sdbulan'],
        'tbs_diolah' => ['tbs_diolah_sdhari', 'tbs_diolah_sdbulan'],
        'restan_akhir' => ['sisa_akhir', 'sisa_akhir'],
        'minyak_sawit' => ['ms_sdhari', 'ms_sdbulan'],
        'inti_sawit' => ['is_sdhari', 'is_sdbulan'],
    ];

    /** Urutan tabel pada output (restan_awal turunan disisipkan paling depan). */
    private const TABLE_ORDER = ['restan_awal', 'tbs_diterima', 'tbs_diolah', 'restan_akhir', 'minyak_sawit', 'inti_sawit'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $dates = DB::table('produksi_pks')
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        if ($dates === []) {
            return response()->json(['dates' => [], 'date' => null, 'plants' => [], 'kebun' => [], 'tables' => [], 'ringkasan' => ['bi' => [], 'sd' => []]]);
        }

        $date = (string) $request->query('date', $dates[0]);
        if (! in_array($date, $dates, true)) {
            $date = $dates[0];
        }

        $rows = DB::table('produksi_pks')->whereDate('posting_date', $date)->get();

        // Plant (kolom): distinct, urut natural.
        $plants = $rows->pluck('plant_code')->filter()->unique()->values()->all();
        sort($plants, SORT_NATURAL);
        $plantDesc = [];
        foreach ($rows as $r) {
            $plantDesc[$r->plant_code] = $plantDesc[$r->plant_code] ?? (string) $r->plant_desc;
        }

        // Kebun (baris): 5E* natural dahulu, lalu sisanya (PHTG/PLSM/PLS/5F..) ikut urutan kemunculan.
        $kebunNama = [];
        $first5e = [];
        $firstOther = [];
        foreach ($rows as $r) {
            $k = (string) $r->kebun_code;
            if ($k === '' || isset($kebunNama[$k])) {
                continue;
            }
            $kebunNama[$k] = (string) $r->nama_kebun;
            if (preg_match('/^5E/i', $k)) {
                $first5e[] = $k;
            } else {
                $firstOther[] = $k;
            }
        }
        sort($first5e, SORT_NATURAL);
        $kebun = array_merge($first5e, $firstOther);

        // Matriks mentah: $mat[measure][block][kebun][plant].
        $mat = [];
        foreach (array_keys(self::MEASURES) as $m) {
            $mat[$m] = ['bi' => [], 'sd' => []];
        }
        foreach ($rows as $r) {
            $k = (string) $r->kebun_code;
            $p = (string) $r->plant_code;
            foreach (self::MEASURES as $m => $cols) {
                foreach (['bi' => 0, 'sd' => 1] as $b => $ci) {
                    $mat[$m][$b][$k][$p] = ($mat[$m][$b][$k][$p] ?? 0.0) + (float) $r->{$cols[$ci]};
                }
            }
        }

        // Restan Awal turunan = Diolah + Restan Akhir − Diterima (per blok).
        $mat['restan_awal'] = ['bi' => [], 'sd' => []];
        foreach (['bi', 'sd'] as $b) {
            foreach ($kebun as $k) {
                foreach ($plants as $p) {
                    $mat['restan_awal'][$b][$k][$p] =
                        ($mat['tbs_diolah'][$b][$k][$p] ?? 0)
                        + ($mat['restan_akhir'][$b][$k][$p] ?? 0)
                        - ($mat['tbs_diterima'][$b][$k][$p] ?? 0);
                }
            }
        }

        $tables = [];
        foreach (self::TABLE_ORDER as $m) {
            $tables[$m] = $this->buildTable($mat[$m], $kebun, $kebunNama, $plants);
        }

        return response()->json([
            'dates' => $dates,
            'date' => $date,
            'plants' => array_map(fn ($p) => ['code' => $p, 'desc' => $plantDesc[$p] ?? ''], $plants),
            'kebun' => array_map(fn ($k) => ['code' => $k, 'nama' => $kebunNama[$k] ?? ''], $kebun),
            'tables' => $tables,
            'ringkasan' => $this->buildRingkasan($tables, $plants),
        ]);
    }

    /**
     * Susun satu tabel: baris per kebun (dua blok) + baris Grand Total.
     * Kuantitas dibulatkan 0 desimal saat emit (round-of-sum: dijumlah dulu lalu dibulatkan).
     *
     * @param  array<string, array<string, array<string, float>>>  $blocks  [block][kebun][plant]
     * @param  array<int, string>  $kebun
     * @param  array<string, string>  $kebunNama
     * @param  array<int, string>  $plants
     */
    private function buildTable(array $blocks, array $kebun, array $kebunNama, array $plants): array
    {
        $colTot = ['bi' => array_fill_keys($plants, 0.0), 'sd' => array_fill_keys($plants, 0.0)];
        $grand = ['bi' => 0.0, 'sd' => 0.0];
        $out = ['rows' => [], 'grand' => ['bi' => [], 'sd' => []]];

        foreach ($kebun as $k) {
            $row = ['kebun' => $k, 'nama' => $kebunNama[$k] ?? '', 'bi' => [], 'sd' => []];
            foreach (['bi', 'sd'] as $b) {
                $rt = 0.0;
                foreach ($plants as $p) {
                    $v = (float) ($blocks[$b][$k][$p] ?? 0);
                    $row[$b][$p] = round($v);
                    $rt += $v;
                    $colTot[$b][$p] += $v;
                }
                $row[$b]['grand'] = round($rt);
                $grand[$b] += $rt;
            }
            $out['rows'][] = $row;
        }

        foreach (['bi', 'sd'] as $b) {
            foreach ($plants as $p) {
                $out['grand'][$b][$p] = round($colTot[$b][$p]);
            }
            $out['grand'][$b]['grand'] = round($grand[$b]);
        }

        return $out;
    }

    /**
     * Ringkasan per plant (+ JLH), dua blok. Memakai grand (col total) tiap tabel.
     * Rendemen = ukuran / TBS Olah × 100 (IFERROR→0); JLH dihitung dari total JLH.
     */
    private function buildRingkasan(array $tables, array $plants): array
    {
        $ring = ['bi' => [], 'sd' => []];
        foreach (['bi', 'sd'] as $b) {
            $sum = ['restan_awal' => 0.0, 'tbs_masuk' => 0.0, 'tbs_olah' => 0.0, 'ms' => 0.0, 'is' => 0.0];
            foreach ($plants as $p) {
                $ra = (float) $tables['restan_awal']['grand'][$b][$p];
                $masuk = (float) $tables['tbs_diterima']['grand'][$b][$p];
                $olah = (float) $tables['tbs_diolah']['grand'][$b][$p];
                $ms = (float) $tables['minyak_sawit']['grand'][$b][$p];
                $is = (float) $tables['inti_sawit']['grand'][$b][$p];
                $rms = $olah > 0 ? $ms / $olah * 100 : 0.0;
                $ris = $olah > 0 ? $is / $olah * 100 : 0.0;
                $ring[$b][$p] = [
                    'restan_awal' => $ra, 'tbs_masuk' => $masuk, 'tbs_olah' => $olah,
                    'restan_akhir' => $ra + $masuk - $olah, 'ms' => $ms, 'is' => $is, 'jumlah' => $ms + $is,
                    'rend_ms' => round($rms, 2), 'rend_is' => round($ris, 2), 'rend_total' => round($rms + $ris, 2),
                ];
                $sum['restan_awal'] += $ra;
                $sum['tbs_masuk'] += $masuk;
                $sum['tbs_olah'] += $olah;
                $sum['ms'] += $ms;
                $sum['is'] += $is;
            }
            $olahJ = $sum['tbs_olah'];
            $rmsJ = $olahJ > 0 ? $sum['ms'] / $olahJ * 100 : 0.0;
            $risJ = $olahJ > 0 ? $sum['is'] / $olahJ * 100 : 0.0;
            $ring[$b]['JLH'] = [
                'restan_awal' => $sum['restan_awal'], 'tbs_masuk' => $sum['tbs_masuk'], 'tbs_olah' => $olahJ,
                'restan_akhir' => $sum['restan_awal'] + $sum['tbs_masuk'] - $olahJ,
                'ms' => $sum['ms'], 'is' => $sum['is'], 'jumlah' => $sum['ms'] + $sum['is'],
                'rend_ms' => round($rmsJ, 2), 'rend_is' => round($risJ, 2), 'rend_total' => round($rmsJ + $risJ, 2),
            ];
        }

        return $ring;
    }
}
```

- [ ] **Step 4: Daftarkan route**

Di `routes/web.php`, dalam grup `Route::prefix('report-data')`, tambahkan setelah baris areal:

```php
    Route::get('/areal', [\App\Http\Controllers\Api\ArealController::class, 'index']);
    Route::get('/produksi', [\App\Http\Controllers\Api\ProduksiController::class, 'index']);
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ProduksiApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ProduksiController.php routes/web.php tests/Feature/ProduksiApiTest.php
git commit -m "feat(produksi): endpoint /report-data/produksi (pivot 6 tabel + ringkasan + rendemen)"
```

---

### Task 5: Halaman `/produksi` (blade + route)

**Files:**
- Create: `resources/views/produksi/index.blade.php`
- Modify: `routes/web.php` (ganti route view `/produksi`)
- Test: `tests/Feature/ProduksiPageTest.php`

**Interfaces:**
- Consumes: `GET /report-data/produksi?date=`.
- Produces: halaman `/produksi` (nama route `produksi`) yang me-render pemilih tanggal + ringkasan + 6 tabel pivot Tabulator.

- [ ] **Step 1: Tulis test gagal**

`tests/Feature/ProduksiPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProduksiPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_produksi_render(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $resp = $this->actingAs($user)->get('/produksi');
        $resp->assertOk();
        $resp->assertSee('produksiApp', false);
        $resp->assertSee('/report-data/produksi', false);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=ProduksiPageTest`
Expected: FAIL (route masih `coming-soon`, tak memuat `produksiApp`).

- [ ] **Step 3: Ganti route view `/produksi`**

Di `routes/web.php`, ganti blok:

```php
    Route::view('/produksi', 'coming-soon', [
        'judul' => 'Produksi',
        'subjudul' => 'Laporan Produksi sedang disiapkan dan akan segera tersedia.',
    ])->name('produksi');
```

menjadi:

```php
    Route::view('/produksi', 'produksi.index')->name('produksi');
```

- [ ] **Step 4: Buat view `resources/views/produksi/index.blade.php`**

```blade
@extends('layouts.app')

@section('title', 'Produksi')

@section('content')
<div x-data="produksiApp()" x-init="init()">
    <div class="filter-bar">
        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label">Tanggal Posting</label>
                <select class="filter-select" x-model="date" @change="load()">
                    <option value="">— pilih tanggal —</option>
                    <template x-for="d in dates" :key="d">
                        <option :value="d" x-text="d"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div x-show="errorMsg" x-cloak class="lm-error-panel" x-text="errorMsg"></div>

    <div class="report-card" x-show="hasData" x-cloak>
        {{-- Ringkasan --}}
        <h3 class="prod-title">Ringkasan</h3>
        <div id="prod-ringkasan" class="lm-report-table"></div>

        {{-- 6 tabel pivot --}}
        <template x-for="t in tableDefs" :key="t.key">
            <div style="margin-top:22px">
                <h3 class="prod-title" x-text="t.title"></h3>
                <div :id="'prod-' + t.key" class="lm-report-table"></div>
            </div>
        </template>
    </div>

    <div x-show="!hasData" x-cloak style="background:#fff;padding:4rem 2rem;text-align:center;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)">
        <div style="font-size:3rem;margin-bottom:1rem">🏭</div>
        <h3 style="color:#666;font-weight:500">Pilih tanggal untuk melihat laporan produksi PKS</h3>
    </div>
</div>

<style>
    .prod-title { color: var(--g-700, #0f4c3a); font-weight: 700; margin: 0 0 8px; font-size: 14px; }
</style>
@endsection

@push('scripts')
<script>
function produksiApp() {
    return {
        dates: [],
        date: '',
        plants: [],
        kebun: [],
        payload: null,
        hasData: false,
        errorMsg: null,
        tables: {},
        tableDefs: [
            { key: 'restan_awal', title: 'RESTAN AWAL TBS' },
            { key: 'tbs_diterima', title: 'TBS DITERIMA' },
            { key: 'tbs_diolah', title: 'TBS DIOLAH' },
            { key: 'restan_akhir', title: 'RESTAN AKHIR' },
            { key: 'minyak_sawit', title: 'PRODUKSI MINYAK SAWIT' },
            { key: 'inti_sawit', title: 'PRODUKSI INTI SAWIT' },
        ],

        qtyFmt(cell) {
            const v = cell.getValue();
            return (v == null || Number(v) === 0)
                ? '-'
                : Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },
        rendFmt(cell) {
            const v = cell.getValue();
            return (v == null) ? '-' : Number(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        async init() {
            await this.load();
        },

        async load() {
            this.errorMsg = null;
            try {
                const q = this.date ? ('?date=' + encodeURIComponent(this.date)) : '';
                const resp = await fetch('/report-data/produksi' + q);
                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({}));
                    throw new Error(err.message || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                this.dates = data.dates || [];
                this.date = data.date || '';
                this.plants = data.plants || [];
                this.kebun = data.kebun || [];
                this.payload = data;
                this.hasData = (this.dates.length > 0);
                if (this.hasData) {
                    this.$nextTick(() => this.renderAll(data));
                }
            } catch (e) {
                this.hasData = false;
                this.errorMsg = e.message;
                if (window.lmToast) window.lmToast(e.message, 'err');
            }
        },

        // Kolom dua blok: identitas (Kebun, Nama) frozen + grup BULAN INI + grup S.D BULAN INI.
        pivotColumns() {
            const block = (b) => {
                const cols = this.plants.map(p => ({
                    title: p.code, field: `${b}_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: this.qtyFmt.bind(this), minWidth: 90,
                }));
                cols.push({ title: 'Grand Total', field: `${b}_grand`, hozAlign: 'right', headerHozAlign: 'center', formatter: this.qtyFmt.bind(this), minWidth: 110 });
                return cols;
            };
            return [
                { title: 'Kebun', field: 'kebun', frozen: true, minWidth: 90,
                  formatter: (c) => { const d = c.getRow().getData(); return d._grand ? 'Grand Total' : (d.kebun ?? ''); } },
                { title: 'Nama Kebun', field: 'nama', frozen: true, minWidth: 180 },
                { title: 'BULAN INI', headerHozAlign: 'center', columns: block('bi') },
                { title: 'S.D BULAN INI', headerHozAlign: 'center', columns: block('sd') },
            ];
        },

        pivotRows(tbl) {
            const rows = (tbl.rows || []).map(r => {
                const o = { kebun: r.kebun, nama: r.nama, _grand: false };
                this.plants.forEach(p => { o[`bi_${p.code}`] = r.bi?.[p.code] ?? 0; o[`sd_${p.code}`] = r.sd?.[p.code] ?? 0; });
                o['bi_grand'] = r.bi?.grand ?? 0;
                o['sd_grand'] = r.sd?.grand ?? 0;
                return o;
            });
            const g = { kebun: '', nama: '', _grand: true };
            this.plants.forEach(p => { g[`bi_${p.code}`] = tbl.grand?.bi?.[p.code] ?? 0; g[`sd_${p.code}`] = tbl.grand?.sd?.[p.code] ?? 0; });
            g['bi_grand'] = tbl.grand?.bi?.grand ?? 0;
            g['sd_grand'] = tbl.grand?.sd?.grand ?? 0;
            rows.push(g);
            return rows;
        },

        renderAll(data) {
            // hancurkan tabel lama
            Object.values(this.tables).forEach(t => { try { t.destroy(); } catch (e) {} });
            this.tables = {};

            const mkTable = (id, columns, rows) => new window.Tabulator(id, {
                data: rows, columns,
                columnDefaults: { headerSort: false },
                layout: 'fitDataStretch',
                rowFormatter: (row) => {
                    if (row.getData()._grand) {
                        row.getElement().style.fontWeight = '700';
                        row.getElement().style.background = '#eef5f1';
                    }
                },
            });

            // 6 pivot
            this.tableDefs.forEach(def => {
                const tbl = data.tables?.[def.key];
                if (!tbl) return;
                this.tables[def.key] = mkTable('#prod-' + def.key, this.pivotColumns(), this.pivotRows(tbl));
            });

            // Ringkasan
            this.tables['ringkasan'] = mkTable('#prod-ringkasan', this.ringkasanColumns(), this.ringkasanRows(data.ringkasan));
        },

        ringkasanColumns() {
            const cols = [{ title: 'Uraian', field: 'uraian', frozen: true, minWidth: 150 }];
            const block = (b, label) => {
                const sub = this.plants.map(p => ({
                    title: p.code, field: `${b}_${p.code}`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: (c) => c.getRow().getData()._rend ? this.rendFmt(c) : this.qtyFmt(c), minWidth: 90,
                }));
                sub.push({ title: 'JLH', field: `${b}_JLH`, hozAlign: 'right', headerHozAlign: 'center',
                    formatter: (c) => c.getRow().getData()._rend ? this.rendFmt(c) : this.qtyFmt(c), minWidth: 100 });
                return { title: label, headerHozAlign: 'center', columns: sub };
            };
            cols.push(block('bi', 'BULAN INI'));
            cols.push(block('sd', 'S.D BULAN INI'));
            return cols;
        },

        ringkasanRows(ring) {
            const defs = [
                { f: 'restan_awal', t: 'Restan Awal', rend: false },
                { f: 'tbs_masuk', t: 'TBS Masuk', rend: false },
                { f: 'tbs_olah', t: 'TBS Olah', rend: false },
                { f: 'restan_akhir', t: 'Restan Akhir', rend: false },
                { f: 'ms', t: 'Minyak Sawit', rend: false },
                { f: 'is', t: 'Inti Sawit', rend: false },
                { f: 'jumlah', t: 'Jumlah MS + IS', rend: false },
                { f: 'rend_ms', t: 'Rend. MS (%)', rend: true },
                { f: 'rend_is', t: 'Rend. IS (%)', rend: true },
                { f: 'rend_total', t: 'Rend. MS + IS (%)', rend: true },
            ];
            const cols = [...this.plants.map(p => p.code), 'JLH'];
            return defs.map(d => {
                const o = { uraian: d.t, _rend: d.rend };
                cols.forEach(c => {
                    o[`bi_${c}`] = ring?.bi?.[c]?.[d.f] ?? 0;
                    o[`sd_${c}`] = ring?.sd?.[c]?.[d.f] ?? 0;
                });
                return o;
            });
        },
    };
}
</script>
@endpush
```

- [ ] **Step 5: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=ProduksiPageTest`
Expected: PASS.

- [ ] **Step 6: Jalankan seluruh test produksi**

Run: `php artisan test --filter=Produksi`
Expected: semua PASS (Model, Import, ConfirmProduksi, Api, Page). `ImportProduksiTest` boleh SKIPPED bila file contoh tak ada.

- [ ] **Step 7: Commit**

```bash
git add resources/views/produksi/index.blade.php routes/web.php tests/Feature/ProduksiPageTest.php
git commit -m "feat(produksi): halaman /produksi (ringkasan + 6 tabel pivot dua blok, Tabulator inline)"
```

---

## Catatan Deploy (setelah semua task lulus)

- `git push origin HEAD:main` → server `git pull` → `php artisan migrate --force` (tabel `produksi_pks`) → `php artisan config:clear route:clear cache:clear view:clear`.
- Job/Service/Model berubah → **`systemctl restart lm-reporting-worker`** (wajib; worker memcache kode lama).
- TIDAK perlu `npm run build`/scp aset: JS produksi INLINE di blade, tidak ada kelas Tailwind baru, `app.js/app.css` tak berubah → hash aset tetap.
- Impor data contoh: lewat halaman Import (jenis "Produksi", pilih file `CONTOH_PRODUKSI_PKS.xlsx`) atau CLI `php artisan produksi:import --file=<path>`.
- Validasi angka vs VIEW1 setelah impor: TBS Diterima 5E01/5F01 Bulan Ini = 3.795.250 / S.D = 19.506.780; Grand Total TBS Diterima S.D = 271.474.210; TBS Diolah Grand Total S.D = 268.919.489.
```
