<?php

namespace Tests\Feature;

use App\Domain\Report\Lm14Service;
use App\Models\Batch;
use App\Models\LmTemplateRow;
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
        // Pivot popup: grup baris = "Klasifikasi 2" (SUB REKENING), baris = "Kode B",
        // kolom = "Klasifikasi STAS" (KATEGORI BKU) — semuanya dari kolom `raw`.
        foreach ([
            [5, 'STAS', '51101048', 100, 'DOC-100', 'STAS01', 'a. Gaji'],
            [5, 'STAS', '90042085', 50, 'DOC-050', 'STAS01', 'g. Premi/Lembur'],
            [4, 'STAS', '51101048', 30, 'DOC-030', 'STAS01', 'a. Gaji'], // periode lain → masuk s.d, bukan bulan ini
            [5, 'BT13', '0', 999, 'DOC-999', 'BT13', 'f. Lain-lain'],     // overhead Penerangan → tak boleh masuk Premi
        ] as [$period, $cc, $ce, $nilai, $doc, $kodeB, $kategori]) {
            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id, 'plant_code' => $unit->code, 'period' => $period,
                'cost_center' => $cc, 'cost_element' => $ce, 'klasifikasi_code' => '1', 'nilai' => $nilai,
                'raw' => json_encode([
                    'Document Number' => $doc, 'Cost Element' => $ce, 'Value in Obj. Crcy' => $nilai,
                    'CO Object Name' => 'Empty Bunch Hopper',
                    'Kode B' => $kodeB, 'Klasifikasi 2' => 'c. 603-604 - Premi', 'Klasifikasi STAS' => $kategori,
                ]),
            ]);
        }

        // Premi = urutan 18 (kode "603-604" tidak unik → drill pakai urutan).
        $base = ['type' => 'LM16', 'batch' => $batch->id, 'unit' => $unit->code, 'kode' => 18];

        // Level-1 pivot bulan ini: hanya STAS periode 5 → 100 + 50 = 150 (BT13 dibuang).
        // Grup = Klasifikasi 2 "c. 603-604 - Premi"; kategori urut alfabetis → "a. Gaji" dulu.
        $pivot = $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['column' => 'bi_jumlah']));
        $pivot->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('pivot.grand_total', 150)
            ->assertJsonPath('pivot.categories.0', 'a. Gaji')
            ->assertJsonPath('pivot.categories.1', 'g. Premi/Lembur')
            ->assertJsonPath('pivot.groups.0.pb7', 'c. 603-604 - Premi')
            ->assertJsonPath('pivot.groups.0.rows.0.pb712', 'STAS01')
            ->assertJsonPath('pivot.groups.0.rows.0.obj', 'Empty Bunch Hopper');

        // Level-2 deep untuk sel SUB REKENING × Kode B × KATEGORI BKU "a. Gaji" (bulan ini) → 100.
        $deepKey = ['pb7' => 'c. 603-604 - Premi', 'pb712' => 'STAS01', 'klasifikasi' => 'a. Gaji'];
        $deep = $this->actingAs($operator)->getJson('/api/report/drilldown-deep?'.http_build_query($base + ['column' => 'bi_jumlah'] + $deepKey));
        $deep->assertOk()->assertJsonPath('detail.sections.0.table', 'pks_biaya')
            ->assertJsonPath('detail.grand_total', 100)
            // Data mentah apa adanya: kolom asli file, urut sesuai header file (CO Object Name kol ke-2 → tampil duluan).
            ->assertJsonPath('detail.sections.0.columns.0.field', 'CO Object Name')
            ->assertJsonPath('detail.sections.0.rows.0.Document Number', 'DOC-100');

        // s.d bulan ini = periode <=5 untuk kategori "a. Gaji" → 100 + 30 = 130.
        $deepSd = $this->actingAs($operator)->getJson('/api/report/drilldown-deep?'.http_build_query($base + ['column' => 'sd_jumlah'] + $deepKey));
        $deepSd->assertOk()->assertJsonPath('detail.grand_total', 130);

        // Baris produksi (urut 3) tidak punya pivot sumber biaya.
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query(['type' => 'LM16', 'batch' => $batch->id, 'unit' => $unit->code, 'kode' => 3, 'column' => 'bi_jumlah']))
            ->assertOk()->assertJsonPath('pivot', null);
    }

    public function test_lm16_drilldown_produksi_per_kebun(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final', 'processed_at' => '2026-05-20 08:00:00']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail(); // Olah
        $operator = User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OPERATOR))->firstOrFail();

        $mk = fn (string $plant, string $kebun, array $v) => array_merge([
            'posting_date' => '2026-05-31', 'tidak_mengolah' => false,
            'plant_code' => $plant, 'plant_desc' => 'PKS', 'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => $kebun, 'nama_kebun' => 'KEBUN '.$kebun,
            'tbs_diterima_sdhari' => 0, 'tbs_diterima_sdbulan' => 0, 'tbs_diolah_sdhari' => 0, 'tbs_diolah_sdbulan' => 0,
            'sisa_akhir' => 0, 'ms_sdhari' => 0, 'ms_sdbulan' => 0, 'is_sdhari' => 0, 'is_sdbulan' => 0,
        ], $v);
        DB::table('produksi_pks')->insert([
            $mk('5F01', '5E01', ['tbs_diterima_sdhari' => 60, 'tbs_diolah_sdhari' => 50, 'sisa_akhir' => 3, 'ms_sdhari' => 10, 'is_sdhari' => 2]),
            $mk('5F01', '5E02', ['tbs_diterima_sdhari' => 40, 'tbs_diolah_sdhari' => 30, 'sisa_akhir' => 1, 'ms_sdhari' => 6, 'is_sdhari' => 0]),
            $mk('5F01', '5E09', []), // semua nol → harus disembunyikan
            $mk('5F04', '5E03', ['tbs_diterima_sdhari' => 999]), // pabrik lain → diabaikan
        ]);

        $base = ['type' => 'LM16', 'batch' => $batch->id, 'unit' => $unit->code];

        // Baris aditif "TBS dari Lapangan (masuk)" (urut 3): per kebun, Grand Total = Σ = 100.
        $tbs = $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['kode' => 3, 'column' => 'bi_jumlah']));
        $tbs->assertOk()
            ->assertJsonPath('context.produksi_detail', true)
            ->assertJsonPath('produksi.kind', 'additive')
            ->assertJsonPath('produksi.row_count', 2)       // 5E09 nol disembunyikan, 5F04 diabaikan
            ->assertJsonPath('produksi.grand', 100)
            ->assertJsonPath('produksi.rows.0.kebun', '5E01')
            ->assertJsonPath('produksi.rows.0.value', 60)
            ->assertJsonPath('produksi.rows.1.kebun', '5E02')
            ->assertJsonPath('produksi.rows.1.value', 40);

        // Baris rendemen MS (urut 11): per kebun + komponen; Grand Total = ΣMS/Σolah×100 = 16/80×100 = 20.
        $rms = $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['kode' => 11, 'column' => 'bi_jumlah']));
        $rms->assertOk()
            ->assertJsonPath('produksi.kind', 'rendemen')
            ->assertJsonPath('produksi.row_count', 2)
            ->assertJsonPath('produksi.grand_comp', 16)
            ->assertJsonPath('produksi.grand_olah', 80)
            ->assertJsonPath('produksi.grand', 20)
            ->assertJsonPath('produksi.rows.0.rendemen', 20); // 10/50×100
    }

    public function test_lm16_semua_unit_konsolidasi(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final', 'processed_at' => '2026-05-20 08:00:00']);
        $operator = User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OPERATOR))->firstOrFail();

        $olah = RefUnit::query()->where('code', '5F01')->firstOrFail();  // Olah
        $kso = RefUnit::query()->where('code', '5F14')->firstOrFail();   // Non Olah

        // Template LM16: satu baris biaya (urut>=16) + baris produksi urut 3 & 11.
        $biaya = LmTemplateRow::query()->where('report_type', 'LM16')->where('urutan', '>=', 16)->orderBy('urutan')->firstOrFail();
        $tpl3 = LmTemplateRow::query()->where('report_type', 'LM16')->where('urutan', 3)->firstOrFail();
        $tpl11 = LmTemplateRow::query()->where('report_type', 'LM16')->where('urutan', 11)->firstOrFail();

        $r16 = fn (RefUnit $u, LmTemplateRow $t, array $v) => DB::table('report_lm16')->insert(array_merge([
            'batch_id' => $batch->id, 'unit_id' => $u->id, 'template_id' => $t->id,
            'real_bln_lalu' => 0, 'bi_olah' => 0, 'bi_kso' => 0, 'bi_jumlah' => 0, 'bi_rko' => 0, 'bi_rkap' => 0,
            'sd_olah' => 0, 'sd_kso' => 0, 'sd_jumlah' => 0, 'sd_rko' => 0, 'sd_rkap' => 0,
            'cap_bi_lalu' => 0, 'cap_bi_rkap' => 0, 'cap_bi_sd' => 0, 'cap_sd_rkap' => 0, 'rp_kg_tbs' => 0, 'rp_kg_mi' => 0,
        ], $v));

        // Biaya: unit Olah bi_jumlah=100 (kolom Olah), unit KSO bi_jumlah=50 (kolom KSO); rkap 200/100.
        $r16($olah, $biaya, ['bi_olah' => 100, 'bi_jumlah' => 100, 'bi_rkap' => 200, 'sd_olah' => 100, 'sd_jumlah' => 100, 'sd_rkap' => 200]);
        $r16($kso, $biaya, ['bi_kso' => 50, 'bi_jumlah' => 50, 'bi_rkap' => 100, 'sd_kso' => 50, 'sd_jumlah' => 50, 'sd_rkap' => 100]);
        // Baris produksi (placeholder nol; diisi ulang applyProduksiToLm16 dari produksi_pks).
        $r16($olah, $tpl3, []);
        $r16($olah, $tpl11, []);

        // Produksi: plant Olah 5F01 & plant Non Olah 5F14 (snapshot 31 Mei).
        $mk = fn (string $plant, array $v) => array_merge([
            'posting_date' => '2026-05-31', 'tidak_mengolah' => false, 'plant_code' => $plant, 'plant_desc' => 'PKS',
            'group_pemilik' => 'Kebun Sendiri', 'kebun_code' => '5E01', 'nama_kebun' => 'KEBUN',
            'tbs_diterima_sdhari' => 0, 'tbs_diterima_sdbulan' => 0, 'tbs_diolah_sdhari' => 0, 'tbs_diolah_sdbulan' => 0,
            'sisa_akhir' => 0, 'ms_sdhari' => 0, 'ms_sdbulan' => 0, 'is_sdhari' => 0, 'is_sdbulan' => 0,
        ], $v);
        DB::table('produksi_pks')->insert([
            $mk('5F01', ['tbs_diterima_sdhari' => 60, 'tbs_diolah_sdhari' => 50, 'ms_sdhari' => 10]),
            $mk('5F14', ['tbs_diterima_sdhari' => 40, 'tbs_diolah_sdhari' => 30, 'ms_sdhari' => 6]),
        ]);

        $resp = $this->actingAs($operator)->getJson('/api/report/lm16?batch='.$batch->id.'&unit=ALL');
        $resp->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.unit.name', 'Semua Unit');

        // Kolom konsolidasi memakai judul "Olah" (bukan "Tidak Olah").
        $cols = collect($resp->json('columns'));
        $this->assertSame('Olah', $cols->firstWhere('key', 'bi_olah')['title']);

        $rows = collect($resp->json('rows'));

        // Biaya (aditif): Olah 100 + KSO 50 = Jumlah 150; capaian BI/RKAP = 150/300×100 = 50.
        $rBiaya = $rows->firstWhere('urutan', (int) $biaya->urutan);
        $this->assertEquals(100.0, $rBiaya['bi_olah']);
        $this->assertEquals(50.0, $rBiaya['bi_kso']);
        $this->assertEquals(150.0, $rBiaya['bi_jumlah']);
        $this->assertEquals(50.0, $rBiaya['cap_bi_rkap']);

        // Produksi TBS masuk (urut 3): plant Olah → kolom Olah (60), plant Non Olah → KSO (40), Jumlah 100.
        $r3 = $rows->firstWhere('urutan', 3);
        $this->assertEquals(60.0, $r3['bi_olah']);
        $this->assertEquals(40.0, $r3['bi_kso']);
        $this->assertEquals(100.0, $r3['bi_jumlah']);

        // Rendemen MS (urut 11): Olah 10/50×100=20, KSO 6/30×100=20, Jumlah 16/80×100=20 (rasio total).
        $r11 = $rows->firstWhere('urutan', 11);
        $this->assertEquals(20.0, $r11['bi_olah']);
        $this->assertEquals(20.0, $r11['bi_kso']);
        $this->assertEquals(20.0, $r11['bi_jumlah']);

        // Drill-down PRODUKSI konsolidasi (kode 3 TBS masuk): Jumlah=100, Olah=60 (5F01), KSO=40 (5F14).
        $dd = ['type' => 'LM16', 'batch' => $batch->id, 'unit' => 'ALL', 'kode' => 3];
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($dd + ['column' => 'bi_jumlah']))
            ->assertOk()->assertJsonPath('context.produksi_detail', true)->assertJsonPath('produksi.grand', 100);
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($dd + ['column' => 'bi_olah']))
            ->assertOk()->assertJsonPath('produksi.grand', 60);
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($dd + ['column' => 'bi_kso']))
            ->assertOk()->assertJsonPath('produksi.grand', 40);
    }

    public function test_lm16_semua_unit_drilldown_biaya_olah_kso(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final', 'processed_at' => '2026-05-20 08:00:00']);
        $operator = User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OPERATOR))->firstOrFail();

        // pks_biaya baris Premi (urut 18) — plant Olah 5F01 (100+50) & Non Olah 5F14 (40), periode 5.
        $ins = fn (string $plant, string $ce, int $nilai) => DB::table('pks_biaya')->insert([
            'batch_id' => $batch->id, 'plant_code' => $plant, 'period' => 5,
            'cost_center' => 'STAS', 'cost_element' => $ce, 'klasifikasi_code' => '1', 'nilai' => $nilai,
            'raw' => json_encode(['Kode B' => 'STAS01', 'Klasifikasi 2' => 'c. 603-604 - Premi', 'Klasifikasi STAS' => 'a. Gaji', 'Value in Obj. Crcy' => $nilai]),
        ]);
        $ins('5F01', '51101048', 100);
        $ins('5F01', '90042085', 50);
        $ins('5F14', '51101048', 40);

        $base = ['type' => 'LM16', 'batch' => $batch->id, 'unit' => 'ALL', 'kode' => 18];

        // Jumlah = seluruh plant PKS KS = 190.
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['column' => 'bi_jumlah']))
            ->assertOk()->assertJsonPath('pivot.grand_total', 190);
        // Olah = hanya unit Olah (5F01) = 150.
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['column' => 'bi_olah']))
            ->assertOk()->assertJsonPath('pivot.grand_total', 150);
        // KSO = hanya unit Non Olah (5F14) = 40.
        $this->actingAs($operator)->getJson('/api/report/drilldown?'.http_build_query($base + ['column' => 'bi_kso']))
            ->assertOk()->assertJsonPath('pivot.grand_total', 40);

        // Level-2 (deep) konsolidasi kolom Olah → hanya 5F01 = 150 (data mentah pks_biaya).
        $deepKey = ['pb7' => 'c. 603-604 - Premi', 'pb712' => 'STAS01', 'klasifikasi' => 'a. Gaji'];
        $this->actingAs($operator)->getJson('/api/report/drilldown-deep?'.http_build_query($base + ['column' => 'bi_olah'] + $deepKey))
            ->assertOk()->assertJsonPath('detail.grand_total', 150);
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

    public function test_lm16_kumulatif_dan_bulan_lalu_lintas_batch(): void
    {
        $this->seed();
        $jan = Batch::query()->create(['code' => 'Batch #2026-01', 'year' => 2026, 'month' => 1, 'status' => 'final', 'processed_at' => '2026-01-20 08:00:00']);
        $feb = Batch::query()->create(['code' => 'Batch #2026-02', 'year' => 2026, 'month' => 2, 'status' => 'final', 'processed_at' => '2026-02-20 08:00:00']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail();

        // Satu cost element (Premi) di DUA batch bulan berbeda, tahun sama.
        $ins = fn (Batch $b, int $period, int $nilai) => DB::table('pks_biaya')->insert([
            'batch_id' => $b->id, 'plant_code' => $unit->code, 'period' => $period,
            'cost_center' => 'STAS', 'cost_element' => '51101048', 'klasifikasi_code' => '1', 'nilai' => $nilai,
        ]);
        $ins($jan, 1, 100);   // Januari (batch lain)
        $ins($feb, 2, 50);    // Februari (batch berjalan)

        // Generate LM16 Februari: bulan-lalu & kumulatif WAJIB lintas-batch (pola LM14).
        app(\App\Domain\Report\Lm16Service::class)->generate($feb, $unit);

        $tpl = LmTemplateRow::query()->where('report_type', 'LM16')->where('uraian', 'Premi')->firstOrFail();
        $row = DB::table('report_lm16')->where('batch_id', $feb->id)->where('unit_id', $unit->id)->where('template_id', $tpl->id)->first();

        $this->assertEqualsWithDelta(50.0, (float) $row->bi_jumlah, 0.01);       // bulan ini = Februari
        $this->assertEqualsWithDelta(100.0, (float) $row->real_bln_lalu, 0.01);  // bulan lalu = Januari (batch lain)
        $this->assertEqualsWithDelta(150.0, (float) $row->sd_jumlah, 0.01);      // s.d Februari = Jan + Feb
    }
}
