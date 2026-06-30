<?php

namespace Tests\Feature;

use App\Domain\Report\Lm16Service;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lm16ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lm16_service_materializes_olah_kso_budget_and_indicators(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail();

        $this->insertProduction($batch, $unit->code);
        $this->insertCost($batch, $unit->code);
        $this->insertBudget($batch, $unit->code);

        $rows = app(Lm16Service::class)->generate($batch, $unit);

        $this->assertSame(56, $rows->count());
        $this->assertEquals(1000.0, (float) $this->reportRow($batch, $unit, 3)->bi_jumlah);
        $this->assertEquals(0.0, (float) $this->reportRow($batch, $unit, 3)->bi_kso);

        $pengolahan = $this->reportRow($batch, $unit, 32);
        $this->assertEquals(700.0, (float) $pengolahan->bi_jumlah);
        $this->assertEquals(1200.0, (float) $pengolahan->sd_jumlah);
        $this->assertEquals(3000.0, (float) $pengolahan->bi_rkap);

        $overhead = $this->reportRow($batch, $unit, 54);
        $this->assertEquals(300.0, (float) $overhead->bi_jumlah);

        $total = $this->reportRow($batch, $unit, 56);
        $this->assertEquals(1000.0, (float) $total->bi_jumlah);
        $this->assertEquals(4.0, (float) $total->rp_kg_mi);

        $nonOlah = RefUnit::query()->where('type', 'PABRIK')->where('olah_status', 'Non Olah')->firstOrFail();
        $this->insertProduction($batch, $nonOlah->code);
        $this->insertCost($batch, $nonOlah->code);
        app(Lm16Service::class)->generate($batch, $nonOlah);

        $this->assertEquals(0.0, (float) $this->reportRow($batch, $nonOlah, 3)->bi_olah);
        $this->assertEquals(1000.0, (float) $this->reportRow($batch, $nonOlah, 3)->bi_kso);
    }

    public function test_lm16_matches_pagun_core_values_with_reference_fixture(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'draft',
        ]);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail();

        $this->insertPagunProductionFixture($batch, $unit->code);
        $this->insertPagunCostFixture($batch, $unit->code);

        app(Lm16Service::class)->generate($batch, $unit);

        $this->assertEquals(1782274975.0, (float) $this->reportRow($batch, $unit, 32)->bi_jumlah);
        $this->assertEquals(7350614012.0, (float) $this->reportRow($batch, $unit, 32)->sd_jumlah);
        $this->assertEquals(3090869176.0, (float) $this->reportRow($batch, $unit, 56)->bi_jumlah);
        $this->assertEquals(13670999829.0, (float) $this->reportRow($batch, $unit, 56)->sd_jumlah);
        $this->assertEquals(21.38, (float) $this->reportRow($batch, $unit, 11)->bi_jumlah);
        $this->assertEquals(25.70, (float) $this->reportRow($batch, $unit, 13)->sd_jumlah);
    }

    public function test_baris_pengolahan_dan_overhead_berlabel_sama_tidak_saling_bocor(): void
    {
        // Regresi: "Biaya Penerangan"/"Biaya Air" ada di grup Pengolahan (kode 603-604)
        // DAN Overhead (kode 424/425). Nilai overhead (cost center BT13/BT14) tidak boleh
        // ikut terhitung di baris pengolahan, dan sebaliknya (sumber: lihat sheet Pagun acuan).
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5F01')->firstOrFail();

        foreach ([
            // overhead Biaya Penerangan ← cost center BT13
            [5, 'BT13', '0', 273000],
            // pengolahan Biaya Penerangan ← cost element 51100601 (di bawah STAS)
            [5, 'STAS', '51100601', 5000],
            // overhead Biaya Air ← cost center BT14 (pengolahan Biaya Air harus tetap 0)
            [5, 'BT14', '0', 41000],
        ] as [$period, $cc, $ce, $nilai]) {
            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id,
                'plant_code' => $unit->code,
                'period' => $period,
                'cost_center' => $cc,
                'cost_element' => $ce,
                'klasifikasi_code' => '1',
                'nilai' => $nilai,
            ]);
        }

        app(Lm16Service::class)->generate($batch, $unit);

        // Pengolahan (urut 26 Penerangan, 25 Air) hanya dari cost element STAS.
        $this->assertEquals(5000.0, (float) $this->reportRow($batch, $unit, 26)->bi_jumlah);
        $this->assertEquals(0.0, (float) $this->reportRow($batch, $unit, 25)->bi_jumlah);
        // Overhead (urut 51 Penerangan, 52 Air) hanya dari cost center BT.
        $this->assertEquals(273000.0, (float) $this->reportRow($batch, $unit, 51)->bi_jumlah);
        $this->assertEquals(41000.0, (float) $this->reportRow($batch, $unit, 52)->bi_jumlah);
        // Total tidak double-count: 5000 (pengolahan) + 273000 + 41000 (overhead) = 319000.
        $this->assertEquals(319000.0, (float) $this->reportRow($batch, $unit, 56)->bi_jumlah);
    }

    private function insertPagunProductionFixture(Batch $batch, string $plantCode): void
    {
        foreach ([
            4 => [
                'Jumlah Produksi TBS' => [9277880, 37383100],
                'Jumlah TBS Diolah' => [9417100, 37006050],
                'Jumlah Sisa Buah di Pabrik' => [377050, 377050],
                'Jlh. Prod. Minyak Sawit' => [2087785, 8170740],
                'Jumlah Produksi Inti Sawit' => [367268, 1433154],
            ],
            5 => [
                'Jumlah Produksi TBS' => [12418870, 49801970],
                'Jumlah TBS Diolah' => [12722220, 49728270],
                'Jumlah Sisa Buah di Pabrik' => [73700, 73700],
                'Jlh. Prod. Minyak Sawit' => [2719841, 10890581],
                'Jumlah Produksi Inti Sawit' => [458555, 1891709],
            ],
        ] as $period => $rows) {
            foreach ($rows as $uraian => [$bi, $sd]) {
                DB::table('pks_produksi')->insert([
                    'batch_id' => $batch->id,
                    'plant_code' => $plantCode,
                    'period' => $period,
                    'uraian' => $uraian,
                    'nilai_bi' => $bi,
                    'nilai_sd' => $sd,
                ]);
            }
        }
    }

    private function insertPagunCostFixture(Batch $batch, string $plantCode): void
    {
        foreach ([
            [4, 'STAS', '51100402', 5568339037],
            [5, 'STAS', '51100402', 1782274975],
            [4, 'BT01', '51100402', 771737055],
            [5, 'BT01', '51100402', 243527481],
            [4, 'SUP3', '51100402', 4240054561],
            [5, 'SUP3', '51100402', 1065066720],
        ] as [$period, $costCenter, $costElement, $nilai]) {
            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id,
                'plant_code' => $plantCode,
                'period' => $period,
                'cost_center' => $costCenter,
                'cost_element' => $costElement,
                'klasifikasi_code' => '1',
                'nilai' => $nilai,
            ]);
        }
    }

    private function insertProduction(Batch $batch, string $plantCode): void
    {
        foreach ([
            4 => ['Jumlah Produksi TBS' => [800, 800], 'Jumlah TBS Diolah' => [700, 700], 'Jumlah Sisa Buah di Pabrik' => [100, 100], 'Jlh. Prod. Minyak Sawit' => [150, 150], 'Jumlah Produksi Inti Sawit' => [40, 40]],
            5 => ['Jumlah Produksi TBS' => [1000, 1800], 'Jumlah TBS Diolah' => [900, 1600], 'Jumlah Sisa Buah di Pabrik' => [200, 200], 'Jlh. Prod. Minyak Sawit' => [200, 350], 'Jumlah Produksi Inti Sawit' => [50, 90]],
        ] as $period => $rows) {
            foreach ($rows as $uraian => [$bi, $sd]) {
                DB::table('pks_produksi')->insert([
                    'batch_id' => $batch->id,
                    'plant_code' => $plantCode,
                    'period' => $period,
                    'uraian' => $uraian,
                    'nilai_bi' => $bi,
                    'nilai_sd' => $sd,
                ]);
            }
        }
    }

    private function insertCost(Batch $batch, string $plantCode): void
    {
        foreach ([
            [4, 'STAS', '51100402', 500],
            [5, 'STAS', '51100402', 700],
            [5, 'BT01', '51100402', 300],
        ] as [$period, $costCenter, $costElement, $nilai]) {
            DB::table('pks_biaya')->insert([
                'batch_id' => $batch->id,
                'plant_code' => $plantCode,
                'period' => $period,
                'cost_center' => $costCenter,
                'cost_element' => $costElement,
                'klasifikasi_code' => '1',
                'nilai' => $nilai,
            ]);
        }
    }

    private function insertBudget(Batch $batch, string $plantCode): void
    {
        foreach ([
            ['Gaji, tunjangan & Bisos Kary Pelaksana', 3000],
            ['Gaji & Bisos Karpin', 2000],
            ['CPO', 200],
            ['Inti', 50],
            ['TBS Diolah', 900],
        ] as [$kode, $nilai]) {
            DB::table('budget_rkap')->insert([
                'year' => $batch->year,
                'komoditi' => 'KS',
                'plant_code' => $plantCode,
                'report_type' => 'LM16',
                'kode' => $kode,
                'nilai' => $nilai,
            ]);
        }
    }

    private function reportRow(Batch $batch, RefUnit $unit, int $urutan): object
    {
        return DB::table('report_lm16')
            ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
            ->where('report_lm16.batch_id', $batch->id)
            ->where('report_lm16.unit_id', $unit->id)
            ->where('lm_template_row.urutan', $urutan)
            ->select('report_lm16.*')
            ->first();
    }
}
