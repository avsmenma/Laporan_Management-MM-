# Import Async (queue) + Popup Progress & Notifikasi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jalankan import (konfirmasi) di latar belakang via queue worker, dengan popup progress bar + notifikasi berhasil/gagal, dan overlay+toast untuk pratinjau & hapus data.

**Architecture:** `confirm()` membuat baris `import_jobs` lalu men-dispatch job `ProcessImport` (queue `database`); worker systemd memprosesnya sambil meng-update `processed`; UI fetch confirm → modal polling `GET /import/status/{id}` → toast. Pratinjau & hapus tetap sinkron tapi diberi overlay + toast.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, queue driver database, Blade + Alpine.js, OpenSpout, PHPUnit. Build: Vite/Tailwind.

## Global Constraints

- VPS 1-core/~236MB bebas → 1 worker streaming saja; `tries=1`, `timeout=3600`.
- Import idempoten (hapus data batch/sumber dulu sebelum insert) — aman dijalankan ulang.
- RKO/RKAP rupiah penuh; nilai identik ke budget_rko & budget_rkap (tak berubah di sini).
- `git add` per-file; pesan commit Bahasa Indonesia.
- Perubahan JS/CSS → WAJIB `npm run build` + scp `public/build` saat deploy (server tanpa node_modules); kelas Tailwind baru hanya ter-compile saat build.
- Backend import type tetap 6: wbs/ohc/gc/rko_bku/rko_ohc/rko_gc.
- Spec: `docs/superpowers/specs/2026-06-23-import-async-progress-design.md`.

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_06_25_000000_create_import_jobs_table.php` | (baru) tabel status/progres import. |
| `app/Models/ImportJob.php` | (baru) model import_jobs. |
| `app/Jobs/ProcessImport.php` | (baru) job antrian: proses file + update progres. |
| `app/Domain/Import/SpreadsheetImportService.php` | (ubah) param `?callable $onProgress`; method publik `dataRowCount`. |
| `app/Http/Controllers/Import/ImportController.php` | (ubah) `confirm()` JSON+dispatch; `status()`. |
| `routes/web.php` | (ubah) route `import.status`. |
| `resources/views/layouts/app.blade.php` | (ubah) komponen toast global + helper `lmToast`. |
| `resources/views/import/index.blade.php` | (ubah) fetch confirm + modal progress + overlay pratinjau. |
| `resources/views/admin/purge.blade.php` | (ubah) overlay submit + toast. |
| `.env` | (ubah) `QUEUE_CONNECTION=database`. |
| Server: `/etc/systemd/system/lm-reporting-worker.service` | (baru) worker. |

---

## Task 1: Tabel & model `import_jobs`

**Files:**
- Create: `database/migrations/2026_06_25_000000_create_import_jobs_table.php`
- Create: `app/Models/ImportJob.php`
- Test: `tests/Feature/ImportJobModelTest.php`

**Interfaces:**
- Produces: tabel `import_jobs`; model `App\Models\ImportJob` (guarded=[], casts processed/total/row_count int). Kolom: id, user_id?, type, year, month?, filename, staged_path, ext, status('queued'|'processing'|'done'|'failed'), total, processed, row_count, error?, timestamps.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportJobModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_jobs_table_and_defaults(): void
    {
        foreach (['type', 'year', 'status', 'total', 'processed', 'row_count', 'staged_path', 'error'] as $col) {
            $this->assertTrue(Schema::hasColumn('import_jobs', $col), "kolom {$col} ada");
        }

        $job = ImportJob::query()->create([
            'type' => 'wbs', 'year' => 2026, 'month' => 5,
            'filename' => 'x.xlsx', 'staged_path' => 'import-staging/x.xlsx', 'ext' => 'xlsx',
        ]);

        $this->assertSame('queued', $job->status);
        $this->assertSame(0, $job->processed);
        $this->assertSame(0, $job->total);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ImportJobModelTest`
Expected: FAIL — tabel/model belum ada.

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
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 20);
            $table->smallInteger('year');
            $table->tinyInteger('month')->nullable();
            $table->string('filename', 255);
            $table->string('staged_path', 255);
            $table->string('ext', 8);
            $table->enum('status', ['queued', 'processing', 'done', 'failed'])->default('queued');
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->integer('row_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $table = 'import_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total' => 'integer', 'processed' => 'integer', 'row_count' => 'integer'];
    }
}
```

- [ ] **Step 5: Run test + migrate dev DB**

Run: `php artisan test --filter=ImportJobModelTest` → PASS
Run: `php artisan migrate` → migrasi import_jobs DONE.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_25_000000_create_import_jobs_table.php app/Models/ImportJob.php tests/Feature/ImportJobModelTest.php
git commit -m "feat(import): tabel & model import_jobs untuk status/progres"
```

