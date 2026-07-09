<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Domain\Report\Lm16Service;
use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportBudgetPksTest extends TestCase
{
    use RefreshDatabase;

    /** Bangun file anggaran PKS sementara (layout kolom = anggaran OHC). */
    private function makeFile(array $rows): string
    {
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([[
            'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name',
            'Cost Element', 'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
        ]], null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([$row], null, 'A'.$r++);
        }
        $path = tempnam(sys_get_temp_dir(), 'pksbud').'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    public function test_import_rkap_biaya_dan_produksi_pks_mengisi_budget_lm16(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-06', 'year' => 2026, 'month' => 6, 'status' => 'final', 'processed_at' => '2026-06-20 08:00:00']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail();
        $service = app(SpreadsheetImportService::class);

        // Biaya: Premi (pengolahan), Beban Depresiasi (490), dan pasangan ambigu
        // "Biaya Air" pengolahan (603-604.09) vs "Beban Air dan Listrik" overhead (424).
        $biaya = $this->makeFile([
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, '603-604.02', 'Premi', '-', '-', '-', 1000000, 0],
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, '490', 'Beban Depresiasi', '-', '-', '-', 500000, 0],
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, '603-604.09', 'Biaya Air', '-', '-', '-', 111, 0],
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, '424', 'Beban Air dan Listrik', '-', '-', '-', 222, 0],
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, '999', 'Kode Asing', '-', '-', '-', 9, 0],   // error: di luar LM16
            ['KS', '5E01', 'KEBUN', 6, '603-604.02', 'Premi', '-', '-', '-', 9, 0],             // error: non-pabrik
        ]);
        $result = $service->importBudget(2026, 'rkap_pks_biaya', $biaya, null, null, 6);
        $this->assertSame(4, $result->rowCount);
        $this->assertSame(2, $result->errorCount());

        // Produksi: TBS Diolah / CPO / Inti per plant.
        $produksi = $this->makeFile([
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, 'TBS Diolah', 'TBS Diolah', '-', '-', '-', 10000, 0],
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, 'CPO', 'CPO', '-', '-', '-', 2300, 0],
            ['KS', '5F01', 'PKS GUNUNG MELIAU', 6, 'Inti', 'Inti', '-', '-', '-', 400, 0],
        ]);
        $this->assertSame(3, $service->importBudget(2026, 'rkap_pks_produksi', $produksi, null, null, 6)->rowCount);

        // Kode tersimpan unik per baris template ('U{urutan}') / kode produksi kanonik.
        $kode = DB::table('budget_rkap')->where('report_type', 'LM16')->pluck('nilai', 'kode');
        $this->assertEquals(1000000.0, (float) $kode['U18']);  // Premi
        $this->assertEquals(500000.0, (float) $kode['U55']);   // Depresiasi
        $this->assertEquals(111.0, (float) $kode['U25']);      // Biaya Air (pengolahan)
        $this->assertEquals(222.0, (float) $kode['U51']);      // Biaya Penerangan (overhead, kode 424)
        $this->assertEquals(10000.0, (float) $kode['TBS Diolah']);

        // Generate LM16: anggaran masuk ke baris yang tepat (tanpa bocor antar seksi).
        app(Lm16Service::class)->generate($batch, $unit);
        $row = fn (int $urutan) => DB::table('report_lm16')
            ->join('lm_template_row', 'lm_template_row.id', '=', 'report_lm16.template_id')
            ->where('batch_id', $batch->id)->where('unit_id', $unit->id)
            ->where('lm_template_row.urutan', $urutan)->first();

        $this->assertEquals(1000000.0, (float) $row(18)->bi_rkap);   // Premi
        $this->assertEquals(111.0, (float) $row(25)->bi_rkap);       // Biaya Air pengolahan
        $this->assertEquals(222.0, (float) $row(51)->bi_rkap);       // Biaya Penerangan overhead
        $this->assertEquals(0.0, (float) $row(52)->bi_rkap);         // Biaya Air overhead TIDAK kecipratan
        $this->assertEquals(500000.0, (float) $row(55)->bi_rkap);    // Depresiasi
        $this->assertEquals(1000111.0, (float) $row(32)->bi_rkap);   // Subtotal pengolahan = 18+25
        $this->assertEquals(10000.0, (float) $row(4)->bi_rkap);      // TBS di olah ← TBS Diolah
        $this->assertEquals(2300.0, (float) $row(7)->bi_rkap);       // Minyak Sawit ← CPO
        $this->assertEquals(23.0, (float) $row(11)->bi_rkap);        // Rendemen MS = CPO/TBS×100
        $this->assertEquals(0.0, (float) $row(18)->bi_rko);          // RKO tak tersentuh impor RKAP

        @unlink($biaya);
        @unlink($produksi);
    }
}
