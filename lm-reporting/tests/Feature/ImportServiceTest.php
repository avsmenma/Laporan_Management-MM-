<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_types_now_include_realisasi_and_budget(): void
    {
        $this->assertSame(
            ['wbs', 'ohc', 'gc', 'rko_bku', 'rko_ohc', 'rko_gc', 'rkap_bku', 'rkap_ohc', 'rkap_gc', 'areal', 'produksi', 'produksi_kebun', 'pks_biaya'],
            array_keys(SpreadsheetImportService::types())
        );
        $this->assertFalse(SpreadsheetImportService::isBudget('wbs'));
        $this->assertTrue(SpreadsheetImportService::isBudget('rko_ohc'));
        $this->assertTrue(SpreadsheetImportService::isBudget('rkap_ohc'));
        $this->assertSame('OHC', SpreadsheetImportService::budgetSource('rko_ohc'));
        $this->assertSame('OHC', SpreadsheetImportService::budgetSource('rkap_ohc'));
        $this->assertNull(SpreadsheetImportService::budgetSource('wbs'));
        $this->assertSame('rko', SpreadsheetImportService::budgetKind('rko_bku'));
        $this->assertSame('rkap', SpreadsheetImportService::budgetKind('rkap_gc'));
        $this->assertNull(SpreadsheetImportService::budgetKind('wbs'));
        $this->assertTrue(SpreadsheetImportService::usesMonthGuard('rkap_bku'));
        $this->assertTrue(SpreadsheetImportService::isProduksiKebun('produksi_kebun'));
        $this->assertFalse(SpreadsheetImportService::isProduksiKebun('produksi'));
        $this->assertTrue(SpreadsheetImportService::usesMonthGuard('produksi_kebun'));
        $this->assertTrue(SpreadsheetImportService::usesMonthGuard('produksi'));
        $this->assertTrue(SpreadsheetImportService::usesMonthGuard('rko_bku'));
        $this->assertFalse(SpreadsheetImportService::usesMonthGuard('wbs'));
    }

    public function test_detect_periods_reads_distinct_month_from_gc_file(): void
    {
        $path = $this->buildGcFile(); // baris H2=5, H3=5 → kolom Period (H) = bulan 5
        $this->assertSame([5], app(SpreadsheetImportService::class)->detectPeriods($path, 'gc'));
        unlink($path);
    }

    public function test_gc_raw_import_reads_cached_formula_values_and_derives_plant_code(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final']);
        $path = $this->buildGcFile();

        $result = app(SpreadsheetImportService::class)->import('gc', $batch, $path, null);

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->rowCount);
        $this->assertSame(2, DB::table('db_gc')->where('batch_id', $batch->id)->count());

        $row = DB::table('db_gc')->where('cost_center', '5E01AP0104')->first();
        $this->assertNotNull($row);
        $this->assertSame('5E01', $row->plant_code);     // diturunkan dari Cost Center
        $this->assertEquals(5, $row->period);
        $this->assertEqualsWithDelta(166546.0, (float) $row->value_obj_crcy, 0.5);
        $this->assertSame('1301', $row->kode);
        $this->assertSame('KS', $row->komoditi);          // dibaca dari cache formula =UPPER("ks")

        // Re-import mengganti data batch (idempoten).
        app(SpreadsheetImportService::class)->import('gc', $batch, $path, null);
        $this->assertSame(2, DB::table('db_gc')->where('batch_id', $batch->id)->count());

        unlink($path);
    }

    public function test_preview_returns_columns_sample_and_total_without_inserting(): void
    {
        $path = $this->buildGcFile();

        $preview = app(SpreadsheetImportService::class)->preview('gc', $path, 1);

        $this->assertSame('gc', $preview['type']);
        $this->assertSame(2, $preview['total']);             // 2 baris data (di luar header)
        $this->assertCount(1, $preview['rows']);             // sample dibatasi 1 baris
        $this->assertContains('Cost Center', $preview['columns']);
        $this->assertSame(0, DB::table('db_gc')->count());   // pratinjau tidak menyimpan apa pun

        unlink($path);
    }

    private function buildGcFile(): string
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
        // header di kolom kode (AL) & komoditi (AP) agar lebar kolom terbaca
        $sheet->setCellValue('AL1', 'Kode');
        $sheet->setCellValue('AP1', 'Komoditi');

        // Baris 2
        $sheet->setCellValueExplicit('A2', '5E01AP0104', DataType::TYPE_STRING);
        $sheet->setCellValue('H2', 5);
        $sheet->setCellValue('J2', 166546);
        $sheet->setCellValueExplicit('AL2', '1301', DataType::TYPE_STRING);
        $sheet->setCellValue('AP2', '=UPPER("ks")'); // formula → cache "KS"

        // Baris 3
        $sheet->setCellValueExplicit('A3', '5E11SP0101', DataType::TYPE_STRING);
        $sheet->setCellValue('H3', 5);
        $sheet->setCellValue('J3', 699413);
        $sheet->setCellValueExplicit('AL3', '1101', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('AP3', 'KS', DataType::TYPE_STRING);

        $path = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);

        return $path;
    }
}
