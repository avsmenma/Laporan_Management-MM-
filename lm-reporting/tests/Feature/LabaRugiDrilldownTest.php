<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LabaRugiDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private function actingViewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function glRow(string $type, int $period, float $amount, array $extra = []): array
    {
        return array_merge([
            'report_type' => $type,
            'posting_date' => sprintf('2026-%02d-15', $period),
            'year' => 2026,
            'period' => $period,
            'profit_center' => '5R00000001',
            'profit_center_desc' => 'Kantor Regional',
            'cost_center' => '5R00ADM05',
            'amount' => $amount,
            'class_code' => null,
            'class_desc' => null,
        ], $extra);
    }

    private function pjlRow(int $period, float $qty, float $amount, array $extra = []): array
    {
        return array_merge([
            'document_number' => 'DOC-1',
            'posting_date' => sprintf('2026-%02d-10', $period),
            'year' => 2026,
            'period' => $period,
            'account' => '41100000',
            'gl_account_desc' => 'Penjualan CPO',
            'profit_center' => '5F01000001',
            'profit_center_desc' => 'PKS Satu',
            'material_code' => 'M1',
            'material_desc' => 'CPO',
            'qty' => $qty,
            'uom' => 'KG',
            'amount' => $amount,
            'customer_code' => 'C1',
            'customer_name' => 'PT Pembeli',
            'document_type' => 'RV',
            'reference' => 'REF',
        ], $extra);
    }

    public function test_admin_pivot_dan_deep(): void
    {
        DB::table('beban_usaha_gl')->insert([
            $this->glRow('ADMIN', 5, 1000, ['class_desc' => 'Beban Gaji, Tunjangan & Beban Sosial Karyawan']),
            $this->glRow('ADMIN', 5, 100, ['class_desc' => 'Beban Gaji, Tunjangan & Beban Sosial Karyawan', 'cost_center' => '5E11AU18KR', 'profit_center' => '5E11000001', 'profit_center_desc' => 'Kebun Sebelas']),
            $this->glRow('ADMIN', 6, 500, ['class_desc' => 'Beban Gaji, Tunjangan & Beban Sosial Karyawan']),
            $this->glRow('ADMIN', 6, 50, ['class_desc' => 'Klasifikasi Di Luar Peta']),
        ]);
        $viewer = $this->actingViewer();

        // Tahap 1: baris 0 (Gaji), kolom sd Bulan Juni → pivot Profit Center × Cost Center × bulan.
        $resp = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=admin&tab=summary&row=0&field=sd&year=2026&month=6');
        $resp->assertOk();
        $p = $resp->json('pivot');
        $this->assertEquals(1600, $p['grand_total']);
        $this->assertSame(3, $p['row_count']);
        $this->assertSame([5, 6], $p['cat_keys']);
        $this->assertSame(['Mei', 'Jun'], $p['categories']);
        // Grup terurut kunci: 5E11... lalu 5R00...
        $this->assertSame('5E11000001 — Kebun Sebelas', $p['groups'][0]['label']);
        $g5r = $p['groups'][1];
        $this->assertSame('5R00000001', $g5r['g']);
        $this->assertSame('5R00ADM05', $g5r['rows'][0]['r']);
        $this->assertEquals(1000, $g5r['rows'][0]['values']['5']);
        $this->assertEquals(500, $g5r['rows'][0]['values']['6']);
        $this->assertEquals(1500, $g5r['rows'][0]['total']);

        // Klasifikasi asing masuk baris Lain-Lain (indeks 28).
        $lain = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=admin&tab=summary&row=28&field=bln&year=2026&month=6');
        $this->assertEquals(50, $lain->json('pivot.grand_total'));

        // Tahap 2: sel pivot (5R00.., 5R00ADM05, Mei) → 1 baris mentah.
        $deep = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown-deep?page=admin&tab=summary&row=0&field=sd&year=2026&month=6&g=5R00000001&r=5R00ADM05&c=5');
        $deep->assertOk();
        $this->assertSame(1, $deep->json('detail.row_count'));
        $this->assertEquals(1000, $deep->json('detail.sections.0.subtotal'));
        $this->assertEquals(1000, (float) $deep->json('detail.sections.0.rows.0.amount'));

        // Tahap 2 tanpa kategori (total baris) → seluruh bulan cakupan sd.
        $deepAll = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown-deep?page=admin&tab=summary&row=0&field=sd&year=2026&month=6&g=5R00000001&r=5R00ADM05');
        $this->assertSame(2, $deepAll->json('detail.row_count'));
        $this->assertEquals(1500, $deepAll->json('detail.sections.0.subtotal'));
    }

    public function test_bol_pivot_split_karet_dan_kso(): void
    {
        DB::table('beban_usaha_gl')->insert([
            $this->glRow('BOL', 5, 800, ['class_code' => 'A123', 'profit_center' => '5F14000001']),
            $this->glRow('BOL', 5, 60, ['class_code' => 'A123', 'profit_center' => '5F20000001']),  // PKR → karet
            $this->glRow('BOL', 5, 300, ['class_code' => 'A119', 'profit_center' => '5E12000001']), // KSO Kumai → karet
            $this->glRow('BOL', 5, 500, ['class_code' => 'A154', 'profit_center' => '5R00000001']),
        ]);
        $viewer = $this->actingViewer();

        // Baris Total (39), tab KARET = A119@5E12 + A123@5F20.
        $kr = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=bol&tab=kr&row=39&field=sd&year=2026&month=6');
        $kr->assertOk();
        $this->assertEquals(360, $kr->json('pivot.grand_total'));

        // Tab KELAPA SAWIT = sisanya.
        $ks = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=bol&tab=ks&row=39&field=sd&year=2026&month=6');
        $this->assertEquals(1300, $ks->json('pivot.grand_total'));

        // Baris KSO Kumai (indeks 32) = A119@5E12.
        $kso = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=bol&tab=summary&row=32&field=sd&year=2026&month=6');
        $this->assertEquals(300, $kso->json('pivot.grand_total'));

        // Baris Jumlah rincian (26) = semua kecuali baris KSO.
        $jml = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=bol&tab=summary&row=26&field=sd&year=2026&month=6');
        $this->assertEquals(1360, $jml->json('pivot.grand_total'));
    }

    public function test_penjualan_pivot_dan_deep(): void
    {
        DB::table('penjualan_produk')->insert([
            $this->pjlRow(6, -10, -1000),
            $this->pjlRow(6, -5, -500, ['profit_center' => '5F02000001', 'profit_center_desc' => 'PKS Dua']),
            $this->pjlRow(6, -3, -300, ['customer_code' => 'C2', 'customer_name' => 'PT Lain']),
            $this->pjlRow(5, -2, -200),
            $this->pjlRow(6, -1, -100, ['material_desc' => 'Lump', 'customer_code' => 'C9']),
        ]);
        $viewer = $this->actingViewer();

        // Tab BUYER, baris CPO×C1, blok BULAN INI → pivot per plant.
        $resp = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=penjualan&tab=buyer&mat=CPO&code=C1&rowType=detail&blok=bi&measure=nilai&year=2026&month=6');
        $resp->assertOk();
        $p = $resp->json('pivot');
        $this->assertEquals(-1500, $p['grand_total']);
        $this->assertSame('CPO', $p['groups'][0]['g']);
        $this->assertCount(2, $p['groups'][0]['rows']); // 5F01 & 5F02
        $this->assertSame('5F01000001 — PKS Satu', $p['groups'][0]['rows'][0]['label']);

        // Blok SD BULAN INI → kategori dua bulan (Mei & Jun).
        $sd = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=penjualan&tab=buyer&mat=CPO&code=C1&rowType=detail&blok=sd&measure=nilai&year=2026&month=6');
        $this->assertEquals(-1700, $sd->json('pivot.grand_total'));
        $this->assertSame([5, 6], $sd->json('pivot.cat_keys'));

        // Measure QTY → nilai pivot dari kolom qty.
        $qty = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=penjualan&tab=buyer&mat=CPO&code=C1&rowType=detail&blok=bi&measure=qty&year=2026&month=6');
        $this->assertEquals(-15, $qty->json('pivot.grand_total'));

        // Baris Total → seluruh produk.
        $tot = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown?page=penjualan&tab=buyer&rowType=total&blok=bi&measure=nilai&year=2026&month=6');
        $this->assertEquals(-1900, $tot->json('pivot.grand_total'));

        // Tahap 2: sel pivot (CPO, 5F01) → baris mentah + subtotal nilai & qty.
        $deep = $this->actingAs($viewer)->getJson(
            '/report-data/laba-rugi/drilldown-deep?page=penjualan&tab=buyer&mat=CPO&code=C1&rowType=detail&blok=bi&measure=nilai&year=2026&month=6&g=CPO&r=5F01000001');
        $deep->assertOk();
        $this->assertSame(1, $deep->json('detail.row_count'));
        $this->assertEquals(-1000, $deep->json('detail.sections.0.subtotal'));
        $this->assertEquals(-10, $deep->json('detail.sections.0.qty_subtotal'));
        $this->assertSame('PT Pembeli', $deep->json('detail.sections.0.rows.0.customer_name'));
    }

    public function test_butuh_login(): void
    {
        $this->getJson('/report-data/laba-rugi/drilldown?page=admin&tab=summary&row=0&field=bln&year=2026&month=6')
            ->assertStatus(401);
    }
}
