<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lm27aApiTest extends TestCase
{
    use RefreshDatabase;

    /** Satu baris GL penjualan; nilai kredit (negatif) seperti ekspor SAP. */
    private function row(int $period, string $material, string $pc, float $amount): array
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
            'qty' => -1,
            'uom' => 'KG',
            'amount' => $amount,
            'customer_code' => 'C1',
            'customer_name' => 'PT Pembeli',
            'document_type' => 'RV',
            'reference' => 'REF',
        ];
    }

    private function seedPenjualan(): void
    {
        DB::table('penjualan_produk')->insert([
            // Kelapa Sawit: TBS kebun (5E) + TBS pabrik (5F) + CPO + Inti Sawit.
            $this->row(6, 'TBS (TANDAN BUAH SEGAR)', '5E01000001', -50),
            $this->row(6, 'TBS (TANDAN BUAH SEGAR)', '5F01000001', -70),
            $this->row(5, 'CPO', '5F01000001', -1000),   // bulan lalu → ikut s.d bulan 6
            $this->row(6, 'CPO', '5F01000001', -2000),
            $this->row(6, 'INTI SAWIT', '5F01000001', -300),
            // Karet: Lump kebun lain + Lump Batulicin (baris tersendiri di LM-34).
            $this->row(6, 'Lump', '5E06000001', -25),
            $this->row(6, 'Lump', '5E13000001', -35),
            // Tidak boleh ikut: bulan sesudahnya & material di luar peta LM-34.
            $this->row(7, 'CPO', '5F01000001', -9999),
            $this->row(6, 'Gula', '5F01000001', -777),
        ]);
    }

    private function viewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_penjualan_lokal_per_budidaya_kumulatif(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=6')->assertOk()->json();

        $this->assertSame(2026, $data['year']);
        $this->assertSame(6, $data['month']);
        // Kelapa Sawit = TBS (50+70) + CPO (1000+2000) + Inti Sawit (300) = 3.420;
        // nilai kredit dibalik jadi positif seperti Excel. Karet = Lump 25+35 = 60.
        $this->assertEqualsWithDelta(3420, $data['values']['lokal']['ks'], 0.001);
        $this->assertEqualsWithDelta(60, $data['values']['lokal']['kr'], 0.001);
    }

    public function test_persediaan_awal_sama_dengan_halaman_persediaan(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=7')->assertOk()->json();

        // Angka acuan = baris "Jumlah Persediaan" kolom PERSEDIAAN AWAL TAHUN (Rp)
        // pada /laba-rugi/persediaan (Σ unit + baris penyesuaian).
        $this->assertEqualsWithDelta(194983848689, $data['values']['persediaan_awal']['ks'], 0.001);
        $this->assertEqualsWithDelta(241975496, $data['values']['persediaan_awal']['kr'], 0.001);

        // Saldo awal tahun tidak berubah per bulan.
        $lain = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2026&month=1')->assertOk()->json();
        $this->assertSame($data['values']['persediaan_awal'], $lain['values']['persediaan_awal']);
    }

    public function test_tanpa_parameter_adopsi_periode_terbaru(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a')->assertOk()->json();

        // Periode terbaru = Juli 2026 → CPO 9.999 ikut terhitung.
        $this->assertSame(2026, $data['year']);
        $this->assertSame(7, $data['month']);
        $this->assertEqualsWithDelta(13419, $data['values']['lokal']['ks'], 0.001);
        $this->assertSame(['year' => 2026, 'month' => 7], $data['periods'][0]);
    }

    public function test_periode_tanpa_data_bernilai_nol(): void
    {
        $this->seedPenjualan();

        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a?year=2025&month=12')->assertOk()->json();

        $this->assertSame(2025, $data['year']);
        $this->assertEqualsWithDelta(0, $data['values']['lokal']['ks'], 0.001);
        $this->assertEqualsWithDelta(0, $data['values']['lokal']['kr'], 0.001);
    }

    public function test_tanpa_data_sama_sekali(): void
    {
        $data = $this->actingAs($this->viewer())
            ->getJson('/report-data/laba-rugi/lm27a')->assertOk()->json();

        $this->assertSame([], $data['periods']);
        $this->assertNull($data['year']);
        $this->assertSame([], $data['values']);
    }

    public function test_butuh_otentikasi(): void
    {
        $this->getJson('/report-data/laba-rugi/lm27a')->assertStatus(401);
    }
}
