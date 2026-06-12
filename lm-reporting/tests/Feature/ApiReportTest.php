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
                            'real_bulan_lalu' => [
                                'value',
                                'drilldown' => ['kode_baris', 'column_key'],
                            ],
                            'bi_jumlah' => [
                                'value',
                                'drilldown' => ['kode_baris', 'column_key'],
                            ],
                            'sd_real_thn_lalu' => [
                                'value',
                                'drilldown' => ['kode_baris', 'column_key'],
                            ],
                            'cap_bi_thnlalu' => [
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
            ->assertJsonPath('context.column_key', 'bi_jumlah')
            // Pivot rincian sumber: grand total = nilai sel (baris db_wbs_raw yang di-seed).
            ->assertJsonPath('pivot.grand_total', 1000)
            ->assertJsonPath('pivot.categories.0', '1. Gaji');

        // Kolom non-sumber (anggaran) tidak memiliki pivot rincian.
        $this->actingAs($operator)
            ->getJson("/api/report/drilldown?type=LM14&batch={$batch->id}&unit={$unit->code}&komoditi=KS&kode=41-01&column=bi_rko")
            ->assertOk()
            ->assertJsonPath('pivot', null);
    }

    public function test_report_endpoint_accepts_authenticated_web_session(): void
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

        $this->post('/login', [
            'email' => $operator->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->getJson("/api/report/lm14?batch={$batch->id}&unit={$unit->code}&komoditi=KS")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_report_data_endpoint_accepts_signed_ui_token(): void
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

        $token = hash_hmac('sha256', "{$operator->id}|{$operator->email}|{$operator->role_id}", config('app.key'));

        $this->withHeaders([
            'X-LM-Report-User' => (string) $operator->id,
            'X-LM-Report-Token' => $token,
        ])->getJson("/report-data/lm14?batch={$batch->id}&unit={$unit->code}&komoditi=KS")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function insertLm14Source(Batch $batch, RefUnit $unit): void
    {
        DB::table('db_wbs_raw')->insert([
            'batch_id' => $batch->id,
            'komoditi' => 'KS',
            'plant_code' => $unit->code,
            'period' => 5,
            'aktifitas' => '41-01',
            'job_name' => 'TM - PEMEL JALAN MANUAL - ACCESS ROAD',
            'cost_element' => '51100001',
            'cost_element_desc' => 'Biaya Tenaga Kerja',
            'klasifikasi' => '1. Gaji',
            'value' => 1000,
            'qty' => null,
        ]);
    }
}
