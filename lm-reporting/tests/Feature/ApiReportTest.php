<?php

namespace Tests\Feature;

use App\Domain\Report\Lm14Service;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_endpoint_returns_meta_columns_rows_and_cell_drilldown_metadata(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'final',
            'processed_at' => '2026-05-20 08:00:00',
        ]);
        $unit = RefUnit::query()->where('code', '5E11')->firstOrFail();
        $operator = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::OPERATOR))->firstOrFail();

        $this->insertLm14Source($batch, $unit);
        app(Lm14Service::class)->generate($batch, $unit, 'KS');

        $this->actingAs($operator)
            ->getJson("/api/report/lm14?batch={$batch->id}&unit={$unit->code}&komoditi=KS")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.batch.period', 5)
            ->assertJsonPath('meta.kpi_hari.jumlah_hari', 31)
            ->assertJsonPath('meta.kpi_hari.hari_dijalani', 20)
            ->assertJsonStructure([
                'columns',
                'rows' => [
                    '*' => [
                        'kode',
                        'uraian',
                        'cells' => [
                            'bi_jumlah' => [
                                'value',
                                'drilldown' => ['kode_baris', 'column_key'],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_viewer_cannot_access_draft_report_and_drilldown_endpoint_exists(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E11')->firstOrFail();
        $viewer = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::VIEWER))->firstOrFail();
        $operator = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::OPERATOR))->firstOrFail();

        $this->insertLm14Source($batch, $unit);
        app(Lm14Service::class)->generate($batch, $unit, 'KS');

        $this->actingAs($viewer)
            ->getJson("/api/report/lm14?batch={$batch->id}&unit={$unit->code}&komoditi=KS")
            ->assertForbidden();

        $this->actingAs($operator)
            ->getJson("/api/report/drilldown?type=LM14&batch={$batch->id}&unit={$unit->code}&komoditi=KS&kode=41-01&column=bi_jumlah")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('context.column_key', 'bi_jumlah');
    }

    private function insertLm14Source(Batch $batch, RefUnit $unit): void
    {
        DB::table('db_wbs')->insert([
            'batch_id' => $batch->id,
            'komoditi' => 'KS',
            'plant_code' => $unit->code,
            'period' => 5,
            'aktivitas' => '41-01',
            'job_name' => 'TM - PEMEL JALAN MANUAL - ACCESS ROAD',
            'cost_element' => '51100001',
            'cost_element_desc' => 'Biaya Tenaga Kerja',
            'klasifikasi_code' => '1',
            'nilai' => 1000,
            'fisik' => null,
        ]);
    }
}
