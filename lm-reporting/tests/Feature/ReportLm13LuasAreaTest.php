<?php

namespace Tests\Feature;

use App\Domain\Report\Lm13Service;
use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportLm13LuasAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_luas_area_tm_inti_ditarik_dari_areal_blok(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        $mk = fn (string $status, int $thn, float $luas) => ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => $status, 'plant_code' => $unit->code,
            'divisi' => 'AFD01', 'tahun_tanam' => $thn, 'luas_tanam' => $luas,
            'total_pokok_produktif' => 1, 'komoditi' => 'KS',
        ]);
        // Total TM = 100,50 + 50,25 = 150,75. TBM tidak ikut. Unit lain tidak ikut.
        $mk('TM', 2011, 100.50);
        $mk('TM', 2012, 50.25);
        $mk('TBM', 2023, 999.0);
        ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => 'TM', 'plant_code' => '5E02',
            'divisi' => 'AFD01', 'tahun_tanam' => 2011, 'luas_tanam' => 777.0,
            'total_pokok_produktif' => 1, 'komoditi' => 'KS',
        ]);

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $resp = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS');
        $resp->assertOk();

        $this->assertEqualsWithDelta(150.75, (float) $resp->json('meta.area.real_thn_ini'), 0.001);
    }
}
