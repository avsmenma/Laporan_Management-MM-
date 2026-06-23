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

    /** (a) File staging tidak ditemukan → status 'failed' dengan pesan error. */
    public function test_missing_staged_file_marks_job_failed(): void
    {
        Storage::fake('local');

        $staged = 'import-staging/tidak-ada.xlsx';
        // Sengaja TIDAK membuat file di disk palsu.

        $importJob = ImportJob::query()->create([
            'type' => 'gc', 'year' => 2026, 'month' => 5,
            'filename' => 'tidak-ada.xlsx', 'staged_path' => $staged, 'ext' => 'xlsx',
        ]);

        (new ProcessImport($importJob->id))->handle(app(\App\Domain\Import\SpreadsheetImportService::class));

        $importJob->refresh();
        $this->assertSame('failed', $importJob->status);
        $this->assertNotEmpty($importJob->error, 'pesan error harus diisi');
    }

    /** (b) File staging corrupt (bukan xlsx valid) → status 'failed' DAN file staging dihapus. */
    public function test_corrupt_staged_file_marks_job_failed_and_deletes_staged(): void
    {
        Storage::fake('local');

        $staged = 'import-staging/corrupt.xlsx';
        // Tulis konten bukan xlsx agar parser melempar exception.
        Storage::disk('local')->put($staged, 'not a real xlsx');

        $importJob = ImportJob::query()->create([
            'type' => 'gc', 'year' => 2026, 'month' => 5,
            'filename' => 'corrupt.xlsx', 'staged_path' => $staged, 'ext' => 'xlsx',
        ]);

        (new ProcessImport($importJob->id))->handle(app(\App\Domain\Import\SpreadsheetImportService::class));

        $importJob->refresh();
        $this->assertSame('failed', $importJob->status, 'status harus failed');
        $this->assertFalse(Storage::disk('local')->exists($staged), 'file staging harus dihapus oleh finally');
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
