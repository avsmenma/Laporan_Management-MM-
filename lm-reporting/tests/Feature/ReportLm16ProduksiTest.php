<?php

namespace Tests\Feature;

use App\Domain\Report\Lm16Service;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportLm16ProduksiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_produksi_lm16_ditarik_dari_produksi_pks(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail(); // PKS Gunung Meliau (Olah)

        // Dua kebun pemasok ke pabrik 5F01 → harus dijumlah. Kebun lain (pabrik lain) diabaikan.
        $mk = fn (string $plant, string $kebun, array $v) => array_merge([
            'posting_date' => '2026-05-31', 'tidak_mengolah' => false,
            'plant_code' => $plant, 'plant_desc' => 'PKS', 'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => $kebun, 'nama_kebun' => 'KEBUN',
        ], $v);
        DB::table('produksi_pks')->insert([
            $mk('5F01', '5E01', ['tbs_diterima_sdhari' => 60, 'tbs_diterima_sdbulan' => 600, 'tbs_diolah_sdhari' => 50, 'tbs_diolah_sdbulan' => 500, 'sisa_akhir' => 3, 'ms_sdhari' => 10, 'ms_sdbulan' => 100, 'is_sdhari' => 2, 'is_sdbulan' => 20]),
            $mk('5F01', '5E02', ['tbs_diterima_sdhari' => 40, 'tbs_diterima_sdbulan' => 400, 'tbs_diolah_sdhari' => 30, 'tbs_diolah_sdbulan' => 300, 'sisa_akhir' => 1, 'ms_sdhari' => 6, 'ms_sdbulan' => 60, 'is_sdhari' => 0, 'is_sdbulan' => 0]),
            // pabrik lain — tak boleh ikut
            $mk('5F04', '5E03', ['tbs_diterima_sdhari' => 999, 'tbs_diterima_sdbulan' => 999, 'tbs_diolah_sdhari' => 999, 'tbs_diolah_sdbulan' => 999, 'sisa_akhir' => 999, 'ms_sdhari' => 999, 'ms_sdbulan' => 999, 'is_sdhari' => 999, 'is_sdbulan' => 999]),
        ]);

        // Generate LM16 tanpa data pks_produksi → baris produksi awalnya 0; override dari produksi_pks.
        app(Lm16Service::class)->generate($batch, $unit);

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm16?batch='.$batch->id.'&unit='.$unit->code)->assertOk()->json();
        $rows = collect($data['rows']);
        $row = fn (int $urutan) => $rows->first(fn ($r) => (int) $r['urutan'] === $urutan);

        // Agregat 5F01 (s/d hari): TBS masuk=100, olah=80, akhir=4, MS=16, IS=2.
        $this->assertEqualsWithDelta(100.0, (float) $row(3)['bi_jumlah'], 0.001); // TBS dari Lapangan
        $this->assertEqualsWithDelta(80.0, (float) $row(4)['bi_jumlah'], 0.001);  // TBS di olah
        $this->assertEqualsWithDelta(4.0, (float) $row(5)['bi_jumlah'], 0.001);   // Stok Akhir
        // Stok Awal = olah + akhir − masuk = 80 + 4 − 100 = −16.
        $this->assertEqualsWithDelta(-16.0, (float) $row(2)['bi_jumlah'], 0.001);
        // MS=16, IS=2, Jumlah M+I=18.
        $this->assertEqualsWithDelta(16.0, (float) $row(7)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $row(8)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $row(9)['bi_jumlah'], 0.001);

        // Plant 5F01 = Olah → nilai di kolom Olah, KSO = 0.
        $this->assertEqualsWithDelta(80.0, (float) $row(4)['bi_olah'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $row(4)['bi_kso'], 0.001);

        // s/d bulan: olah=800, MS=160, IS=20.
        $this->assertEqualsWithDelta(800.0, (float) $row(4)['sd_jumlah'], 0.001);

        // Rendemen (s/d hari): MS/olah=16/80=20,00; IS=2/80=2,50; total=18/80=22,50.
        $this->assertEqualsWithDelta(20.0, (float) $row(11)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(2.5, (float) $row(12)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(22.5, (float) $row(13)['bi_jumlah'], 0.001);
        // Rendemen s/d bulan MS = 160/800 = 20,00.
        $this->assertEqualsWithDelta(20.0, (float) $row(11)['sd_jumlah'], 0.001);
    }
}
