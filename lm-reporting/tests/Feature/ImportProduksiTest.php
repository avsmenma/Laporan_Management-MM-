<?php

namespace Tests\Feature;

use App\Domain\Import\SpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportProduksiTest extends TestCase
{
    use RefreshDatabase;

    private function contohPath(): string
    {
        // File contoh berada di parent docs (Project_LM/docs/produksi), di luar repo app.
        return base_path('../docs/produksi/CONTOH_PRODUKSI_PKS.xlsx');
    }

    public function test_import_produksi_membaca_sheet_dan_idempoten(): void
    {
        $path = $this->contohPath();
        if (! is_file($path)) {
            $this->markTestSkipped("File contoh produksi tidak tersedia di: {$path}");
        }

        $service = app(SpreadsheetImportService::class);

        $r1 = $service->importProduksi($path);
        $this->assertSame(62, $r1->rowCount);
        $this->assertSame(62, DB::table('produksi_pks')->count());

        // posting_date dari serial 46173 = 2026-05-31
        $this->assertSame(62, DB::table('produksi_pks')->whereDate('posting_date', '2026-05-31')->count());

        // Sampel nilai: 5F01 / 5E01 / Kebun Sendiri
        $row = DB::table('produksi_pks')->where('plant_code', '5F01')->where('kebun_code', '5E01')->first();
        $this->assertEquals(3795250.0, (float) $row->tbs_diterima_sdhari);
        $this->assertEquals(19506780.0, (float) $row->tbs_diterima_sdbulan);
        $this->assertEquals(3987400.0, (float) $row->tbs_diolah_sdhari);

        // Idempoten: impor ulang tanggal yang sama tidak menggandakan.
        $service->importProduksi($path);
        $this->assertSame(62, DB::table('produksi_pks')->count());
    }
}