---

## Task 2: Callback progres di SpreadsheetImportService

**Files:**
- Modify: `app/Domain/Import/SpreadsheetImportService.php`
- Test: `tests/Feature/ImportProgressCallbackTest.php`

**Interfaces:**
- Consumes: —
- Produces:
  - `import(string $type, Batch $batch, UploadedFile|string $file, ?int $userId = null, ?callable $onProgress = null): ImportResult`
  - `importBudget(int $year, string $type, UploadedFile|string $file, ?int $userId = null, ?callable $onProgress = null): ImportResult`
  - `dataRowCount(string $path): int` (publik; bungkus `totalDataRows`)
  - `$onProgress` dipanggil dengan jumlah baris terproses (integer, kumulatif) — minimal sekali setelah selesai, dan per-chunk bila memungkinkan.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportProgressCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_invokes_progress_callback_and_counts_rows(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $path = $this->buildGcFile(); // 2 baris data

        $this->assertSame(2, app(SpreadsheetImportService::class)->dataRowCount($path));

        $seen = [];
        app(SpreadsheetImportService::class)->import('gc', $batch, $path, null, function (int $n) use (&$seen) {
            $seen[] = $n;
        });

        $this->assertNotEmpty($seen, 'callback progres terpanggil');
        $this->assertSame(2, end($seen), 'progres akhir = jumlah baris');

        unlink($path);
    }

    private function buildGcFile(): string
    {
        $headers = ['Cost Center', 'CO Object Name', 'Business Transaction', 'Document Number', 'Ref', 'Cost Element', 'Cost element name', 'Period', 'Posting Date', 'Value in Obj. Crcy', 'Total quantity'];
        $s = new Spreadsheet;
        $sh = $s->getActiveSheet();
        $sh->setTitle('DB CC GC');
        foreach ($headers as $i => $l) {
            $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $l);
        }
        $sh->setCellValue('AL1', 'Kode');
        $sh->setCellValue('AP1', 'Komoditi');
        $sh->setCellValueExplicit('A2', '5E01AP0104', DataType::TYPE_STRING);
        $sh->setCellValue('H2', 5);
        $sh->setCellValue('J2', 100);
        $sh->setCellValueExplicit('AP2', 'KS', DataType::TYPE_STRING);
        $sh->setCellValueExplicit('A3', '5E11SP0101', DataType::TYPE_STRING);
        $sh->setCellValue('H3', 5);
        $sh->setCellValue('J3', 200);
        $sh->setCellValueExplicit('AP3', 'KS', DataType::TYPE_STRING);
        $p = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        (new Xlsx($s))->save($p);

        return $p;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ImportProgressCallbackTest`
Expected: FAIL — `dataRowCount` & param `$onProgress` belum ada.

- [ ] **Step 3: Implement**

Di `SpreadsheetImportService`:

1. Tambah method publik tepat di bawah `preview()`:

```php
    /** Jumlah baris data (tanpa header) — cepat (tanpa muat sel). Untuk progres import. */
    public function dataRowCount(string $path): int
    {
        return $this->totalDataRows($path);
    }
```

2. Ubah signature `import()` menjadi:

```php
    public function import(string $type, Batch $batch, UploadedFile|string $file, ?int $userId = null, ?callable $onProgress = null): ImportResult
```

   dan teruskan `$onProgress` ke `importRaw(...)` pada ketiga cabang match (wbs/ohc/gc) sebagai argumen terakhir.

3. Ubah `importRaw(Batch $batch, string $table, array $columns, iterable $rows, string $kind, ?callable $onProgress = null): ImportResult`. BACA isi method ini; di titik setelah SETIAP chunk di-insert (tempat `$inserted` bertambah), panggil:

```php
            if ($onProgress !== null) {
                $onProgress($inserted);
            }
