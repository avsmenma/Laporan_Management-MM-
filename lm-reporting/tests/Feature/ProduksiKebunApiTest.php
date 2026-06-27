<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProduksiKebunApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedWb(): void
    {
        $sendiri = fn (string $kebun, string $unit, string $afd, float $w) => [
            'posting_date' => '2026-05-31', 'plant_code' => '5F01', 'plant_desc' => 'PABRIK A',
            'goods_recipient' => $kebun, 'desc_plant_kebun' => $unit, 'afdeling' => $afd,
            'supplier_code' => null, 'supplier_name' => null, 'weight_netto' => $w,
            'supply' => 'Kebun Sendiri', 'kategori_pembelian' => null, 'short_plant' => null,
        ];
        $beli = fn (string $kat, string $code, string $name, string $sp, float $w) => [
            'posting_date' => '2026-05-31', 'plant_code' => '5F01', 'plant_desc' => 'PABRIK A',
            'goods_recipient' => null, 'desc_plant_kebun' => null, 'afdeling' => null,
            'supplier_code' => $code, 'supplier_name' => $name, 'weight_netto' => $w,
            'supply' => 'Pembelian', 'kategori_pembelian' => $kat, 'short_plant' => $sp,
        ];

        DB::table('produksi_kebun_wb')->insert([
            // Kebun Sendiri: 5E01 (AFD01=100, AFD02=200), 5E02 (AFD01=50)
            $sendiri('5E01', 'KEBUN SATU', 'AFD01', 100),
            $sendiri('5E01', 'KEBUN SATU', 'AFD02', 200),
            $sendiri('5E02', 'KEBUN DUA', 'AFD01', 50),
            // Pembelian: Pihak 3 (sup 23xx) + Plasma (sup 25xx)
            $beli('Kebun Pihak 3', '23000001', 'PK Alpha', 'Pagun', 1000),
            $beli('Kebun Pihak 3', '23000002', 'PK Beta', 'Pakem', 500),
            $beli('Kebun Plasma', '25000001', 'KUD Gamma', 'Pagun', 300),
        ]);
    }

    private function actingViewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_butuh_auth(): void
    {
        $this->seedWb();
        $this->getJson('/report-data/produksi/kebun')->assertStatus(401);
    }

    public function test_pivot_kebun_sendiri_dan_pembelian(): void
    {
        $this->seedWb();
        $user = $this->actingViewer();

        $data = $this->actingAs($user)->getJson('/report-data/produksi/kebun?year=2026&month=5')->assertOk()->json();

        $this->assertSame([['year' => 2026, 'month' => 5]], $data['periods']);
        $this->assertSame(['AFD01', 'AFD02'], $data['afdeling']);
        $this->assertSame(['Pagun', 'Pakem'], $data['short_plant']);

        // --- Kebun Sendiri ---
        $ks = $data['kebun_sendiri'];
        $this->assertSame(['5E01', '5E02'], array_column($ks['rows'], 'goods_recipient'));
        $row01 = $ks['rows'][0];
        $this->assertSame('KEBUN SATU', $row01['unit_kerja']);
        $this->assertEquals(100, $row01['afd']['AFD01']);
        $this->assertEquals(200, $row01['afd']['AFD02']);
        $this->assertEquals(300, $row01['grand_total']);
        // Grand total per afdeling & keseluruhan
        $this->assertEquals(150, $ks['grand']['afd']['AFD01']); // 100+50
        $this->assertEquals(200, $ks['grand']['afd']['AFD02']);
        $this->assertEquals(350, $ks['grand']['grand_total']);

        // --- Pembelian ---
        $pb = $data['pembelian'];
        $this->assertSame(['Kebun Pihak 3', 'Kebun Plasma'], array_column($pb['groups'], 'kategori'));
        $g0 = $pb['groups'][0];
        $this->assertSame(['23000001', '23000002'], array_column($g0['rows'], 'supplier_code'));
        $this->assertEquals(1000, $g0['rows'][0]['sp']['Pagun']);
        $this->assertEquals(1500, $g0['subtotal']['grand_total']); // 1000+500
        $this->assertEquals(1000, $g0['subtotal']['sp']['Pagun']);
        $this->assertEquals(500, $g0['subtotal']['sp']['Pakem']);
        // Grand total pembelian = 1000+500+300 = 1800; Pagun = 1000+300 = 1300
        $this->assertEquals(1800, $pb['grand']['grand_total']);
        $this->assertEquals(1300, $pb['grand']['sp']['Pagun']);
        $this->assertEquals(500, $pb['grand']['sp']['Pakem']);
    }

    public function test_periode_kosong_fallback_terbaru(): void
    {
        $this->seedWb();
        $user = $this->actingViewer();

        $data = $this->actingAs($user)->getJson('/report-data/produksi/kebun?year=2026&month=1')->assertOk()->json();
        $this->assertSame(2026, $data['year']);
        $this->assertSame(5, $data['month']);
    }
}
