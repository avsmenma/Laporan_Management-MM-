<?php

namespace Tests\Feature;

use App\Domain\Report\Lm13Service;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportLm13ProduksiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_nilai_produksi_ditarik_dari_produksi_pks(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        $mk = fn (string $plant, array $v) => array_merge([
            'posting_date' => '2026-05-31', 'tidak_mengolah' => false,
            'plant_code' => $plant, 'plant_desc' => 'PKS', 'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => '5E01', 'nama_kebun' => 'KEBUN GUNUNG MELIAU',
        ], $v);
        DB::table('produksi_pks')->insert([
            $mk('5F01', ['tbs_diterima_sdhari' => 100, 'tbs_diterima_sdbulan' => 1000, 'tbs_diolah_sdhari' => 80, 'tbs_diolah_sdbulan' => 800, 'sisa_akhir' => 5, 'ms_sdhari' => 16, 'ms_sdbulan' => 160, 'is_sdhari' => 4, 'is_sdbulan' => 40]),
            $mk('5F04', ['tbs_diterima_sdhari' => 50, 'tbs_diterima_sdbulan' => 500, 'tbs_diolah_sdhari' => 40, 'tbs_diolah_sdbulan' => 400, 'sisa_akhir' => 2, 'ms_sdhari' => 8, 'ms_sdbulan' => 80, 'is_sdhari' => 2, 'is_sdbulan' => 20]),
        ]);
        // Kebun lain tidak boleh ikut (uji filter kebun).
        DB::table('produksi_pks')->insert($mk('5F01', ['kebun_code' => '5E02', 'tbs_diterima_sdhari' => 999, 'tbs_diterima_sdbulan' => 999]));

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS')->assertOk()->json();
        $rows = collect($data['rows']);
        $oj = fn (string $urutan) => $rows->first(fn ($r) => (string) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL');

        // B Diterima · Kebun Inti (urutan 6) ← TBS Diterima grand total (150 / 1500).
        $this->assertEqualsWithDelta(150.0, (float) $oj('6')['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(1500.0, (float) $oj('6')['sd_jumlah'], 0.001);
        // B Jumlah (urutan 9) = 6+7+8 (Plasma/Pihak III = 0).
        $this->assertEqualsWithDelta(150.0, (float) $oj('9')['bi_jumlah'], 0.001);

        // D Minyak Sawit · Kebun Inti (16) ← 24/240; E Inti Sawit (21) ← 6/60.
        $this->assertEqualsWithDelta(24.0, (float) $oj('16')['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(6.0, (float) $oj('21')['bi_jumlah'], 0.001);
        // Jumlah Produksi MS + IS (25) = 30 / 300.
        $this->assertEqualsWithDelta(30.0, (float) $oj('25')['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $oj('25')['sd_jumlah'], 0.001);

        // F TBS Olah · Kebun Inti (28) ← TBS Diolah 120/1200; Jumlah (31) sama.
        $this->assertEqualsWithDelta(120.0, (float) $oj('28')['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $oj('31')['bi_jumlah'], 0.001);

        // A Saldo Awal · Kebun Inti (2) = Diolah+Akhir−Diterima = 120+7−150 = −23.
        $this->assertEqualsWithDelta(-23.0, (float) $oj('2')['bi_jumlah'], 0.001);
        // Baris Jumlah seksi A (4.5 turunan) ikut = −23.
        $this->assertEqualsWithDelta(-23.0, (float) $oj('4.5')['bi_jumlah'], 0.001);

        // Saldo Akhir TBS (46) ← sisa_akhir 7 (bi & sd sama).
        $this->assertEqualsWithDelta(7.0, (float) $oj('46')['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $oj('46')['sd_jumlah'], 0.001);
    }
}
