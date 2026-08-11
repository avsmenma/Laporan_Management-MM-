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
            ['posting_date' => '2026-05-15', 'plant_code' => '5F01', 'kebun_code' => '5E01', 'ms_sdbulan' => 999, 'is_sdbulan' => 99, 'tbs_diterima_sdbulan' => 9999, 'tbs_diolah_sdbulan' => 8888],
            ['posting_date' => '2026-05-31', 'plant_code' => '5F01', 'kebun_code' => '5E01', 'ms_sdbulan' => 100.4, 'is_sdbulan' => 10, 'tbs_diterima_sdbulan' => 1000, 'tbs_diolah_sdbulan' => 900],
            ['posting_date' => '2026-05-31', 'plant_code' => '5F01', 'kebun_code' => '5E02', 'ms_sdbulan' => 50.4, 'is_sdbulan' => 5, 'tbs_diterima_sdbulan' => 500, 'tbs_diolah_sdbulan' => 450],
            ['posting_date' => '2026-05-31', 'plant_code' => '5F07', 'kebun_code' => '5E01', 'ms_sdbulan' => 70, 'is_sdbulan' => 7, 'tbs_diterima_sdbulan' => 700, 'tbs_diolah_sdbulan' => 650],
        ];
        foreach ($rows as $r) {
            DB::table('produksi_pks')->insert($r + ['plant_desc' => '', 'nama_kebun' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Satu baris GL penjualan; qty kredit (negatif) seperti ekspor SAP. */
    private function pjlRow(int $period, string $material, string $pc, float $qty): array
    {
        return [
            'document_number' => 'DOC-1',
            'posting_date' => sprintf('2026-%02d-10', $period),
            'year' => 2026,
            'period' => $period,
            'account' => '41100000',
            'gl_account_desc' => 'Penjualan',
            'profit_center' => $pc,
            'profit_center_desc' => 'Unit',
            'material_code' => 'M1',
            'material_desc' => $material,
            'qty' => $qty,
            'uom' => 'KG',
            'amount' => -1000,
            'customer_code' => 'C1',
            'customer_name' => 'PT Pembeli',
            'document_type' => 'RV',
            'reference' => 'REF',
        ];
    }

    public function test_penjualan_per_plant_kumulatif_sd_bulan(): void
    {
        $this->seedProduksi();
        DB::table('penjualan_produk')->insert([
            // 5F01 CPO: bulan lalu + bulan ini → s.d Mei = 300.
            $this->pjlRow(4, 'CPO', '5F01000001', -100),
            $this->pjlRow(5, 'CPO', '5F01000001', -200),
            $this->pjlRow(5, 'CPO', '5F07000001', -50),
            $this->pjlRow(5, 'INTI SAWIT', '5F01000001', -30),
            // Tidak boleh ikut: bulan sesudahnya & material di luar peta.
            $this->pjlRow(6, 'CPO', '5F01000001', -9999),
            $this->pjlRow(5, 'Lump', '5E06000001', -7),
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/laba-rugi/persediaan?year=2026&month=5')
            ->assertOk()->json();

        $this->assertSame(300, (int) $data['penjualan']['ms']['5F01']);
        $this->assertSame(50, (int) $data['penjualan']['ms']['5F07']);
        $this->assertSame(30, (int) $data['penjualan']['is']['5F01']);
        // TBS tidak punya peta penjualan; Lump bukan material sawit.
        $this->assertArrayNotHasKey('tbs', $data['penjualan']);
        $this->assertArrayNotHasKey('5E06', $data['penjualan']['ms']);

        // Periode tanpa snapshot produksi tetap boleh punya angka penjualan.
        $tanpaSnapshot = $this->actingAs($user)->getJson('/report-data/laba-rugi/persediaan?year=2026&month=4')
            ->assertOk()->json();
        $this->assertNull($tanpaSnapshot['produksi']);
        $this->assertSame(100, (int) $tanpaSnapshot['penjualan']['ms']['5F01']);
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
        // TBS Diolah (PENGOLAHAN SENDIRI) = Σ tbs_diolah_sdbulan snapshot terbaru.
        $this->assertSame(1350, (int) $data['produksi']['olah']['5F01']);
        $this->assertSame(650, (int) $data['produksi']['olah']['5F07']);

        // Periode tanpa snapshot → produksi null (tidak diam-diam pindah bulan).
        $kosong = $this->actingAs($user)->getJson('/report-data/laba-rugi/persediaan?year=2026&month=1')->assertOk()->json();
        $this->assertNull($kosong['produksi']);
        $this->assertSame(1, $kosong['month']);
        $this->assertNull($kosong['date']);
    }

    public function test_nilai_persediaan_akhir_dari_impor_zstock(): void
    {
        $this->seedProduksi();
        DB::table('persediaan_nilai')->insert([
            ['year' => 2026, 'period' => 5, 'plant_code' => '5F01', 'unit_name' => 'PKS Gunung Meliau', 'product' => '- Minyak Sawit', 'nilai_rp' => 1000, 'created_at' => now(), 'updated_at' => now()],
            ['year' => 2026, 'period' => 5, 'plant_code' => '5R00', 'unit_name' => 'Tanah Merah', 'product' => '- Minyak Sawit', 'nilai_rp' => 250, 'created_at' => now(), 'updated_at' => now()],
            ['year' => 2026, 'period' => 5, 'plant_code' => '5E06', 'unit_name' => 'Kebun Sintang', 'product' => 'LUMP', 'nilai_rp' => 77, 'created_at' => now(), 'updated_at' => now()],
            // Periode lain tidak boleh ikut.
            ['year' => 2026, 'period' => 4, 'plant_code' => '5F01', 'unit_name' => 'PKS Gunung Meliau', 'product' => '- Minyak Sawit', 'nilai_rp' => 999, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/laba-rugi/persediaan?year=2026&month=5')
            ->assertOk()->json();

        // Kunci dinormalkan: huruf besar & tanpa awalan '-'.
        $this->assertEqualsWithDelta(1000, $data['nilai']['MINYAK SAWIT']['5F01|PKS GUNUNG MELIAU'], 0.001);
        $this->assertEqualsWithDelta(250, $data['nilai']['MINYAK SAWIT']['5R00|TANAH MERAH'], 0.001);
        $this->assertEqualsWithDelta(77, $data['nilai']['LUMP']['5E06|KEBUN SINTANG'], 0.001);
        $this->assertCount(2, $data['nilai']['MINYAK SAWIT']);
    }

    public function test_butuh_otentikasi(): void
    {
        $this->getJson('/report-data/laba-rugi/persediaan')->assertStatus(401);
    }
}
