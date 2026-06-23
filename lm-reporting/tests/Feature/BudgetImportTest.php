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