```

   Pastikan juga dipanggil sekali setelah loop (untuk sisa chunk terakhir / total akhir).

4. Ubah signature `importBudget(int $year, string $type, UploadedFile|string $file, ?int $userId = null, ?callable $onProgress = null): ImportResult`. Di dalam loop `foreach ($this->dataRows($path) as $c)`, hitung `$seen++` per baris non-kosong dan panggil `$onProgress($seen)` tiap kelipatan 500 (dan sekali di akhir sebelum return).

Tanpa `$onProgress` → perilaku lama (test & CLI lama tetap lulus).

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter='ImportProgressCallbackTest|ImportServiceTest|BudgetImportTest'`
Expected: PASS semua (callback baru + test lama tak berubah perilaku).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Import/SpreadsheetImportService.php tests/Feature/ImportProgressCallbackTest.php
git commit -m "feat(import): callback progres + dataRowCount pada SpreadsheetImportService"
```

---

## Task 3: Job `ProcessImport`

**Files:**
- Create: `app/Jobs/ProcessImport.php`
- Test: `tests/Feature/ProcessImportJobTest.php`

**Interfaces:**
- Consumes: `ImportJob` (Task 1); `SpreadsheetImportService::{import,importBudget,dataRowCount,isBudget}` (Task 2); `Batch`.
- Produces: `ProcessImport(int $importJobId)` queued job. `handle()`: status `processing` + set `total`; jalankan import dgn callback update `processed`; sukses → `done`+`row_count` (+ tandai needs_regenerate seperti controller lama); gagal → `failed`+`error`; selalu hapus file staging.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessImport;
use App\Models\Batch;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProcessImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_realisasi_file_and_marks_done(): void
    {
        Storage::fake('local');
        // Buat file gc kecil di disk staging palsu.
        $tmp = $this->buildGcFile();
        $staged = 'import-staging/job.xlsx';
        Storage::disk('local')->put($staged, file_get_contents($tmp));
        unlink($tmp);

        $importJob = ImportJob::query()->create([
            'type' => 'gc', 'year' => 2026, 'month' => 5,
            'filename' => 'gc.xlsx', 'staged_path' => $staged, 'ext' => 'xlsx',
        ]);

        (new ProcessImport($importJob->id))->handle(app(\App\Domain\Import\SpreadsheetImportService::class));

        $importJob->refresh();
        $this->assertSame('done', $importJob->status);
        $this->assertSame(2, $importJob->row_count);
        $this->assertGreaterThan(0, $importJob->total);
        $this->assertSame(2, DB::table('db_gc')->count());
        $this->assertFalse(Storage::disk('local')->exists($staged), 'file staging dihapus');
        // batch dibuat & ditandai
        $this->assertSame(1, Batch::query()->where('year', 2026)->where('month', 5)->count());
    }

    private function buildGcFile(): string
    {
        $headers = ['Cost Center', 'CO Object Name', 'Business Transaction', 'Document Number', 'Ref', 'Cost Element', 'Cost element name', 'Period', 'Posting Date', 'Value in Obj. Crcy', 'Total quantity'];
        $s = new Spreadsheet;
        $sh = $s->getActiveSheet();
        $sh->setTitle('DB CC GC');
        foreach ($headers as $i => $l) {
            $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $l);
        }
        $sh->setCellValue('AL1', 'Kode');
        $sh->setCellValue('AP1', 'Komoditi');
        $sh->setCellValueExplicit('A2', '5E01AP0104', DataType::TYPE_STRING);
        $sh->setCellValue('H2', 5);
        $sh->setCellValueExplicit('AP2', 'KS', DataType::TYPE_STRING);
        $sh->setCellValueExplicit('A3', '5E11SP0101', DataType::TYPE_STRING);
        $sh->setCellValue('H3', 5);
        $sh->setCellValueExplicit('AP3', 'KS', DataType::TYPE_STRING);
        $p = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        (new Xlsx($s))->save($p);

        return $p;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProcessImportJobTest`
