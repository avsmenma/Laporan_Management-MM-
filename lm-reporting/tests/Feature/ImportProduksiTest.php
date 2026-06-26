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

        // Baris non-kebun 5F* (5F08) & PLS dilewati saat import → 62 − 5 = 57.
        $r1 = $service->importProduksi($path);
        $this->assertSame(57, $r1->rowCount);
        $this->assertSame(57, DB::table('produksi_pks')->count());

        // posting_date dari serial 46173 = 2026-05-31
        $this->assertSame(57, DB::table('produksi_pks')->whereDate('posting_date', '2026-05-31')->count());

        // Baris 5F* dan PLS tidak ikut masuk.
        $this->assertSame(0, DB::table('produksi_pks')->where('kebun_code', 'like', '5F%')->count());
        $this->assertSame(0, DB::table('produksi_pks')->where('kebun_code', 'PLS')->count());
        // PLSM & PHTG tetap ada (tidak ikut dihapus).
        $this->assertGreaterThan(0, DB::table('produksi_pks')->where('kebun_code', 'PLSM')->count());
        $this->assertGreaterThan(0, DB::table('produksi_pks')->where('kebun_code', 'PHTG')->count());

        // Sampel nilai: 5F01 / 5E01 / Kebun Sendiri
        $row = DB::table('produksi_pks')->where('plant_code', '5F01')->where('kebun_code', '5E01')->first();
        $this->assertEquals(3795250.0, (float) $row->tbs_diterima_sdhari);
        $this->assertEquals(19506780.0, (float) $row->tbs_diterima_sdbulan);
        $this->assertEquals(3987400.0, (float) $row->tbs_diolah_sdhari);

        // Idempoten: impor ulang tanggal yang sama tidak menggandakan.
        $service->importProduksi($path);
        $this->assertSame(57, DB::table('produksi_pks')->count());
    }

    public function test_penjaga_bulan_hanya_impor_tanggal_yang_cocok(): void
    {
        $path = $this->contohPath();
        if (! is_file($path)) {
            $this->markTestSkipped("File contoh produksi tidak tersedia di: {$path}");
        }

        $service = app(SpreadsheetImportService::class);

        // File contoh seluruhnya tanggal 2026-05-31.
        // Pilih bulan 6 → tidak ada baris yang cocok.
        $r0 = $service->importProduksi($path, null, null, 2026, 6);
        $this->assertSame(0, $r0->rowCount, 'Bulan 6 tidak punya tanggal yang cocok');
        $this->assertSame(0, DB::table('produksi_pks')->count());

        // Pilih bulan 5 → semua 57 baris kebun masuk.
        $r1 = $service->importProduksi($path, null, null, 2026, 5);
        $this->assertSame(57, $r1->rowCount, 'Bulan 5 cocok dengan seluruh baris');
        $this->assertSame(57, DB::table('produksi_pks')->count());
    }

    public function test_filter_baris_dikecualikan(): void
    {
        $this->assertTrue(SpreadsheetImportService::isExcludedProduksiKebun('5F08'));
        $this->assertTrue(SpreadsheetImportService::isExcludedProduksiKebun('5f08'));
        $this->assertTrue(SpreadsheetImportService::isExcludedProduksiKebun('PLS'));
        // Tidak dikecualikan: kebun 5E*, PLSM, PHTG, kosong.
        $this->assertFalse(SpreadsheetImportService::isExcludedProduksiKebun('5E01'));
        $this->assertFalse(SpreadsheetImportService::isExcludedProduksiKebun('PLSM'));
        $this->assertFalse(SpreadsheetImportService::isExcludedProduksiKebun('PHTG'));
        $this->assertFalse(SpreadsheetImportService::isExcludedProduksiKebun(null));
    }
}
