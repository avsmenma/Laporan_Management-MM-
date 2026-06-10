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
                ['KS', '5E01', 5, '41-01', '511001', '2. SPK', 100, 1.5],
                ['KS', '5E01', 5, '41-02', '511002', '3', 200, 2.5],
            ],
            'DB BTL' => [
                ['Kode', 'Plant', 'Period', 'Kode CC', 'Cost Element', 'Klasifikasi', 'Nilai'],
                ['KS', '5E01', 5, 'BT01', '511003', '1. Gaji', 300],
            ],
        ]);

        $this->assertSame(2, $service->import('wbs', $batch, $file)->rowCount);
        $this->assertSame(2, DB::table('db_wbs')->where('batch_id', $batch->id)->count());
        $this->assertSame('2', DB::table('db_wbs')->where('batch_id', $batch->id)->where('aktivitas', '41-01')->value('klasifikasi_code'));

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
        $this->assertSame('1', DB::table('db_btl')->where('batch_id', $batch->id)->value('klasifikasi_code'));
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

    public function test_pks_matrix_workbook_imports_summary_lm625_and_rkap_rows(): void
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
            'Summary' => [
                [],
                [],
                ['Uraian', 'Period', 'Kode A', 'Kode B', 'CO Object Name', 'Cost Element', 'Cost element name', 'Lock', 'Nilai'],
                ['5F01', 5, 'STAS', 'STAS01', 'Loading Ramp', 51100402, 'Bi Gaji', 'a. Gaji', 700],
            ],
            'LM625F01' => $this->lm625Rows(),
            'RKAP' => [
                [null, '5F01'],
                ['Uraian', 'Pagun'],
                ['Gaji, tunjangan & Bisos Kary Pelaksana', 123],
            ],
        ]);

        $this->assertSame(1, $service->import('pks_biaya', $batch, $file)->rowCount);
        $this->assertSame(10, $service->import('pks_produksi', $batch, $file)->rowCount);
        $this->assertSame(1, $service->import('budget_rkap', $batch, $file)->rowCount);

        $this->assertEquals(700.0, (float) DB::table('pks_biaya')->where('plant_code', '5F01')->value('nilai'));
        $this->assertEquals(124.0, (float) DB::table('pks_produksi')->where('plant_code', '5F01')->where('period', 5)->where('uraian', 'Jumlah Produksi TBS')->value('nilai_bi'));
        $this->assertEquals(92.0, (float) DB::table('pks_produksi')->where('plant_code', '5F01')->where('period', 4)->where('uraian', 'Jumlah Produksi TBS')->value('nilai_bi'));
        $this->assertEquals(123000.0, (float) DB::table('budget_rkap')->where('plant_code', '5F01')->where('report_type', 'LM16')->value('nilai'));
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function lm625Rows(): array
    {
        $rows = array_fill(0, 60, []);
        foreach ([
            22 => ['Jumlah Produksi TBS', 124, 498, 92, 373],
            31 => ['Jumlah TBS Diolah', 127, 497, 94, 370],
            40 => ['Jumlah Sisa Buah di Pabrik', 7, 7, 37, 37],
            49 => ['Jlh. Prod. Minyak Sawit', 27, 108, 20, 81],
            58 => ['Jumlah Produksi Inti Sawit', 4, 18, 3, 14],
        ] as $index => [$uraian, $bi, $sd, $prevBi, $prevSd]) {
            $rows[$index][1] = $uraian;
            $rows[$index][3] = $bi;
            $rows[$index][4] = $bi;
            $rows[$index][5] = $sd;
            $rows[$index][18] = $prevBi;
            $rows[$index][19] = $prevBi;
            $rows[$index][20] = $prevSd;
        }

        return $rows;
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function workbook(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
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