Expected: FAIL — `App\Jobs\ProcessImport` belum ada.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use App\Models\ImportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $importJobId) {}

    public function handle(SpreadsheetImportService $service): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job === null) {
            return;
        }

        $path = Storage::disk('local')->path($job->staged_path);
        if (! Storage::disk('local')->exists($job->staged_path)) {
            $job->forceFill(['status' => 'failed', 'error' => 'Berkas staging tidak ditemukan.'])->save();

            return;
        }

        $isBudget = SpreadsheetImportService::isBudget($job->type);
        $job->forceFill(['status' => 'processing', 'total' => $service->dataRowCount($path)])->save();

        // Throttle update DB: maksimal tiap 500 baris.
        $onProgress = function (int $n) use ($job): void {
            if ($n - $job->processed >= 500) {
                $job->forceFill(['processed' => $n])->save();
            }
        };

        try {
            if ($isBudget) {
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

            $job->forceFill([
                'status' => 'done',
                'processed' => $result->rowCount,
                'row_count' => $result->rowCount,
            ])->save();
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
        } finally {
            Storage::disk('local')->delete($job->staged_path);
        }
    }
}
```

- [ ] **Step 4: Run test**

Run: `php artisan test --filter=ProcessImportJobTest` → PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProcessImport.php tests/Feature/ProcessImportJobTest.php
git commit -m "feat(import): job ProcessImport memproses file di latar + update progres"
```

---

## Task 4: Controller confirm (AJAX dispatch) + endpoint status

**Files:**
- Modify: `app/Http/Controllers/Import/ImportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ImportConfirmDispatchTest.php`

**Interfaces:**
- Consumes: `ImportJob`, `ProcessImport`, `SpreadsheetImportService::isBudget`.
- Produces:
  - `confirm()` → JSON `{ job_id, status_url }`, status 202; membuat `import_jobs` (queued) + dispatch `ProcessImport`; TIDAK menghapus file staging (job yang hapus).
  - `status(ImportJob $importJob)` → JSON `{ status, processed, total, row_count, error }` (route name `import.status`, hanya Operator/Admin).

- [ ] **Step 1: Write the failing test**

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

class ImportConfirmDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function operator(): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'op']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_confirm_creates_import_job_and_dispatches(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('import-staging/abc.xlsx', 'dummy');

        $token = '00000000-0000-0000-0000-000000000abc';
        // confirm memvalidasi token regex 36 char; pakai uuid valid:
        $token = (string) \Illuminate\Support\Str::uuid();
        Storage::disk('local')->put("import-staging/{$token}.xlsx", 'dummy');

        $res = $this->actingAs($this->operator())->postJson('/import/confirm', [
            'token' => $token, 'ext' => 'xlsx', 'type' => 'gc', 'year' => 2026, 'month' => 5,
        ]);

        $res->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);
        $this->assertSame(1, ImportJob::query()->count());
        Queue::assertPushed(ProcessImport::class);
    }

    public function test_status_endpoint_returns_progress(): void
    {
        $job = ImportJob::query()->create([
            'type' => 'gc', 'year' => 2026, 'month' => 5, 'filename' => 'g.xlsx',
            'staged_path' => 'import-staging/x.xlsx', 'ext' => 'xlsx',
            'status' => 'processing', 'total' => 10, 'processed' => 4,
        ]);

        $res = $this->actingAs($this->operator())->getJson("/import/status/{$job->id}");

        $res->assertOk()->assertJson(['status' => 'processing', 'processed' => 4, 'total' => 10]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ImportConfirmDispatchTest`
Expected: FAIL — confirm masih sinkron / route status belum ada.

- [ ] **Step 3: Rewrite `confirm()` + add `status()`**

Ganti seluruh isi method `confirm()` menjadi (validasi sama, lalu buat job + dispatch):

```php
    public function confirm(Request $request): \Illuminate\Http\JsonResponse
    {
        $type = (string) $request->input('type');
        $isBudget = SpreadsheetImportService::isBudget($type);

        $rules = [
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext'   => ['required', 'in:xlsx,xls,csv'],
            'type'  => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
        if (! $isBudget) {
            $rules['month'] = ['required', 'integer', 'min:1', 'max:12'];
        }
        $data = $request->validate($rules);

        $staged = "import-staging/{$data['token']}.{$data['ext']}";
        if (! Storage::disk('local')->exists($staged)) {
            return response()->json(['message' => 'Berkas pratinjau kedaluwarsa. Unggah ulang.'], 422);
        }

        $job = \App\Models\ImportJob::query()->create([
            'user_id'     => $request->user()->id,
            'type'        => $type,
            'year'        => (int) $data['year'],
            'month'       => $isBudget ? null : (int) $data['month'],
            'filename'    => "{$data['token']}.{$data['ext']}",
            'staged_path' => $staged,
            'ext'         => $data['ext'],
            'status'      => 'queued',
        ]);

        \App\Jobs\ProcessImport::dispatch($job->id);

        return response()->json([
            'job_id'     => $job->id,
            'status_url' => route('import.status', $job),
        ], 202);
    }

    public function status(\App\Models\ImportJob $importJob): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status'    => $importJob->status,
            'processed' => $importJob->processed,
            'total'     => $importJob->total,
            'row_count' => $importJob->row_count,
            'error'     => $importJob->error,
        ]);
    }
