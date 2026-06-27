<?php

namespace Tests\Feature;

use App\Domain\Import\ImportTemplateService;
use App\Domain\Import\SpreadsheetImportService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function actingOperator(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Operator']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_setiap_jenis_import_punya_template(): void
    {
        $types = array_keys(SpreadsheetImportService::types());
        foreach ($types as $type) {
            $this->assertTrue(ImportTemplateService::hasTemplate($type), "Template hilang untuk: {$type}");
        }
    }

    public function test_nama_sheet_dan_header_sesuai_importer(): void
    {
        $svc = new ImportTemplateService;

        $areal = $svc->build('areal')->getActiveSheet();
        $this->assertSame('DB', $areal->getTitle());
        $this->assertSame('Status', $areal->getCell('A1')->getValue());

        $pks = $svc->build('produksi')->getActiveSheet();
        $this->assertSame('ZPTPNHLPP039', $pks->getTitle());

        $kebun = $svc->build('produksi_kebun')->getActiveSheet();
        $this->assertSame('ZESTHLE020', $kebun->getTitle());
        $this->assertSame('Posting Date', $kebun->getCell('M1')->getValue()); // idx 12
        $this->assertSame('Weight netto', $kebun->getCell('W1')->getValue()); // idx 22
    }

    public function test_route_template_mengembalikan_xlsx(): void
    {
        $user = $this->actingOperator();

        $resp = $this->actingAs($user)->get('/import/template/produksi_kebun');
        $resp->assertOk();
        $resp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_route_template_jenis_tak_dikenal_404(): void
    {
        $user = $this->actingOperator();
        $this->actingAs($user)->get('/import/template/ngawur')->assertNotFound();
    }

    public function test_template_produksi_kebun_bisa_diimpor_kembali(): void
    {
        // Round-trip: isi template dengan 1 baris lalu impor → tersimpan benar.
        $svc = new ImportTemplateService;
        $ss = $svc->build('produksi_kebun');
        $sheet = $ss->getActiveSheet();
        // Baris data: Plant(A)=5F01, Goods Recipient(C)=5E01, Afdeling(E)=AFD01,
        // Posting Date(M)=2026-05-10, Weight netto(W)=1234. Kode ditulis sebagai TEXT
        // (seperti ekspor SAP asli) agar "5E01" tidak diubah jadi notasi ilmiah.
        $str = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
        $sheet->setCellValueExplicit('A2', '5F01', $str);
        $sheet->setCellValueExplicit('C2', '5E01', $str);
        $sheet->setCellValue('D2', 'KEBUN SATU');
        $sheet->setCellValueExplicit('E2', 'AFD01', $str);
        $sheet->setCellValueExplicit('M2', '2026-05-10', $str);
        $sheet->setCellValue('W2', 1234);

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new XlsxWriter($ss))->save($path);

        $result = app(SpreadsheetImportService::class)->importProduksiKebun($path, null, null, 2026, 5);
        @unlink($path);

        $this->assertSame(1, $result->rowCount);
        $row = \Illuminate\Support\Facades\DB::table('produksi_kebun_wb')->first();
        $this->assertSame('Kebun Sendiri', $row->supply);
        $this->assertSame('5E01', $row->goods_recipient);
        $this->assertEquals(1234, (float) $row->weight_netto);
    }
}
