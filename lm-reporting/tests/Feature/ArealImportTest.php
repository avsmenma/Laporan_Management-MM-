<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ArealImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_areal_reads_DB_sheet_not_first_sheet(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $path = $this->buildArealFile();

        // total dihitung dari sheet DB (2 baris data), bukan VIEW.
        $this->assertSame(2, app(SpreadsheetImportService::class)->rowCountForType('areal', $path));

        $res = app(SpreadsheetImportService::class)->import('areal', $batch, $path, null);
        $this->assertSame(2, $res->rowCount);
        $this->assertSame(2, DB::table('areal_blok')->where('batch_id', $batch->id)->count());

        $r = ArealBlok::query()->where('divisi', 'AFD07')->where('tahun_tanam', 2012)->first();
        $this->assertNotNull($r);
        $this->assertEqualsWithDelta(7.20, (float) $r->luas_tanam, 0.001);   // kol J
        $this->assertSame(647, $r->total_pokok_produktif);                    // kol N
        $this->assertSame('TM', $r->status_blok_petak);
        $this->assertSame('5E01', $r->plant_code);
        $this->assertSame('KS', $r->komoditi);

        // Re-import idempoten per batch.
        app(SpreadsheetImportService::class)->import('areal', $batch, $path, null);
        $this->assertSame(2, DB::table('areal_blok')->where('batch_id', $batch->id)->count());

        unlink($path);
    }

    /** File 2 sheet: VIEW (umpan jebakan) + DB (data sebenarnya). */
    private function buildArealFile(): string
    {
        $ss = new Spreadsheet;
        $view = $ss->getActiveSheet();
        $view->setTitle('VIEW');
        $view->setCellValue('A1', 'JEBAKAN VIEW'); // bukan data

        $db = $ss->createSheet();
        $db->setTitle('DB');
        $headers = ['Status', 'Status Blok/Petak', 'Plant', 'Divisi', 'Kode Blok/Petak', 'Tanggal Mulai', 'Sampai', 'Project Definition', 'Deskripsi Blok/Petak', 'Luas Tanam (Ha)', 'Tahun Tanam', 'Total Pokok', 'Luas (Ha)', 'Total Pokok Produktif', 'Kondisi Areal', 'Jenis Tanah', 'GIS ID', 'Unit Kerja', 'Komoditi'];
        foreach ($headers as $i => $h) {
            $db->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $h);
        }
        $rows = [
            ['@5B@', 'TM', '5E01', 'AFD07', '511', 42370, 50040, 'SM-5E01', 'AFD07-511', 7.2, 2012, 952, 7, 647, '01', '01', '5E-511', 'KEBUN GUNUNG MELIAU', 'KS'],
            ['@5B@', 'TU', '5E01', 'AFD08', '701', 42370, 50040, 'SM-5E01', 'AFD08-701', 11.77, 2025, 1600, 6.76, 1468, '01', '01', '5E-701', 'KEBUN GUNUNG MELIAU', 'KS'],
        ];
        $r = 2;
        foreach ($rows as $row) {
            foreach ($row as $i => $val) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$r;
                if (in_array($i, [0, 1, 2, 3, 4, 7, 8, 14, 15, 16, 17, 18], true)) {
                    $db->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
                } else {
                    $db->setCellValue($cell, $val);
                }
            }
            $r++;
        }

        $path = tempnam(sys_get_temp_dir(), 'areal').'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }
}