```

Hapus `import ImportUploadLog`/`Storage` yang tak terpakai bila perlu (Storage masih dipakai cancel & confirm). Pastikan `use Illuminate\Http\JsonResponse;` ada atau pakai FQCN seperti di atas.

- [ ] **Step 4: Add route**

Di `routes/web.php`, dalam grup `role:Operator,Admin` (dekat route import lain):

```php
    Route::get('/import/status/{importJob}', [ImportController::class, 'status'])->name('import.status');
```

- [ ] **Step 5: Run test**

Run: `php artisan test --filter=ImportConfirmDispatchTest` → PASS
Run: `php artisan test --filter=ImportControllerBudgetTest` → pastikan test confirm lama tidak ada (Task 7 dulu menguji confirm sinkron — jika ada test confirm sinkron, perbarui ke ekspektasi JSON 202 + Queue::fake; jangan melemahkan asersi).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Import/ImportController.php routes/web.php tests/Feature/ImportConfirmDispatchTest.php
git commit -m "feat(import): confirm men-dispatch job (JSON 202) + endpoint status progres"
```

---

## Task 5: Frontend — toast global, modal progress import, overlay pratinjau/hapus

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (komponen toast + helper `lmToast` + CSS)
- Modify: `resources/views/import/index.blade.php` (fetch confirm + modal progress + overlay pratinjau)
- Modify: `resources/views/admin/purge.blade.php` (overlay submit)

**Interfaces:**
- Consumes: endpoint `POST /import/confirm` (JSON 202 `{job_id,status_url}`) & `GET /import/status/{id}` (Task 4); flash `session('status')`.
- Produces: `window.lmToast(message, type)` global; modal `#lm-import-progress`; overlay `.lm-overlay`.

- [ ] **Step 1: Tambah komponen toast + overlay global di `layouts.app`**

Sebelum `@stack('scripts')` (dekat akhir body), tambah markup + style + script:

```blade
    {{-- Toast notifikasi global --}}
    <div id="lm-toasts" style="position:fixed;top:16px;right:16px;z-index:200;display:flex;flex-direction:column;gap:8px"></div>
    {{-- Overlay proses (pratinjau/hapus) --}}
    <div id="lm-overlay" style="display:none;position:fixed;inset:0;z-index:190;background:rgba(15,76,58,.35);align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:12px;padding:22px 26px;box-shadow:0 12px 40px rgba(0,0,0,.25);display:flex;gap:14px;align-items:center">
            <span class="lm-spin" style="width:22px;height:22px;border:3px solid var(--g-100,#cfe6db);border-top-color:var(--g-700,#0f4c3a);border-radius:50%;display:inline-block;animation:lmspin .8s linear infinite"></span>
            <span id="lm-overlay-text" style="font-weight:600;color:var(--ink-800,#1f2a26)">Memproses…</span>
        </div>
    </div>
    <style>@keyframes lmspin{to{transform:rotate(360deg)}}</style>
    <script>
        window.lmToast = function (message, type) {
            var wrap = document.getElementById('lm-toasts');
            if (!wrap) return;
            var el = document.createElement('div');
            var ok = type !== 'err';
            el.style.cssText = 'min-width:240px;max-width:360px;padding:12px 14px;border-radius:9px;color:#fff;font-size:13px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.2);background:' + (ok ? '#0f8a5f' : '#c0392b');
            el.textContent = message;
            wrap.appendChild(el);
            setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function(){ el.remove(); }, 400); }, ok ? 5000 : 8000);
        };
        window.lmOverlay = function (show, text) {
            var o = document.getElementById('lm-overlay');
            if (!o) return;
            if (text) document.getElementById('lm-overlay-text').textContent = text;
            o.style.display = show ? 'flex' : 'none';
        };
        // Tampilkan flash status sbg toast saat halaman dimuat.
        @if (session('status'))
            window.lmToast(@json(session('status')), 'ok');
        @endif
    </script>
```

