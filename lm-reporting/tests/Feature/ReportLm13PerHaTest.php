<?php

namespace Tests\Feature;

use App\Domain\Report\Lm13Service;
use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportLm13PerHaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_baris_per_ha_dihitung_dari_luas_area_tm_inti(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        // Luas Area Kebun TM Inti = 100,50 + 50,25 = 150,75 Ha.
        $mk = fn (string $status, int $thn, float $luas) => ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => $status, 'plant_code' => $unit->code,
            'divisi' => 'AFD01', 'tahun_tanam' => $thn, 'luas_tanam' => $luas,
            'total_pokok_produktif' => 1, 'komoditi' => 'KS',
        ]);
        $mk('TM', 2011, 100.50);
        $mk('TM', 2012, 50.25);

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        // Set numerator (rupiah) pada blok OLAH_JUAL: 53=Jumlah Beban Tanaman,
        // 61=Jumlah Beban Penyusutan, 68=Jumlah Biaya Produksi.
        $tplId = fn (int $u) => DB::table('lm_template_row')
            ->where('report_type', 'LM13')->where('komoditi', 'KS')->where('urutan', $u)->value('id');
        $set = function (int $u, float $bi, float $sd) use ($batch, $unit, $tplId): void {
            $affected = DB::table('report_lm13')
                ->where('batch_id', $batch->id)->where('unit_id', $unit->id)
                ->where('template_id', $tplId($u))->where('blok', 'OLAH_JUAL')
                ->update(['bi_real_thn_ini' => $bi, 'sd_real_thn_ini' => $sd]);
            $this->assertSame(1, $affected, "Baris urutan {$u} OLAH_JUAL harus ada");
        };
        $set(53, 1_000_000, 5_000_000);   // Jumlah Beban Tanaman
        $set(61, 200_000, 800_000);       // Jumlah Beban Penyusutan
        $set(68, 1_500_000, 7_000_000);   // Jumlah Biaya Produksi

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS')->assertOk()->json();
        $rows = collect($data['rows']);
        $oj = fn (int $urutan) => $rows->first(fn ($r) => (int) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL');

        $area = 150.75;
        // 69: Biaya Tanaman per Ha = 53 / Luas.
        $this->assertEqualsWithDelta(round(1_000_000 / $area, 2), (float) $oj(69)['bi_jumlah'], 0.01);
        $this->assertEqualsWithDelta(round(5_000_000 / $area, 2), (float) $oj(69)['sd_jumlah'], 0.01);
        // 70: Biaya Produksi excl. Penyust. per Ha = (68 − 61) / Luas.
        $this->assertEqualsWithDelta(round((1_500_000 - 200_000) / $area, 2), (float) $oj(70)['bi_jumlah'], 0.01);
        $this->assertEqualsWithDelta(round((7_000_000 - 800_000) / $area, 2), (float) $oj(70)['sd_jumlah'], 0.01);
        // 71: Biaya Produksi per Ha = 68 / Luas.
        $this->assertEqualsWithDelta(round(1_500_000 / $area, 2), (float) $oj(71)['bi_jumlah'], 0.01);
        $this->assertEqualsWithDelta(round(7_000_000 / $area, 2), (float) $oj(71)['sd_jumlah'], 0.01);
    }

    public function test_per_ha_nol_bila_luas_area_kosong(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $tplId = fn (int $u) => DB::table('lm_template_row')
            ->where('report_type', 'LM13')->where('komoditi', 'KS')->where('urutan', $u)->value('id');
        DB::table('report_lm13')
            ->where('batch_id', $batch->id)->where('unit_id', $unit->id)
            ->where('template_id', $tplId(68))->where('blok', 'OLAH_JUAL')
            ->update(['bi_real_thn_ini' => 1_500_000, 'sd_real_thn_ini' => 7_000_000]);

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS')->assertOk()->json();
        $rows = collect($data['rows']);
        $oj = fn (int $urutan) => $rows->first(fn ($r) => (int) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL');

        // Tanpa areal_blok → Luas 0 → per Ha 0 (IFERROR/0), bukan error.
        $this->assertEqualsWithDelta(0.0, (float) $oj(71)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $oj(71)['sd_jumlah'], 0.001);
    }
}
