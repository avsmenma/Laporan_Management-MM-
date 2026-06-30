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

    public function test_staf_gaji_bulan_lalu_drilldown_uses_db_ohc_sp01_not_wbs(): void
    {
        $this->seed();
        $april = Batch::query()->create(['code' => 'Batch #2026-04', 'year' => 2026, 'month' => 4, 'status' => 'final', 'processed_at' => '2026-04-20 08:00:00']);
        $may = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final', 'processed_at' => '2026-05-20 08:00:00']);
        $unit = RefUnit::query()->where('code', '5E11')->firstOrFail();
        $operator = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::OPERATOR))->firstOrFail();

        // Bulan lalu (April): gaji staf ada di db_ohc lock SP01 (klasifikasi Gaji).
        // db_wbs_raw 99-01 hanya noise non-gaji — rincian TIDAK boleh mengambilnya.
        DB::table('db_ohc')->insert([
            'batch_id' => $april->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 4,
            'lock' => 'SP01', 'klasifikasi' => '1. Gaji', 'value_obj_crcy' => 217,
        ]);
        DB::table('db_wbs_raw')->insert([
            'batch_id' => $april->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 4,
            'aktifitas' => '99-01', 'klasifikasi' => '6.Lain-Lain', 'value' => 9999,
        ]);

        app(Lm14Service::class)->generate($may, $unit, 'KS');

        // Rincian sumber kolom "Real Bulan Lalu" baris gaji staf = db_ohc SP01 (217),
        // bukan grand-total WBS 99-01 (217 + 9999).
        $this->actingAs($operator)
            ->getJson("/api/report/drilldown?type=LM14&batch={$may->id}&unit={$unit->code}&komoditi=KS&kode=99-01&column=real_bulan_lalu")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pivot.grand_total', 217);
    }

    public function test_drilldown_deep_includes_qty_subtotal_per_section(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E11')->firstOrFail();
        $operator = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::OPERATOR))->firstOrFail();

        DB::table('db_wbs_raw')->insert([
            ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 5, 'aktifitas' => '41-01', 'klasifikasi' => '3. Bahan', 'pekerjaan_pb7_i' => '14 - Pemupukan', 'pekerjaan_pb712_ii' => 'Pupuk', 'value' => 1000, 'qty' => 10],
            ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 5, 'aktifitas' => '41-01', 'klasifikasi' => '3. Bahan', 'pekerjaan_pb7_i' => '14 - Pemupukan', 'pekerjaan_pb712_ii' => 'Pupuk', 'value' => 500, 'qty' => 5],
        ]);
        app(Lm14Service::class)->generate($batch, $unit, 'KS');

        $query = http_build_query([
            'type' => 'LM14', 'batch' => $batch->id, 'unit' => $unit->code, 'komoditi' => 'KS',
            'kode' => '41-01', 'column' => 'bi_jumlah',
            'pb7' => '14 - Pemupukan', 'pb712' => 'Pupuk', 'klasifikasi' => '3. Bahan',
        ]);

        $response = $this->actingAs($operator)->getJson("/api/report/drilldown-deep?{$query}");
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertEquals('qty', $response->json('detail.sections.0.qty_field'));
        $this->assertEquals(15, $response->json('detail.sections.0.qty_subtotal'));
        $this->assertEquals(1500, $response->json('detail.sections.0.subtotal'));
    }

    public function test_lm16_drilldown_pivot_dan_deep_dari_pks_biaya(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final', 'processed_at' => '2026-05-20 08:00:00']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail();
        $operator = User::query()->whereHas('role', fn ($query) => $query->where('name', Role::OPERATOR))->firstOrFail();

        // Premi (urut 18, pengolahan) ← cost element STAS; BT13 overhead TIDAK boleh bocor.
        foreach ([
            [5, 'STAS', '51101048', 100],
            [5, 'STAS', '90042085', 50],
            [4, 'STAS', '51101048', 30],   // periode lain → masuk s.d, bukan bulan ini
            [5, 'BT13', '0', 999],         // overhead Penerangan → tak boleh masuk Premi
        ] as [$period, $cc, $ce, $nilai]) {
            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id, 'plant_code' => $unit->code, 'period' => $period,
                'cost_center' => $cc, 'cost_element' => $ce, 'klasifikasi_code' => '1', 'nilai' => $nilai,
            ]);
        }

        // Premi = urutan 18 (kode "603-604" tidak unik → drill pakai urutan).
        $base = ['type' => 'LM16', 'batch' => $batch->id, 'unit' => $unit->code, 'kode' => 18];

        // Level-1 pivot bulan ini: hanya STAS periode 5 → 100 + 50 = 150 (BT13 dibuang).
        $pivot = $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['column' => 'bi_jumlah']));
        $pivot->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('pivot.grand_total', 150)
            ->assertJsonPath('pivot.categories.0', 'Nilai')
            ->assertJsonPath('pivot.groups.0.pb7', 'STAS');

        // Level-2 deep untuk GL 51101048 (bulan ini) → 100.
        $deep = $this->actingAs($operator)->getJson('/api/report/drilldown-deep?'.http_build_query($base + ['column' => 'bi_jumlah', 'pb7' => 'STAS', 'pb712' => '51101048']));
        $deep->assertOk()->assertJsonPath('detail.sections.0.table', 'pks_biaya')
            ->assertJsonPath('detail.grand_total', 100);

        // s.d bulan ini = periode <=5 untuk 51101048 → 100 + 30 = 130.
        $deepSd = $this->actingAs($operator)->getJson('/api/report/drilldown-deep?'.http_build_query($base + ['column' => 'sd_jumlah', 'pb7' => 'STAS', 'pb712' => '51101048']));
        $deepSd->assertOk()->assertJsonPath('detail.grand_total', 130);

        // Baris produksi (urut 3) tidak punya pivot sumber biaya.
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query(['type' => 'LM16', 'batch' => $batch->id, 'unit' => $unit->code, 'kode' => 3, 'column' => 'bi_jumlah']))
            ->assertOk()->assertJsonPath('pivot', null);
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
