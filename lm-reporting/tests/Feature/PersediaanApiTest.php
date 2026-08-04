<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersediaanApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedProduksi(): void
    {
        // Dua snapshot pada bulan yang sama: hanya tanggal TERBARU (2026-05-31)
        // yang boleh dipakai (pola snapshot halaman /produksi/pks).
        $rows = [
            ['posting_date' => '2026-05-15', 'plant_code' => '5F01', 'kebun_code' => '5E01', 'ms_sdbulan' => 999, 'is_sdbulan' => 99, 'tbs_diterima_sdbulan' => 9999],
            ['posting_date' => '2026-05-31', 'plant_code' => '5F01', 'kebun_code' => '5E01', 'ms_sdbulan' => 100.4, 'is_sdbulan' => 10, 'tbs_diterima_sdbulan' => 1000],
            ['posting_date' => '2026-05-31', 'plant_code' => '5F01', 'kebun_code' => '5E02', 'ms_sdbulan' => 50.4, 'is_sdbulan' => 5, 'tbs_diterima_sdbulan' => 500],
            ['posting_date' => '2026-05-31', 'plant_code' => '5F07', 'kebun_code' => '5E01', 'ms_sdbulan' => 70, 'is_sdbulan' => 7, 'tbs_diterima_sdbulan' => 700],
        ];
        foreach ($rows as $r) {
            DB::table('produksi_pks')->insert($r + ['plant_desc' => '', 'nama_kebun' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_produksi_per_plant_dari_snapshot_terbaru(): void
    {
        $this->seedProduksi();
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        // Tanpa parameter → adopsi periode terbaru yang punya data (Mei 2026).
        $data = $this->actingAs($user)->getJson('/report-data/laba-rugi/persediaan')->assertOk()->json();
        $this->assertSame(2026, $data['year']);
        $this->assertSame(5, $data['month']);
        $this->assertSame('2026-05-31', $data['date']);
        $this->assertSame([['year' => 2026, 'month' => 5]], $data['periods']);

        // Snapshot 15 Mei diabaikan; round-of-sum: 100.4+50.4 = 150.8 → 151
        // (bukan penjumlahan hasil pembulatan per baris = 150).
        $this->assertSame(151, (int) $data['produksi']['ms']['5F01']);
        $this->assertSame(15, (int) $data['produksi']['is']['5F01']);
        $this->assertSame(1500, (int) $data['produksi']['tbs']['5F01']);
        $this->assertSame(70, (int) $data['produksi']['ms']['5F07']);

        // Periode tanpa snapshot → produksi null (tidak diam-diam pindah bulan).
        $kosong = $this->actingAs($user)->getJson('/report-data/laba-rugi/persediaan?year=2026&month=1')->assertOk()->json();
        $this->assertNull($kosong['produksi']);
        $this->assertSame(1, $kosong['month']);
        $this->assertNull($kosong['date']);
    }

    public function test_butuh_otentikasi(): void
    {
        $this->getJson('/report-data/laba-rugi/persediaan')->assertStatus(401);
    }
}
