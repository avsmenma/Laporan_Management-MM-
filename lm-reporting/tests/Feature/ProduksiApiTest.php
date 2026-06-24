<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProduksiApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedProduksi(): void
    {
        // Dua kebun (5E01, 5E02) di dua plant (5F01, 5F04), satu tanggal.
        $base = ['posting_date' => '2026-05-31', 'tidak_mengolah' => false];
        DB::table('produksi_pks')->insert([
            $base + ['plant_code' => '5F01', 'plant_desc' => 'PKS A', 'group_pemilik' => 'Kebun Sendiri', 'kebun_code' => '5E01', 'nama_kebun' => 'KEBUN A',
                'tbs_diterima_sdhari' => 100, 'tbs_diterima_sdbulan' => 1000, 'tbs_diolah_sdhari' => 80, 'tbs_diolah_sdbulan' => 800,
                'sisa_akhir' => 5, 'ms_sdhari' => 16, 'ms_sdbulan' => 160, 'is_sdhari' => 4, 'is_sdbulan' => 40],
            $base + ['plant_code' => '5F04', 'plant_desc' => 'PKS B', 'group_pemilik' => 'Kebun Sendiri', 'kebun_code' => '5E02', 'nama_kebun' => 'KEBUN B',
                'tbs_diterima_sdhari' => 50, 'tbs_diterima_sdbulan' => 500, 'tbs_diolah_sdhari' => 40, 'tbs_diolah_sdbulan' => 400,
                'sisa_akhir' => 2, 'ms_sdhari' => 8, 'ms_sdbulan' => 80, 'is_sdhari' => 2, 'is_sdbulan' => 20],
        ]);
    }

    private function actingViewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_butuh_auth(): void
    {
        $this->seedProduksi();
        $this->getJson('/report-data/produksi')->assertStatus(401);
    }

    public function test_pivot_nilai_dan_grand_total(): void
    {
        $this->seedProduksi();
        $user = $this->actingViewer();

        $resp = $this->actingAs($user)->getJson('/report-data/produksi?date=2026-05-31');
        $resp->assertOk();
        $data = $resp->json();

        $this->assertSame(['2026-05-31'], $data['dates']);
        $this->assertSame(['5F01', '5F04'], array_column($data['plants'], 'code'));
        $this->assertSame(['5E01', '5E02'], array_column($data['kebun'], 'code'));

        // TBS Diterima: 5E01/5F01 Bulan Ini=100, S.D=1000; Grand bulan ini=150, sd=1500
        $td = $data['tables']['tbs_diterima'];
        $row01 = collect($td['rows'])->firstWhere('kebun', '5E01');
        $this->assertEquals(100, $row01['bi']['5F01']);
        $this->assertEquals(1000, $row01['sd']['5F01']);
        $this->assertEquals(100, $row01['bi']['grand']);   // hanya 1 plant terisi
        $this->assertEquals(150, $td['grand']['bi']['grand']);
        $this->assertEquals(1500, $td['grand']['sd']['grand']);

        // Restan Awal turunan (bi) 5E01/5F01 = diolah(80) + akhir(5) - diterima(100) = -15
        $ra = collect($data['tables']['restan_awal']['rows'])->firstWhere('kebun', '5E01');
        $this->assertEquals(-15, $ra['bi']['5F01']);

        // Restan Akhir: sisa_akhir dipakai apa adanya untuk KEDUA blok (bi == sd).
        $rk = collect($data['tables']['restan_akhir']['rows'])->firstWhere('kebun', '5E01');
        $this->assertEquals(5, $rk['bi']['5F01']);
        $this->assertEquals($rk['bi']['5F01'], $rk['sd']['5F01']);

        // Ringkasan bi 5F01: olah=80, ms=16 → rend_ms=20.00
        $this->assertEquals(20.0, round($data['ringkasan']['bi']['5F01']['rend_ms'], 2));
        // Ringkasan bi JLH olah=120, ms=24 → rend_ms=20.00
        $this->assertEquals(20.0, round($data['ringkasan']['bi']['JLH']['rend_ms'], 2));

        // Ringkasan sd 5F01: olah=800, ms=160 → rend_ms=20.00
        $this->assertEquals(20.0, round($data['ringkasan']['sd']['5F01']['rend_ms'], 2));
    }

    public function test_tanggal_tidak_ada_fallback_ke_terbaru(): void
    {
        $this->seedProduksi();
        $user = $this->actingViewer();

        // Minta tanggal tanpa data → fallback ke tanggal terbaru yang ada.
        $resp = $this->actingAs($user)->getJson('/report-data/produksi?date=2026-01-01');
        $resp->assertOk();
        $data = $resp->json();

        $this->assertSame('2026-05-31', $data['date']);
        $this->assertSame(['2026-05-31'], $data['dates']);
    }
}
