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

    public function test_baris_plasma_dan_pihak_iii_ditarik_dari_produksi_pks(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        // 5E02 Kebun Gunung Mas terpetakan ke plant 5F01 (LM13_PEMBELIAN_PLANT_KEBUN).
        $unit = RefUnit::query()->where('code', '5E02')->firstOrFail();

        $mk = fn (string $kebun, string $plant, array $v) => array_merge([
            'posting_date' => '2026-05-31', 'tidak_mengolah' => false,
            'plant_code' => $plant, 'plant_desc' => 'PKS', 'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => $kebun, 'nama_kebun' => $kebun,
        ], $v);
        DB::table('produksi_pks')->insert([
            // Kebun Inti: dijumlah lintas plant (di sini satu plant saja).
            $mk('5E02', '5F08', ['tbs_diterima_sdhari' => 100, 'tbs_diterima_sdbulan' => 1000, 'tbs_diolah_sdhari' => 80, 'tbs_diolah_sdbulan' => 800, 'sisa_akhir' => 5, 'ms_sdhari' => 16, 'ms_sdbulan' => 160, 'is_sdhari' => 4, 'is_sdbulan' => 40]),
            // Plasma & Pihak III pada plant terpetakan (5F01).
            $mk('PLSM', '5F01', ['tbs_diterima_sdhari' => 50, 'tbs_diterima_sdbulan' => 500, 'tbs_diolah_sdhari' => 40, 'tbs_diolah_sdbulan' => 400, 'sisa_akhir' => 3, 'ms_sdhari' => 8, 'ms_sdbulan' => 80, 'is_sdhari' => 2, 'is_sdbulan' => 20]),
            $mk('PHTG', '5F01', ['tbs_diterima_sdhari' => 30, 'tbs_diterima_sdbulan' => 300, 'tbs_diolah_sdhari' => 20, 'tbs_diolah_sdbulan' => 200, 'sisa_akhir' => 1, 'ms_sdhari' => 4, 'ms_sdbulan' => 40, 'is_sdhari' => 1, 'is_sdbulan' => 10]),
            // Plant lain (5F04 → milik 5E04) tidak boleh ikut untuk 5E02.
            $mk('PLSM', '5F04', ['tbs_diterima_sdhari' => 999, 'tbs_diterima_sdbulan' => 999, 'tbs_diolah_sdhari' => 999, 'tbs_diolah_sdbulan' => 999, 'sisa_akhir' => 999, 'ms_sdhari' => 999, 'ms_sdbulan' => 999, 'is_sdhari' => 999, 'is_sdbulan' => 999]),
        ]);

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS')->assertOk()->json();
        $rows = collect($data['rows']);
        $oj = fn (string $urutan) => $rows->first(fn ($r) => (string) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL');
        $bi = fn (string $urutan) => (float) $oj($urutan)['bi_jumlah'];
        $sd = fn (string $urutan) => (float) $oj($urutan)['sd_jumlah'];

        // A Saldo Awal = Diolah + Akhir − Diterima: Plasma (3) = 40+3−50 = −7;
        // Pihak III (4) = 20+1−30 = −9; Jumlah (4.5) = −15 + −7 + −9 = −31.
        $this->assertEqualsWithDelta(-7.0, $bi('3'), 0.001);
        $this->assertEqualsWithDelta(-9.0, $bi('4'), 0.001);
        $this->assertEqualsWithDelta(-31.0, $bi('4.5'), 0.001);

        // B Diterima: Plasma (7) 50/500, Pihak III (8) 30/300, Jumlah (9) = 180.
        $this->assertEqualsWithDelta(50.0, $bi('7'), 0.001);
        $this->assertEqualsWithDelta(500.0, $sd('7'), 0.001);
        $this->assertEqualsWithDelta(30.0, $bi('8'), 0.001);
        $this->assertEqualsWithDelta(180.0, $bi('9'), 0.001);

        // D MS: Plasma (17) 8, Pihak III (18) 4, Jumlah (19) = 28.
        // E IS: Plasma (22) 2, Pihak III (23) 1, Jumlah (24) = 7; MS+IS (25) = 35.
        $this->assertEqualsWithDelta(8.0, $bi('17'), 0.001);
        $this->assertEqualsWithDelta(4.0, $bi('18'), 0.001);
        $this->assertEqualsWithDelta(28.0, $bi('19'), 0.001);
        $this->assertEqualsWithDelta(2.0, $bi('22'), 0.001);
        $this->assertEqualsWithDelta(1.0, $bi('23'), 0.001);
        $this->assertEqualsWithDelta(7.0, $bi('24'), 0.001);
        $this->assertEqualsWithDelta(35.0, $bi('25'), 0.001);

        // F TBS Olah: Plasma (29) 40, Pihak III (30) 20, Jumlah (31) = 140.
        $this->assertEqualsWithDelta(40.0, $bi('29'), 0.001);
        $this->assertEqualsWithDelta(20.0, $bi('30'), 0.001);
        $this->assertEqualsWithDelta(140.0, $bi('31'), 0.001);

        // F MS (33/34/35 = D per pihak) Jumlah (36) = 28; F IS Jumlah (41) = 7;
        // Jumlah Produksi yang MS+IS (42) = 35.
        $this->assertEqualsWithDelta(16.0, $bi('33'), 0.001);
        $this->assertEqualsWithDelta(8.0, $bi('34'), 0.001);
        $this->assertEqualsWithDelta(28.0, $bi('36'), 0.001);
        $this->assertEqualsWithDelta(4.0, $bi('38'), 0.001);
        $this->assertEqualsWithDelta(7.0, $bi('41'), 0.001);
        $this->assertEqualsWithDelta(35.0, $bi('42'), 0.001);

        // F Restan Loading Ramp per pihak (43/44/45) → Saldo Akhir TBS (46) = 5+3+1 = 9.
        $this->assertEqualsWithDelta(5.0, $bi('43'), 0.001);
        $this->assertEqualsWithDelta(3.0, $bi('44'), 0.001);
        $this->assertEqualsWithDelta(1.0, $bi('45'), 0.001);
        $this->assertEqualsWithDelta(9.0, $bi('46'), 0.001);
        $this->assertEqualsWithDelta(9.0, $sd('46'), 0.001);

        // HPP Pihak III (73): biaya (67) masih 0 tanpa data pembelian → tetap 0 ('-').
        $this->assertEqualsWithDelta(0.0, $bi('73'), 0.001);
    }

    public function test_kolom_real_thn_lalu_produksi_ditarik_dari_produksi_pks_tahun_lalu(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        $mk = fn (string $plant, string $date, array $v) => array_merge([
            'posting_date' => $date, 'tidak_mengolah' => false,
            'plant_code' => $plant, 'plant_desc' => 'PKS', 'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => '5E01', 'nama_kebun' => 'KEBUN GUNUNG MELIAU',
        ], $v);

        // Tahun ini (2026-05) — agar laporan punya nilai Real Bln Ini juga.
        DB::table('produksi_pks')->insert([
            $mk('5F01', '2026-05-31', ['tbs_diterima_sdhari' => 70, 'tbs_diterima_sdbulan' => 700, 'tbs_diolah_sdhari' => 50, 'tbs_diolah_sdbulan' => 500, 'sisa_akhir' => 6, 'ms_sdhari' => 12, 'ms_sdbulan' => 120, 'is_sdhari' => 3, 'is_sdbulan' => 30]),
        ]);

        // Tahun lalu (2025-05) — sumber kolom "Real Thn Lalu" / "s.d Thn Lalu".
        DB::table('produksi_pks')->insert([
            $mk('5F01', '2025-05-31', ['tbs_diterima_sdhari' => 60, 'tbs_diterima_sdbulan' => 600, 'tbs_diolah_sdhari' => 48, 'tbs_diolah_sdbulan' => 480, 'sisa_akhir' => 3, 'ms_sdhari' => 10, 'ms_sdbulan' => 100, 'is_sdhari' => 2, 'is_sdbulan' => 20]),
            $mk('5F04', '2025-05-31', ['tbs_diterima_sdhari' => 40, 'tbs_diterima_sdbulan' => 400, 'tbs_diolah_sdhari' => 32, 'tbs_diolah_sdbulan' => 320, 'sisa_akhir' => 1, 'ms_sdhari' => 6, 'ms_sdbulan' => 60, 'is_sdhari' => 1, 'is_sdbulan' => 10]),
        ]);
        // Kebun lain tahun lalu tidak boleh ikut (uji filter kebun).
        DB::table('produksi_pks')->insert($mk('5F01', '2025-05-31', ['kebun_code' => '5E02', 'tbs_diterima_sdhari' => 999, 'tbs_diterima_sdbulan' => 999]));

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $data = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS')->assertOk()->json();
        $rows = collect($data['rows']);
        $oj = fn (string $urutan) => $rows->first(fn ($r) => (string) $r['urutan'] === $urutan && $r['block'] === 'OLAH_JUAL');

        // Agregat tahun lalu (5E01, 2025-05-31): diterima 100/1000, olah 80/800, akhir 4,
        // MS 16/160, IS 3/30. (5E02 dikecualikan.)
        // B Diterima · Kebun Inti (6) → Real Thn Lalu 100, s.d Thn Lalu 1000.
        $this->assertEqualsWithDelta(100.0, (float) $oj('6')['real_thn_lalu'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $oj('6')['sd_real_thn_lalu'], 0.001);
        // B Jumlah (9) = 6+7+8.
        $this->assertEqualsWithDelta(100.0, (float) $oj('9')['real_thn_lalu'], 0.001);
        // D Minyak Sawit · Kebun Inti (16) → 16/160; E Inti Sawit (21) → 3/30.
        $this->assertEqualsWithDelta(16.0, (float) $oj('16')['real_thn_lalu'], 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $oj('21')['real_thn_lalu'], 0.001);
        // Jumlah Produksi MS + IS (25) = 19/190.
        $this->assertEqualsWithDelta(19.0, (float) $oj('25')['real_thn_lalu'], 0.001);
        $this->assertEqualsWithDelta(190.0, (float) $oj('25')['sd_real_thn_lalu'], 0.001);
        // F TBS Olah · Kebun Inti (28) → 80/800; Jumlah (31) sama.
        $this->assertEqualsWithDelta(80.0, (float) $oj('28')['real_thn_lalu'], 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $oj('31')['real_thn_lalu'], 0.001);
        // A Saldo Awal · Kebun Inti (2) = olah+akhir−diterima = 80+4−100 = −16 (sd 800+4−1000 = −196).
        $this->assertEqualsWithDelta(-16.0, (float) $oj('2')['real_thn_lalu'], 0.001);
        $this->assertEqualsWithDelta(-196.0, (float) $oj('2')['sd_real_thn_lalu'], 0.001);
        // Saldo Akhir TBS (46) → sisa_akhir 4 (bi & sd sama).
        $this->assertEqualsWithDelta(4.0, (float) $oj('46')['real_thn_lalu'], 0.001);

        // Kolom "Real Bln Ini" (tahun ini) tetap dari snapshot 2026-05: diterima 70.
        $this->assertEqualsWithDelta(70.0, (float) $oj('6')['bi_jumlah'], 0.001);
    }
}
