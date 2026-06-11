<?php

namespace Tests\Feature;

use App\Domain\Report\Lm14Service;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lm14ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lm14_service_materializes_detail_subtotal_total_and_achievements(): void
    {
        $this->seed();
        [$batch, $unit] = $this->fixtures();

        $rows = app(Lm14Service::class)->generate($batch, $unit, 'KS');

        $this->assertSame(210, $rows->count());
        $this->assertSame(210, DB::table('report_lm14')->where('batch_id', $batch->id)->where('unit_id', $unit->id)->count());

        $staff = $this->reportRow($batch, $unit, 2);
        $this->assertEquals(100.0, (float) $staff->real_bulan_ini);
        $this->assertEquals(50.0, (float) $staff->real_bulan_lalu);
        $this->assertEquals(40.0, (float) $staff->real_tahun_lalu);
        $this->assertEquals(1000.0, (float) $staff->rko);
        $this->assertEquals(2000.0, (float) $staff->rkap);
        $this->assertEquals(150.0, (float) $staff->real_sd_bulan_ini);
        $this->assertEquals(70.0, (float) $staff->real_sd_tahunlalu);
        $this->assertEquals(200.0, (float) $staff->cap_bi_lalu);
        $this->assertEquals(250.0, (float) $staff->cap_bi_thnlalu);
        $this->assertEquals(10.0, (float) $staff->cap_bi_rko);
        $this->assertEquals(15.0, (float) $staff->cap_sd_rko);

        $jumlahGaji = $this->reportRow($batch, $unit, 6);
        $this->assertEquals(300.0, (float) $jumlahGaji->real_bulan_ini);
        $this->assertEquals(125.0, (float) $jumlahGaji->real_bulan_lalu);
        $this->assertEquals(3000.0, (float) $jumlahGaji->rko);
        $this->assertEquals(425.0, (float) $jumlahGaji->real_sd_bulan_ini);

        $biayaTanaman = $this->reportRow($batch, $unit, 169);
        $this->assertEquals(300.0, (float) $biayaTanaman->real_bulan_ini);

        $depresiasi = $this->reportRow($batch, $unit, 171);
        $this->assertEquals(25.0, (float) $depresiasi->real_bulan_ini);

        $total = $this->reportRow($batch, $unit, 210);
        $this->assertEquals(325.0, (float) $total->real_bulan_ini);

        DB::table('db_btl')->where('batch_id', $batch->id)->where('kode_cc', 'SP01')->update(['nilai' => 150]);
        app(Lm14Service::class)->generate($batch, $unit, 'KS');
        $this->assertSame(210, DB::table('report_lm14')->where('batch_id', $batch->id)->where('unit_id', $unit->id)->count());
        $this->assertEquals(350.0, (float) $this->reportRow($batch, $unit, 6)->real_bulan_ini);
    }

    public function test_report_generate_command_generates_lm14_for_requested_unit(): void
    {
        $this->seed();
        [$batch, $unit] = $this->fixtures();

        $this->artisan('report:generate', [
            '--type' => 'LM14',
            '--batch' => (string) $batch->id,
            '--unit' => $unit->code,
            '--komoditi' => 'KS',
        ])->assertExitCode(0);

        $this->assertSame(210, DB::table('report_lm14')->where('batch_id', $batch->id)->where('unit_id', $unit->id)->count());
    }

    /**
     * @return array{0: Batch, 1: RefUnit}
     */
    private function fixtures(): array
    {
        $batchApril = Batch::query()->create([
            'code' => 'Batch #2026-04',
            'year' => 2026,
            'month' => 4,
            'status' => 'draft',
        ]);
        $batchMay = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'draft',
        ]);
        $unit = RefUnit::query()->where('code', '5E01')->firstOrFail();

        DB::table('db_btl')->insert([
            // Gaji staf "dari WBS": realisasi bulan ini ada di db_btl cost center SP01.
            ['batch_id' => $batchMay->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 5, 'kode_cc' => 'SP01', 'cost_element' => null, 'nilai' => 100],
            ['batch_id' => $batchMay->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 5, 'kode_cc' => 'SUP', 'cost_element' => '51100200', 'nilai' => 25],
        ]);
        DB::table('db_wbs')->insert([
            // Gaji staf "dari WBS": realisasi bulan lalu ada di db_wbs aktivitas 99-01.
            ['batch_id' => $batchApril->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 4, 'aktivitas' => '99-01', 'nilai' => 50],
            ['batch_id' => $batchMay->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 5, 'aktivitas' => '99-01.', 'nilai' => 200],
            ['batch_id' => $batchApril->id, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'period' => 4, 'aktivitas' => '99-01.', 'nilai' => 75],
        ]);
        DB::table('realisasi_tahun_lalu')->insert([
            ['year' => 2025, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01', 'period' => 4, 'nilai' => 30],
            ['year' => 2025, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01', 'period' => 5, 'nilai' => 40],
            ['year' => 2025, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01.', 'period' => 5, 'nilai' => 60],
        ]);
        DB::table('budget_rko')->insert([
            ['year' => 2026, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01', 'nilai' => 1000],
            ['year' => 2026, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01.', 'nilai' => 2000],
        ]);
        DB::table('budget_rkap')->insert([
            ['year' => 2026, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01', 'nilai' => 2000],
            ['year' => 2026, 'komoditi' => 'KS', 'plant_code' => $unit->code, 'report_type' => 'LM14', 'kode' => '99-01.', 'nilai' => 4000],
        ]);

        return [$batchMay, $unit];
    }

    private function reportRow(Batch $batch, RefUnit $unit, int $urutan): object
    {
        return DB::table('report_lm14')
            ->join('lm_template_row', 'report_lm14.template_id', '=', 'lm_template_row.id')
            ->where('report_lm14.batch_id', $batch->id)
            ->where('report_lm14.unit_id', $unit->id)
            ->where('lm_template_row.urutan', $urutan)
            ->select('report_lm14.*')
            ->first();
    }
}
