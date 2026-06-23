<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportProgressCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_invokes_progress_callback_and_counts_rows(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $path = $this->buildGcFile(); // 2 baris data

        $this->assertSame(2, app(SpreadsheetImportService::class)->dataRowCount($path));

        $seen = [];
        app(SpreadsheetImportService::class)->import('gc', $batch, $path, null, function (int $n) use (&$seen) {
            $seen[] = $n;
        });

        $this->assertNotEmpty($seen, 'callback progres terpanggil');
        $this->assertSame(2, end($seen), 'progres akhir = jumlah baris');

        unlink($path);
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
        $sh->setCellValue('J2', 100);
        $sh->setCellValueExplicit('AP2', 'KS', DataType::TYPE_STRING);
        $sh->setCellValueExplicit('A3', '5E11SP0101', DataType::TYPE_STRING);
        $sh->setCellValue('H3', 5);
        $sh->setCellValue('J3', 200);
        $sh->setCellValueExplicit('AP3', 'KS', DataType::TYPE_STRING);
        $p = tempnam(sys_get_temp_dir(), 'gc').'.xlsx';
        (new Xlsx($s))->save($p);

        return $p;
    }
}
