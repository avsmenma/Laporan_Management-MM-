<?php

namespace Tests\Feature;

use App\Domain\Report\Lm13Service;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lm13ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lm13_service_materializes_three_blocks_and_indicators(): void
    {
        $this->seed();
        [$batch, $unit] = $this->fixtures();

        $rows = app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $this->assertSame(222, $rows->count());
        $this->assertSame(222, DB::table('report_lm13')->where('batch_id', $batch->id)->where('unit_id', $unit->id)->count());

        $diterimaTotal = $this->reportRow($batch, $unit, 'OLAH_JUAL', 6);
        $diterimaOlah = $this->reportRow($batch, $unit, 'OLAH', 6);
        $diterimaJual = $this->reportRow($batch, $unit, 'JUAL', 6);
        $this->assertEquals(1000.0, (float) $diterimaTotal->bi_real_thn_ini);
        $this->assertEquals(1500.0, (float) $diterimaTotal->sd_real_thn_ini);
        $this->assertEquals(600.0, (float) $diterimaOlah->bi_real_thn_ini);
        $this->assertEquals(400.0, (float) $diterimaJual->bi_real_thn_ini);

        $gajiTotal = $this->reportRow($batch, $unit, 'OLAH_JUAL', 48);
        $gajiOlah = $this->reportRow($batch, $unit, 'OLAH', 48);
        $this->assertEquals(100.0, (float) $gajiTotal->bi_real_thn_ini);
        $this->assertEquals(60.0, (float) $gajiOlah->bi_real_thn_ini);

        $bebanTanaman = $this->reportRow($batch, $unit, 'OLAH_JUAL', 53);
        $this->assertEquals(1000.0, (float) $bebanTanaman->bi_real_thn_ini);

        $biayaProduksi = $this->reportRow($batch, $unit, 'OLAH_JUAL', 68);
        $this->assertEquals(1300.0, (float) $biayaProduksi->bi_real_thn_ini);

        $biayaTanamanPerHa = $this->reportRow($batch, $unit, 'OLAH_JUAL', 69);
        $this->assertEquals(100.0, (float) $biayaTanamanPerHa->bi_real_thn_ini);

        $biayaProduksiPerHa = $this->reportRow($batch, $unit, 'OLAH_JUAL', 71);
        $this->assertEquals(130.0, (float) $biayaProduksiPerHa->bi_real_thn_ini);

        $hpp = $this->reportRow($batch, $unit, 'OLAH_JUAL', 72);
        $this->assertEquals(3.25, (float) $hpp->bi_real_thn_ini);

        DB::table('alokasi_produksi')->where('batch_id', $batch->id)->where('produk', 'TBS Diterima')->update(['jumlah' => 700]);
        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $this->assertSame(222, DB::table('report_lm13')->where('batch_id', $batch->id)->where('unit_id', $unit->id)->count());
        $this->assertEquals(1400.0, (float) $this->reportRow($batch, $unit, 'OLAH_JUAL', 6)->bi_real_thn_ini);
    }

    public function test_report_generate_command_generates_lm13_for_requested_unit(): void
    {
        $this->seed();
        [$batch, $unit] = $this->fixtures();

        $this->artisan('report:generate', [
            '--type' => 'LM13',
            '--batch' => (string) $batch->id,
            '--unit' => $unit->code,
            '--komoditi' => 'KS',
        ])->assertExitCode(0);

        $this->assertSame(222, DB::table('report_lm13')->where('batch_id', $batch->id)->where('unit_id', $unit->id)->count());
    }

    /**
     * @return array{0: Batch, 1: RefUnit}
     */
    private function fixtures(): array
    {
        $batchApril = Batch::query()->create(['code' => 'Batch #2026-04', 'year' => 2026, 'month' => 4, 'status' => 'draft']);
        $batchMay = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E11')->firstOrFail();

        DB::table('alokasi_areal')->insert([
            'year' => 2026,
            'kebun_code' => $unit->code,
            'real_thn_lalu' => 8,
            'real_thn_ini' => 10,
            'rko' => 9,
            'rkap' => 11,
        ]);

        DB::table('alokasi_produksi')->insert([
            ['batch_id' => $batchApril->id, 'year' => 2026, 'month' => 4, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'TBS Diterima', 'jumlah' => 300],
            ['batch_id' => $batchApril->id, 'year' => 2026, 'month' => 4, 'kebun_code' => $unit->code, 'pabrik_code' => null, 'produk' => 'TBS Diterima', 'jumlah' => 200],
            ['batch_id' => $batchMay->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'TBS Diterima', 'jumlah' => 600],
            ['batch_id' => $batchMay->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => null, 'produk' => 'TBS Diterima', 'jumlah' => 400],
            ['batch_id' => $batchMay->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'CPO', 'jumlah' => 300],
            ['batch_id' => $batchMay->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'Kernel', 'jumlah' => 100],
        ]);

        foreach ([
            'Jumlah Gaji' => 100,
            'JUMLAH BIAYA PEMELIHARAAN' => 200,
            'JUMLAH BIAYA PEMUPUKAN' => 300,
            'JUMLAH BIAYA PANEN' => 150,
            'JUMLAH BIAYA PENGANGKUTAN' => 250,
            'Jumlah Overhead (Biaya Tidak Langsung)' => 100,
            'Jumlah Depresiasi' => 200,
        ] as $lm14Uraian => $value) {
            $templateId = DB::table('lm_template_row')
                ->where('report_type', 'LM14')
                ->where('komoditi', 'KS')
                ->where('uraian', $lm14Uraian)
                ->value('id');

            DB::table('report_lm14')->insert([
                'batch_id' => $batchMay->id,
                'unit_id' => $unit->id,
                'komoditi' => 'KS',
                'template_id' => $templateId,
                'real_bulan_ini' => $value,
                'real_bulan_lalu' => 0,
                'real_tahun_lalu' => $value - 10,
                'rko' => $value + 10,
                'rkap' => $value + 20,
                'real_sd_bulan_ini' => $value * 2,
                'real_sd_tahunlalu' => ($value - 10) * 2,
                'rko_sd' => ($value + 10) * 2,
                'rkap_sd' => ($value + 20) * 2,
            ]);
        }

        return [$batchMay, $unit];
    }

    private function reportRow(Batch $batch, RefUnit $unit, string $block, int $urutan): object
    {
        return DB::table('report_lm13')
            ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
            ->where('report_lm13.batch_id', $batch->id)
            ->where('report_lm13.unit_id', $unit->id)
            ->where('report_lm13.blok', $block)
            ->where('lm_template_row.urutan', $urutan)
            ->select('report_lm13.*')
            ->first();
    }
}