- [ ] **Step 2: Import — fetch confirm + modal progress**

Di `resources/views/import/index.blade.php`, ganti blok form Konfirmasi (`route('import.confirm')`) sehingga submit lewat JS. Bungkus area pratinjau dgn Alpine `x-data="lmImportProgress()"`. Tambah:

1. Form pratinjau (`route('import.store')`): tambah `@submit="window.lmOverlay(true,'Memproses pratinjau…')"` (overlay sinkron; halaman reload).
2. Form konfirmasi → tombol jadi `type="button"` memanggil `confirm()`:

```blade
                <div class="flex items-center gap-3" style="margin-top:16px" x-data="lmImportProgress()">
                    <button class="btn btn-primary" type="button"
                        @click="confirm({
                            token: '{{ $pending['token'] }}', ext: '{{ $pending['ext'] }}',
                            type: '{{ $pending['type'] }}', year: {{ (int) $pending['year'] }},
                            month: {{ $pending['is_budget'] ?? false ? 'null' : (int) ($pending['month'] ?? 0) }}
                        })">Konfirmasi &amp; Simpan ke Database</button>
                    <form method="POST" action="{{ route('import.cancel') }}">
                        @csrf
                        <input type="hidden" name="token" value="{{ $pending['token'] }}">
                        <input type="hidden" name="ext" value="{{ $pending['ext'] }}">
                        <button class="btn btn-outline" type="submit">Batalkan</button>
                    </form>

                    {{-- Modal progress --}}
                    <div x-show="open" x-cloak style="position:fixed;inset:0;z-index:195;background:rgba(15,76,58,.4);display:flex;align-items:center;justify-content:center">
                        <div style="background:#fff;border-radius:12px;padding:24px;min-width:340px;box-shadow:0 16px 48px rgba(0,0,0,.3)">
                            <div style="font-weight:700;margin-bottom:12px" x-text="title"></div>
                            <div style="height:12px;background:var(--line,#e5ece9);border-radius:99px;overflow:hidden">
                                <div style="height:100%;background:var(--g-700,#0f4c3a);transition:width .4s" :style="`width:${pct}%`"></div>
                            </div>
                            <div style="margin-top:10px;font-size:12.5px;color:var(--ink-600,#54625c)" x-text="label"></div>
                        </div>
                    </div>
                </div>
```

3. Tambahkan script Alpine di `@push('scripts')` (atau bagian script halaman):

```blade
@push('scripts')
<script>
    function lmImportProgress() {
        return {
            open: false, pct: 0, title: 'Mengimpor…', label: 'Menyiapkan…',
            async confirm(payload) {
                this.open = true; this.pct = 0; this.title = 'Mengimpor ' + payload.type; this.label = 'Mengantre…';
                try {
                    const res = await fetch('{{ route('import.confirm') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error(e.message || 'Gagal memulai import'); }
                    const { status_url } = await res.json();
                    this.poll(status_url);
                } catch (e) { this.open = false; window.lmToast(e.message, 'err'); }
            },
            poll(url) {
                const tick = async () => {
                    try {
                        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const s = await r.json();
                        if (s.total > 0) this.pct = Math.min(100, Math.round(s.processed / s.total * 100));
                        this.label = (s.processed || 0).toLocaleString('id-ID') + ' / ' + (s.total || 0).toLocaleString('id-ID') + ' baris (' + this.pct + '%)';
                        if (s.status === 'done') { this.open = false; window.lmToast('Import berhasil: ' + s.row_count + ' baris', 'ok'); setTimeout(() => location.reload(), 1200); return; }
                        if (s.status === 'failed') { this.open = false; window.lmToast('Import gagal: ' + (s.error || 'tidak diketahui'), 'err'); return; }
                        setTimeout(tick, 2000);
                    } catch (e) { setTimeout(tick, 3000); }
                };
                tick();
            },
        };
    }
</script>
@endpush
```

(Pastikan layout meletakkan `@stack('scripts')` — sudah ada di `layouts.app`.)

- [ ] **Step 3: Hapus data — overlay saat submit**

Di `resources/views/admin/purge.blade.php`, pada form purge tambah `@submit="window.lmOverlay(true,'Menghapus data…')"` (atau `onsubmit="window.lmOverlay(true,'Menghapus data…')"` bila tanpa Alpine). Flash status akan muncul sebagai toast saat reload (via Step 1).

