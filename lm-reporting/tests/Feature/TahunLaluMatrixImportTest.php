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

class TahunLaluMatrixImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_wide_tahun_lalu_matrix_with_cached_iferror_values(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'final',
        ]);

        $path = $this->buildMatrixWorkbook();

        $result = app(SpreadsheetImportService::class)->import('realisasi_tahun_lalu', $batch, $path);

        $this->assertSame([], $result->errors);
        $this->assertGreaterThan(0, $result->rowCount);

        // Nilai cache di balik =IFERROR(__xludf.DUMMYFUNCTION(...), 167644715) terbaca dengan benar
        // dan tersimpan pada period = bulan batch (Mei = 5), tahun 2025, komoditi KS.
        $row = DB::table('realisasi_tahun_lalu')
            ->where('year', 2025)->where('komoditi', 'KS')->where('plant_code', '5E11')
            ->where('report_type', 'LM14')->where('kode', '99-01')->where('period', 5)
            ->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(167644715.0, (float) $row->nilai, 0.5);

        // Sel angka biasa (bukan formula) juga terbaca.
        $plain = DB::table('realisasi_tahun_lalu')
            ->where('plant_code', '5E01')->where('kode', '41-01')->where('period', 5)
            ->value('nilai');
        $this->assertEqualsWithDelta(2000.0, (float) $plain, 0.5);

        // Baris non-kode (label seksi / "Jumlah") tidak ikut tersimpan.
        $this->assertSame(0, DB::table('realisasi_tahun_lalu')->where('kode', 'Jumlah Gaji')->count());

        unlink($path);
    }

    private function buildMatrixWorkbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tahun Lalu');

        $sheet->setCellValue('B1', 'PT PERKEBUNAN NUSANTARA IV - RPC 5');
        $sheet->setCellValue('B2', 'TAHUN 2025');
        $sheet->setCellValue('B3', 'Bulan');
        $sheet->setCellValue('D3', 'Mei');
        $sheet->setCellValue('B4', 'Kode Komodity');
        $sheet->setCellValue('D4', 'KS');

        // Baris header: kode unit kebun tersebar sebagai kolom.
        // Ditulis eksplisit sebagai teks agar "5E01" dst tidak ditafsir sebagai notasi ilmiah.
        $sheet->setCellValue('B5', 'WBS');
        $sheet->setCellValue('D5', 'DESKRIPSI WBS');
        $sheet->setCellValueExplicit('E5', '5E01', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F5', '5E02', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('G5', '5E11', DataType::TYPE_STRING);
        $sheet->setCellValue('H5', 'Total');

        // Baris data 99-01: kolom 5E11 berupa formula ekspor Google Sheets dengan nilai cache.
        $sheet->setCellValue('B6', '99-01');
        $sheet->setCellValue('D6', 'Gaji/Upah dan Biaya Kary. Staf dari WBS');
        $sheet->setCellValueExplicit(
            'G6',
            '=IFERROR(__xludf.DUMMYFUNCTION("""COMPUTED_VALUE"""),167644715)',
            DataType::TYPE_FORMULA,
        );

        // Baris "Jumlah" tidak boleh ikut (bukan kode).
        $sheet->setCellValue('B7', 'Jumlah Gaji');
        $sheet->setCellValue('G7', 1);

        // Baris data 41-01 dengan angka biasa.
        $sheet->setCellValue('B8', '41-01');
        $sheet->setCellValue('D8', 'TM - PEMEL JALAN MANUAL - ACCESS ROAD');
        $sheet->setCellValue('E8', 2000);

        $path = tempnam(sys_get_temp_dir(), 'tl_matrix').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        return $path;
    }
}
