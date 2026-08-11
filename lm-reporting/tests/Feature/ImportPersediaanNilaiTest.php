<?php

namespace Tests\Feature;

use App\Domain\Import\ImportTemplateService;
use App\Domain\Import\SpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportPersediaanNilaiTest extends TestCase
{
    use RefreshDatabase;

    private function contohPath(): string
    {
        // Berkas contoh ada di docs induk (Project_LM/docs), di luar folder app.
        return base_path('../docs/laba_rugi/persediaan/1 ZSTOCK.xlsx');
    }

    public function test_import_zstock_mengisi_persediaan_nilai(): void
    {
        $path = $this->contohPath();
        if (! is_file($path)) {
            $this->markTestSkipped("Berkas contoh ZSTOCK tidak tersedia di: {$path}");
        }

        $service = app(SpreadsheetImportService::class);
        $r = $service->importPersediaanNilai($path, null, null, 2026, 7);

        // 39 baris data (3 produk sawit × 11 unit + LUMP × 6 unit karet).
        $this->assertSame(39, $r->rowCount);
        $this->assertSame(39, DB::table('persediaan_nilai')->where('year', 2026)->where('period', 7)->count());

        $meliau = DB::table('persediaan_nilai')
            ->where(['year' => 2026, 'period' => 7, 'plant_code' => '5F01', 'product' => '- Minyak Sawit'])->first();
        $this->assertEquals(29304045053.0, (float) $meliau->nilai_rp);
        $this->assertSame('PKS Gunung Meliau', $meliau->unit_name);

        // Satu plant bisa punya 2 unit kerja (5R00) — keduanya tersimpan terpisah.
        $this->assertSame(2, DB::table('persediaan_nilai')
            ->where(['year' => 2026, 'period' => 7, 'plant_code' => '5R00', 'product' => '- Minyak Sawit'])->count());
        $this->assertEquals(10256545883.0, (float) DB::table('persediaan_nilai')
            ->where(['year' => 2026, 'period' => 7, 'plant_code' => '5R00', 'unit_name' => 'Tanah Merah'])
            ->where('product', '- Minyak Sawit')->value('nilai_rp'));

        // Karet ikut terbaca (label tanpa awalan '-').
        $this->assertEquals(2219429030.0, (float) DB::table('persediaan_nilai')
            ->where(['year' => 2026, 'period' => 7, 'plant_code' => '5E06', 'product' => 'LUMP'])->value('nilai_rp'));

        // Idempoten: impor ulang periode sama tidak menggandakan.
        $service->importPersediaanNilai($path, null, null, 2026, 7);
        $this->assertSame(39, DB::table('persediaan_nilai')->count());

        // Periode berbeda berdiri sendiri.
        $service->importPersediaanNilai($path, null, null, 2026, 6);
        $this->assertSame(78, DB::table('persediaan_nilai')->count());
    }

    public function test_pratinjau_membaca_sheet_data(): void
    {
        $path = $this->contohPath();
        if (! is_file($path)) {
            $this->markTestSkipped('Berkas contoh ZSTOCK tidak tersedia.');
        }

        $p = app(SpreadsheetImportService::class)->preview('persediaan_nilai', $path, 3);

        $this->assertSame(39, $p['total']);
        $this->assertSame(['Plant', 'Plant Description', 'Material Description', 'Value on Period End'], $p['columns']);
        $this->assertCount(3, $p['rows']);
        $this->assertSame('5R00', (string) $p['rows'][0][0]);
    }

    public function test_jenis_terdaftar_dan_punya_template(): void
    {
        $this->assertArrayHasKey('persediaan_nilai', SpreadsheetImportService::types());
        $this->assertTrue(SpreadsheetImportService::isPersediaanNilai('persediaan_nilai'));
        // Bulan wajib: berkas tidak punya kolom periode.
        $this->assertTrue(SpreadsheetImportService::usesMonthGuard('persediaan_nilai'));
        $this->assertTrue(ImportTemplateService::hasTemplate('persediaan_nilai'));
        $this->assertSame('Data', ImportTemplateService::specs()['persediaan_nilai']['sheet']);
    }

    public function test_tahun_bulan_wajib(): void
    {
        $path = $this->contohPath();
        if (! is_file($path)) {
            $this->markTestSkipped('Berkas contoh ZSTOCK tidak tersedia.');
        }

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SpreadsheetImportService::class)->importPersediaanNilai($path, null, null, 2026, null);
    }
}
