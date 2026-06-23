<?php

namespace Tests\Feature;

use App\Jobs\ProcessImport;
use App\Models\ImportJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportControllerBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ---------------------------------------------------------------------------
    // Helper: buat user Admin dengan Role.
    // ---------------------------------------------------------------------------

    private function adminUser(): User
    {
        $role = Role::query()->create(['name' => Role::ADMIN, 'description' => 'Admin']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    // ---------------------------------------------------------------------------
    // Helper: bangun file budget xlsx (A=komoditi, B=plant, D=period, E=kode, J=nilai)
    // ---------------------------------------------------------------------------

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

    // ---------------------------------------------------------------------------
    // Helper: bangun file GC dengan satu bulan detectable.
    // Kolom Period = kolom H (index 7 dalam GC_COLUMNS).
    // ---------------------------------------------------------------------------

    private function buildGcFile(int $period = 5): string
    {
        $headers = [
            'Cost Center', 'CO Object Name', 'Business Transaction', 'Document Number', 'Ref. document number',
            'Cost Element', 'Cost element name', 'Period', 'Posting Date', 'Value in Obj. Crcy', 'Total quantity',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DB CC GC');
        foreach ($headers as $i => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $label);
        }
        $sheet->setCellValue('AL1', 'Kode');
        $sheet->setCellValue('AP1', 'Komoditi');

        $sheet->setCellValueExplicit('A2', '5E11SP0101', DataType::TYPE_STRING);
        $sheet->setCellValue('H2', $period);
        $sheet->setCellValue('J2', 500000);
        $sheet->setCellValueExplicit('AL2', '1101', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('AP2', 'KS', DataType::TYPE_STRING);

        $path = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);

        return $path;
    }

    // ===========================================================================
    // TEST 1: Budget path — rko_bku, year-only, is_budget=true
    // ===========================================================================

    public function test_budget_store_returns_preview_with_is_budget_true_and_no_month(): void
    {
        $admin = $this->adminUser();

        // Seed unit + template supaya importBudget bisa berjalan.
        DB::table('ref_unit')->insert([
            ['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS'],
        ]);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
        ]);

        $budgetPath = $this->buildBudgetFile('41-01', 1000.0);
        $uploaded = UploadedFile::fake()->createWithContent('bku.xlsx', file_get_contents($budgetPath));
        unlink($budgetPath);

        $response = $this->actingAs($admin)->post('/import', [
            'type' => 'rko_bku',
            'year' => 2026,
            'file' => $uploaded,
        ]);

        $response->assertOk();

        $pending = $response->viewData('pending');
        $this->assertNotNull($pending, 'View harus menyertakan data pending');
        $this->assertTrue($pending['is_budget'], 'is_budget harus true untuk rko_bku');
        $this->assertSame(2026, $pending['year']);
        $this->assertArrayHasKey('token', $pending);
        $this->assertArrayHasKey('ext', $pending);
        $this->assertArrayHasKey('type', $pending);
        $this->assertArrayHasKey('filename', $pending);
        // month tidak wajib untuk budget; bisa null atau tidak ada
        $this->assertNull($pending['month'] ?? null, 'month harus null untuk tipe budget');

        $detectedMonths = $response->viewData('detected_months');
        $this->assertSame([], $detectedMonths, 'detected_months harus [] untuk tipe budget');
    }

    public function test_budget_confirm_saves_to_budget_rko_and_budget_source(): void
    {
        // Confirm sekarang bersifat async: membuat ImportJob (queued) + dispatch ProcessImport.
        // Asersi DB budget_rko/budget_source dipindahkan ke ProcessImportJobTest (Task 3).
        // Di sini kita verifikasi bahwa dispatch terjadi dan ImportJob terbuat dengan benar.
        Queue::fake();

        $admin = $this->adminUser();

        DB::table('ref_unit')->insert([
            ['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS'],
        ]);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
        ]);

        $budgetPath = $this->buildBudgetFile('41-01', 1000.0);
        $uploaded = UploadedFile::fake()->createWithContent('bku.xlsx', file_get_contents($budgetPath));
        unlink($budgetPath);

        // Langkah 1: preview
        $preview = $this->actingAs($admin)->post('/import', [
            'type' => 'rko_bku',
            'year' => 2026,
            'file' => $uploaded,
        ]);
        $preview->assertOk();
        $pending = $preview->viewData('pending');

        // Langkah 2: konfirmasi → JSON 202 (async dispatch)
        $confirm = $this->actingAs($admin)->postJson('/import/confirm', [
            'token' => $pending['token'],
            'ext'   => $pending['ext'],
            'type'  => $pending['type'],
            'year'  => $pending['year'],
        ]);

        $confirm->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);

        // ImportJob harus terbuat dengan type dan year yang benar.
        $this->assertSame(1, ImportJob::query()->count(), 'Harus ada 1 ImportJob setelah confirm');
        $importJob = ImportJob::query()->first();
        $this->assertSame('rko_bku', $importJob->type, 'type harus rko_bku');
        $this->assertSame(2026, (int) $importJob->year, 'year harus 2026');
        $this->assertSame('queued', $importJob->status, 'status awal harus queued');
        $this->assertNull($importJob->month, 'month harus null untuk tipe budget');

        // ProcessImport harus di-dispatch ke queue.
        Queue::assertPushed(ProcessImport::class, function (ProcessImport $job) use ($importJob) {
            return $job->importJobId === $importJob->id;
        });
    }

    // ===========================================================================
    // TEST 2: Realisasi path — GC, year+month auto-detect, batch dibuat
    // ===========================================================================

    public function test_realisasi_store_detects_month_and_creates_batch_on_confirm(): void
    {
        $admin = $this->adminUser();

        $gcPath = $this->buildGcFile(5); // period = 5
        $uploaded = UploadedFile::fake()->createWithContent('gc.xlsx', file_get_contents($gcPath));
        unlink($gcPath);

        // Langkah 1: preview
        $preview = $this->actingAs($admin)->post('/import', [
            'type' => 'gc',
            'year' => 2026,
            'file' => $uploaded,
        ]);
        $preview->assertOk();

        $pending = $preview->viewData('pending');
        $this->assertNotNull($pending);
        $this->assertFalse($pending['is_budget'], 'is_budget harus false untuk tipe gc');
        $this->assertSame(2026, $pending['year']);
        $this->assertSame(5, $pending['month'], 'month harus terdeteksi otomatis = 5');

        $detectedMonths = $preview->viewData('detected_months');
        $this->assertSame([5], $detectedMonths, 'detected_months harus [5]');

        // Langkah 2: konfirmasi → JSON 202 (async dispatch)
        // Pembuatan Batch sekarang terjadi di dalam ProcessImport job (diuji di ProcessImportJobTest).
        Queue::fake();
        $confirm = $this->actingAs($admin)->postJson('/import/confirm', [
            'token' => $pending['token'],
            'ext'   => $pending['ext'],
            'type'  => $pending['type'],
            'year'  => $pending['year'],
            'month' => $pending['month'],
        ]);

        $confirm->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);

        // ImportJob harus terbuat dengan type, year, dan month yang benar.
        $this->assertSame(1, ImportJob::query()->count(), 'Harus ada 1 ImportJob setelah confirm');
        $importJob = ImportJob::query()->first();
        $this->assertSame('gc', $importJob->type, 'type harus gc');
        $this->assertSame(2026, (int) $importJob->year, 'year harus 2026');
        $this->assertSame(5, (int) $importJob->month, 'month harus 5');
        $this->assertSame('queued', $importJob->status, 'status awal harus queued');

        // ProcessImport harus di-dispatch ke queue.
        Queue::assertPushed(ProcessImport::class, function (ProcessImport $job) use ($importJob) {
            return $job->importJobId === $importJob->id;
        });
    }
}
