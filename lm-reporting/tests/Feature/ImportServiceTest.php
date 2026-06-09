<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wbs_and_btl_import_replace_batch_rows(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'draft',
        ]);

        $service = app(SpreadsheetImportService::class);
        $file = $this->workbook([
            'DB WBS' => [
                ['Budidaya', 'Plant', 'Period', 'Aktifitas', 'Cost Element', 'Klasifikasi', 'Nilai', 'Fisik'],
                ['KS', '5E01', 5, '41-01', '511001', '2', 100, 1.5],
                ['KS', '5E01', 5, '41-02', '511002', '3', 200, 2.5],
            ],
            'DB BTL' => [
                ['Kode', 'Plant', 'Period', 'Kode CC', 'Cost Element', 'Klasifikasi', 'Nilai'],
                ['KS', '5E01', 5, 'BT01', '511003', '1', 300],
            ],
        ]);

        $this->assertSame(2, $service->import('wbs', $batch, $file)->rowCount);
        $this->assertSame(2, DB::table('db_wbs')->where('batch_id', $batch->id)->count());

        $replacement = $this->workbook([
            'DB WBS' => [
                ['Budidaya', 'Plant', 'Period', 'Aktifitas', 'Cost Element', 'Klasifikasi', 'Nilai', 'Fisik'],
                ['KS', '5E01', 5, '41-99', '511009', '2', 900, 9],
            ],
        ]);

        $this->assertSame(1, $service->import('wbs', $batch, $replacement)->rowCount);
        $this->assertSame(1, DB::table('db_wbs')->where('batch_id', $batch->id)->count());
        $this->assertSame('41-99', DB::table('db_wbs')->where('batch_id', $batch->id)->value('aktivitas'));

        $this->assertSame(1, $service->import('btl', $batch, $file)->rowCount);
        $this->assertSame(1, DB::table('db_btl')->where('batch_id', $batch->id)->count());
    }

    public function test_rkap_and_rko_import_are_upserted_and_multiplied_by_thousand(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'draft',
        ]);

        $service = app(SpreadsheetImportService::class);
        $file = $this->workbook([
            'RKAP' => [
                ['Tahun', 'Komoditi', 'Plant', 'Report Type', 'Kode', 'Nilai'],
                [2026, 'KS', '5E01', 'LM14', '41-01', 123],
            ],
        ]);

        $this->assertSame(1, $service->import('budget_rkap', $batch, $file)->rowCount);
        $this->assertEquals(123000.0, (float) DB::table('budget_rkap')->where('kode', '41-01')->value('nilai'));

        $replacement = $this->workbook([
            'RKO' => [
                ['Tahun', 'Komoditi', 'Plant', 'Report Type', 'Kode', 'Nilai'],
                [2026, 'KS', '5E01', 'LM14', '41-01', 45],
            ],
        ]);

        $this->assertSame(1, $service->import('budget_rko', $batch, $replacement)->rowCount);
        $this->assertEquals(45000.0, (float) DB::table('budget_rko')->where('kode', '41-01')->value('nilai'));
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function workbook(array $sheets): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    $sheet->setCellValueExplicit(
                        [$columnIndex + 1, $rowIndex + 1],
                        $value,
                        is_string($value) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC,
                    );
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'lm-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