- [ ] **Step 4: Build assets + verifikasi suite**

Run: `npm run build` (kelas/JS baru ter-compile).
Run: `php artisan test` → semua hijau (perubahan view tak memecah test controller).
Manual: jelaskan di laporan cara uji (pratinjau→overlay; konfirmasi→modal progress; hapus→overlay+toast).

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/import/index.blade.php resources/views/admin/purge.blade.php
git commit -m "feat(ui): toast global + modal progress import + overlay pratinjau/hapus"
```

---

## Task 6: Aktifkan queue + worker + deploy (ops)

**Files:** `.env` (server), `/etc/systemd/system/lm-reporting-worker.service` (server). Tidak ada test otomatis — verifikasi operasional.

- [ ] **Step 1: Lokal — set QUEUE_CONNECTION & uji end-to-end manual**

Di `.env` lokal pastikan `QUEUE_CONNECTION=database`. Jalankan `php artisan queue:work database` di terminal terpisah, lalu uji import via web lokal: pratinjau → konfirmasi → modal progress jalan → selesai toast.

- [ ] **Step 2: Push semua commit**

```bash
git push origin main
```

- [ ] **Step 3: Deploy kode + asset ke server**

```bash
# di mesin lokal
npm run build
scp -i <key> public/build/assets/* root@163.61.58.92:/var/www/lm-reporting/lm-reporting/public/build/assets/
scp -i <key> public/build/manifest.json root@163.61.58.92:/var/www/lm-reporting/lm-reporting/public/build/manifest.json
```

Di server:
```bash
cd /var/www/lm-reporting/lm-reporting
git pull origin main
php artisan migrate --force            # buat tabel import_jobs
# set QUEUE_CONNECTION=database di .env (ganti baris 'sync')
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' .env
php artisan config:clear && php artisan route:clear && php artisan cache:clear && php artisan view:clear
# bersihkan asset hash lama yg tak ada di manifest (lihat manifest), chown
chown -R www-data:www-data public/build
```

- [ ] **Step 4: Pasang worker systemd**

Buat `/etc/systemd/system/lm-reporting-worker.service`:
```ini
[Unit]
Description=LM Reporting queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/lm-reporting/lm-reporting
ExecStart=/usr/bin/php /var/www/lm-reporting/lm-reporting/artisan queue:work database --sleep=3 --tries=1 --max-time=3600
StartLimitIntervalSec=0

[Install]
WantedBy=multi-user.target
```
Lalu:
```bash
systemctl daemon-reload
systemctl enable --now lm-reporting-worker
systemctl status lm-reporting-worker --no-pager
```

- [ ] **Step 5: Verifikasi di server**

- `systemctl status lm-reporting-worker` → active (running).
- Upload file via web → konfirmasi → modal progress bergerak → selesai toast; cek `import_jobs` status `done`, data masuk DB.
- Restart worker setelah tiap deploy berikutnya: `systemctl restart lm-reporting-worker` (kode job ter-cache di worker lama).

- [ ] **Step 6: Catat ke memory deploy** (langkah deploy app ini sekarang menyertakan `systemctl restart lm-reporting-worker`).

---

## Self-Review Notes

- **Spec coverage:** import_jobs (T1) ✓; queue config + worker (T6) ✓; progress callback (T2) ✓; ProcessImport job + staging cleanup + needs_regenerate (T3) ✓; confirm AJAX + status endpoint (T4) ✓; toast + modal progress + overlay pratinjau/hapus (T5) ✓; build/deploy asset (T6) ✓.
- **Verifikasi sebelum coding:** (a) isi `importRaw` untuk titik panggil `$onProgress` (T2) — baca method. (b) apakah ada test lama yang menguji `confirm()` sinkron (T4) — perbarui ke JSON 202, jangan lemahkan. (c) `purge.blade.php` apakah pakai Alpine — sesuaikan @submit/onsubmit. (d) `Role::OPERATOR` konstanta ada (dipakai test).
- **Type consistency:** `ProcessImport(int $importJobId)`; `import(...,?callable $onProgress)`; `importBudget(...,?callable $onProgress)`; `dataRowCount(string):int`; status JSON keys `{status,processed,total,row_count,error}` konsisten T3/T4/T5.
- **Catatan penting deploy:** setelah deploy yang mengubah kode Job/Service, WAJIB `systemctl restart lm-reporting-worker` (worker memcache kode lama).
