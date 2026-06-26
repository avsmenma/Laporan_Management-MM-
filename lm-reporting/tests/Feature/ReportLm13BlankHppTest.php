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

class ReportLm13BlankHppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_baris_harga_pokok_pihak_iii_dikosongkan(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $tplId = fn (int $u) => DB::table('lm_template_row')
            ->where('report_type', 'LM13')->where('komoditi', 'KS')->where('urutan', $u)->value('id');
        $set = fn (int $u, float $v) => DB::table('report_lm13')
            ->where('batch_id', $batch->id)->where('unit_id', $unit->id)
            ->where('template_id', $tplId($u))->where('blok', 'OLAH_JUAL')
            ->update(['bi_real_thn_ini' => $v, 'sd_real_thn_ini' => $v]);

        $set(72, 7145.0); // Harga Pokok Kebun Sendiri Rp/Kg MS+IS — tetap.
        $set(73, 7145.0); // Harga Pokok Pihak III Rp/Kg Ms+IS — dikosongkan.

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS')->assertOk()->json();
        $rows = collect($data['rows']);
        $oj = fn (int $urutan) => $rows->first(fn ($r) => (int) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL');

        // 72 (Kebun Sendiri) tetap ada; 73 (Pihak III) dikosongkan → 0 (tampil "-").
        $this->assertEqualsWithDelta(7145.0, (float) $oj(72)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $oj(73)['bi_jumlah'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $oj(73)['sd_jumlah'], 0.001);
    }
}
