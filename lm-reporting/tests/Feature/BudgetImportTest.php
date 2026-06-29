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
        // RKO & RKAP terpisah: impor rko_* TIDAK mengisi budget_rkap.
        $this->assertSame(0, DB::table('budget_rkap')->count());

        // budget_source (audit) juga menyimpan baris mentah per-sumber, ditandai jenis=rko.
        $this->assertSame(1, DB::table('budget_source')->where('source', 'BKU')->count());
        $this->assertSame(1, DB::table('budget_source')->where('source', 'OHC')->count());
        $this->assertSame(2, DB::table('budget_source')->where('jenis', 'rko')->count());
        $this->assertEqualsWithDelta(3000.0, (float) DB::table('budget_source')->sum('nilai'), 0.5);

        // Re-import BKU hanya mengganti baris BKU, OHC utuh (rko & audit).
        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $bku);
        $this->assertSame(1, DB::table('budget_rko')->where('source', 'OHC')->count());
        $this->assertSame(1, DB::table('budget_source')->where('source', 'OHC')->count());
        $this->assertSame(1, DB::table('budget_source')->where('source', 'BKU')->count());

        unlink($bku);
        unlink($ohc);
    }

    public function test_rko_dan_rkap_disimpan_terpisah_dengan_nilai_berbeda(): void
    {
        RefUnit::query()->create(['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
        ]);

        // Dua file dengan NILAI BERBEDA untuk kode yang sama.
        $rko = $this->buildBudgetFile('41-01', 1000.0);
        $rkap = $this->buildBudgetFile('41-01', 1500.0);

        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $rko);
        app(SpreadsheetImportService::class)->importBudget(2026, 'rkap_bku', $rkap);

        // Tiap tabel menyimpan nilainya sendiri — tidak saling menimpa.
        $this->assertEqualsWithDelta(1000.0, (float) DB::table('budget_rko')->sum('nilai'), 0.5);
        $this->assertEqualsWithDelta(1500.0, (float) DB::table('budget_rkap')->sum('nilai'), 0.5);

        // budget_source memisahkan jenis: satu baris rko (1000) & satu baris rkap (1500).
        $this->assertEqualsWithDelta(1000.0, (float) DB::table('budget_source')->where('jenis', 'rko')->sum('nilai'), 0.5);
        $this->assertEqualsWithDelta(1500.0, (float) DB::table('budget_source')->where('jenis', 'rkap')->sum('nilai'), 0.5);

        // Re-impor RKAP dengan nilai baru tidak menyentuh RKO.
        $rkap2 = $this->buildBudgetFile('41-01', 1800.0);
        app(SpreadsheetImportService::class)->importBudget(2026, 'rkap_bku', $rkap2);
        $this->assertEqualsWithDelta(1000.0, (float) DB::table('budget_rko')->sum('nilai'), 0.5, 'RKO tidak boleh berubah');
        $this->assertEqualsWithDelta(1800.0, (float) DB::table('budget_rkap')->sum('nilai'), 0.5);

        unlink($rko);
        unlink($rkap);
        unlink($rkap2);
    }

    public function test_gc_writes_only_to_budget_source_audit(): void
    {
        RefUnit::query()->create(['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
        ]);

        // Kode AP/AR khas GC tidak punya baris template LM14.
        $gc = $this->buildBudgetFile('AP01', 5000.0);

        $result = app(SpreadsheetImportService::class)->importBudget(2026, 'rko_gc', $gc);

        // GC: rowCount 0 (tidak ada baris budget_rko ditulis).
        $this->assertSame(0, $result->rowCount);
        $this->assertSame(0, DB::table('budget_rko')->count());
        $this->assertSame(0, DB::table('budget_rkap')->count());

        // Hanya budget_source (audit) yang berisi baris GC.
        $this->assertSame(1, DB::table('budget_source')->where('source', 'GC')->count());
        $this->assertEqualsWithDelta(5000.0, (float) DB::table('budget_source')->where('source', 'GC')->sum('nilai'), 0.5);

        unlink($gc);
    }

    public function test_month_guard_imports_only_selected_period_without_touching_others(): void
    {
        RefUnit::query()->create(['code' => '5E11', 'name' => 'Kebun A', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        DB::table('lm_template_row')->insert([
            ['report_type' => 'LM14', 'komoditi' => 'KS', 'kode' => '41-01', 'urutan' => 1, 'uraian' => 'X', 'row_type' => 'detail'],
        ]);

        // File memuat dua period: bulan 5 (1000) dan bulan 6 (2000).
        $file = $this->buildMultiPeriodFile('41-01', [5 => 1000.0, 6 => 2000.0]);

        // Impor bulan 5 → hanya baris period 5 yang masuk.
        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $file, null, null, 5);
        $this->assertSame(1, DB::table('budget_rko')->where('source', 'BKU')->count());
        $this->assertEqualsWithDelta(1000.0, (float) DB::table('budget_rko')->sum('nilai'), 0.5);
        $this->assertSame(5, (int) DB::table('budget_rko')->value('period'));

        // Impor bulan 6 → baris period 6 ditambah, period 5 TETAP UTUH.
        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $file, null, null, 6);
        $this->assertSame(2, DB::table('budget_rko')->where('source', 'BKU')->count());
        $this->assertEqualsWithDelta(3000.0, (float) DB::table('budget_rko')->sum('nilai'), 0.5);
        $this->assertSame(1, DB::table('budget_rko')->where('period', 5)->count(), 'period 5 tidak boleh terhapus');
        $this->assertSame(1, DB::table('budget_rko')->where('period', 6)->count());

        // Re-impor bulan 5 → hanya ganti period 5, period 6 utuh.
        app(SpreadsheetImportService::class)->importBudget(2026, 'rko_bku', $file, null, null, 5);
        $this->assertSame(2, DB::table('budget_rko')->where('source', 'BKU')->count());
        $this->assertSame(1, DB::table('budget_rko')->where('period', 6)->count(), 'period 6 tidak boleh terhapus');

        unlink($file);
    }

    /**
     * File budget multi-period: satu baris per (period => nilai), kode sama.
     *
     * @param  array<int, float>  $perPeriod
     */
    private function buildMultiPeriodFile(string $kode, array $perPeriod): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['Komoditi', 'Plant', 'C', 'Period', 'Kode', 'Obj', 'CE', 'CEdesc', 'Klas', 'Nilai'] as $i => $label) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $label);
        }
        $row = 2;
        foreach ($perPeriod as $period => $nilai) {
            $sheet->setCellValueExplicit('A'.$row, 'KS', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, '5E11', DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$row, $period);
            $sheet->setCellValueExplicit('E'.$row, $kode, DataType::TYPE_STRING);
            $sheet->setCellValue('J'.$row, $nilai);
            $row++;
        }

        $path = tempnam(sys_get_temp_dir(), 'budmp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
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
