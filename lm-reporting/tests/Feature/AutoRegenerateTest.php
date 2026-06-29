<?php

namespace Tests\Feature;

use App\Jobs\ProcessImport;
use App\Jobs\RegenerateReports;
use App\Models\Batch;
use App\Models\ImportJob;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AutoRegenerateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::ADMIN], ['description' => 'admin']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    // ── Job RegenerateReports ────────────────────────────────────────────────

    public function test_job_meregenerasi_batch_dan_menghapus_flag(): void
    {
        RefUnit::query()->create(['code' => '5E11', 'name' => 'A', 'type' => 'KEBUN', 'komoditi' => null]);
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft', 'needs_regenerate' => true]);

        (new RegenerateReports([$batch->id]))->handle(app(\App\Domain\Report\ReportGenerateService::class));

        $batch->refresh();
        $this->assertFalse((bool) $batch->needs_regenerate);
        $this->assertNotNull($batch->processed_at);
    }

    // ── Trigger: HAPUS DATA selektif ─────────────────────────────────────────

    public function test_hapus_anggaran_selektif_men_dispatch_regenerasi(): void
    {
        Queue::fake();
        Batch::query()->create(['code' => 'Batch #2026-01', 'year' => 2026, 'month' => 1, 'status' => 'draft']);
        Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        DB::table('budget_rko')->insert(['year' => 2026, 'komoditi' => 'KS', 'plant_code' => '5E11', 'report_type' => 'LM14', 'kode' => '99-01', 'nilai' => 100]);

        $this->actingAs($this->admin())->post('/data/purge', [
            'target' => 'anggaran', 'mode' => 'year', 'year' => 2026, 'konfirmasi' => 'HAPUS',
        ])->assertRedirect();

        // Regenerasi dijadwalkan untuk SEMUA batch tahun 2026 (anggaran terkunci per tahun).
        Queue::assertPushed(RegenerateReports::class, function (RegenerateReports $job) {
            return count($job->batchIds) === 2;
        });
    }

    public function test_hapus_hasil_laporan_tidak_men_dispatch_regenerasi(): void
    {
        Queue::fake();
        Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);

        $this->actingAs($this->admin())->post('/data/purge', [
            'target' => 'laporan', 'mode' => 'year', 'year' => 2026, 'konfirmasi' => 'HAPUS',
        ])->assertRedirect();

        // Target = laporan itu sendiri → user ingin laporan terhapus, bukan dibangun ulang.
        Queue::assertNotPushed(RegenerateReports::class);
    }

    public function test_hapus_global_per_bulan_tidak_men_dispatch_regenerasi(): void
    {
        Queue::fake();
        Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);

        $this->actingAs($this->admin())->post('/data/purge', [
            'mode' => 'month', 'year' => 2026, 'month' => 5, 'konfirmasi' => 'HAPUS',
        ])->assertRedirect();

        // Hapus global menghapus batch sekaligus → tak ada yang perlu diregenerasi.
        Queue::assertNotPushed(RegenerateReports::class);
    }

    // ── Trigger: IMPOR ───────────────────────────────────────────────────────

    public function test_impor_realisasi_men_dispatch_regenerasi_batch(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tmp = $this->buildGcFile();
        $staged = 'import-staging/job.xlsx';
        Storage::disk('local')->put($staged, file_get_contents($tmp));
        unlink($tmp);

        $importJob = ImportJob::query()->create([
            'type' => 'gc', 'year' => 2026, 'month' => 5,
            'filename' => 'gc.xlsx', 'staged_path' => $staged, 'ext' => 'xlsx',
        ]);

        (new ProcessImport($importJob->id))->handle(app(\App\Domain\Import\SpreadsheetImportService::class));

        $batch = Batch::query()->where('year', 2026)->where('month', 5)->firstOrFail();
        Queue::assertPushed(RegenerateReports::class, fn (RegenerateReports $job) => $job->batchIds === [$batch->id]);
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
        $p = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        (new Xlsx($s))->save($p);

        return $p;
    }
}
