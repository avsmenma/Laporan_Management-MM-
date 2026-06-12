<?php

namespace Tests\Feature;

use App\Domain\Admin\DataPurgeService;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function seedPeriod(int $year, int $month): int
    {
        $batch = Batch::query()->create([
            'code' => sprintf('Batch #%04d-%02d', $year, $month),
            'year' => $year,
            'month' => $month,
            'status' => 'final',
        ]);

        DB::table('db_wbs_raw')->insert(['batch_id' => $batch->id, 'komoditi' => 'KS']);
        DB::table('db_gc')->insert(['batch_id' => $batch->id, 'komoditi' => 'KS']);
        DB::table('budget_rkap')->insert([
            'year' => $year, 'komoditi' => 'KS', 'plant_code' => '5E11', 'report_type' => 'LM14', 'kode' => '99-01', 'nilai' => 100,
        ]);

        return $batch->id;
    }

    public function test_purge_by_month_deletes_batch_data_but_keeps_annual_budget(): void
    {
        $this->seedPeriod(2026, 5);

        app(DataPurgeService::class)->purgeByMonth(2026, 5);

        $this->assertSame(0, DB::table('db_wbs_raw')->count());
        $this->assertSame(0, DB::table('db_gc')->count());
        $this->assertSame(0, Batch::query()->where('year', 2026)->where('month', 5)->count());
        // Anggaran tahunan tidak ikut terhapus saat hapus per bulan.
        $this->assertSame(1, DB::table('budget_rkap')->count());
    }

    public function test_purge_by_year_deletes_batch_data_and_annual_budget(): void
    {
        $this->seedPeriod(2026, 5);
        $this->seedPeriod(2025, 4);

        app(DataPurgeService::class)->purgeByYear(2026);

        $this->assertSame(0, Batch::query()->where('year', 2026)->count());
        $this->assertSame(0, DB::table('budget_rkap')->where('year', 2026)->count());
        // Tahun lain tetap utuh.
        $this->assertSame(1, Batch::query()->where('year', 2025)->count());
        $this->assertSame(1, DB::table('db_wbs_raw')->count());
    }

    public function test_purge_all_empties_every_data_table(): void
    {
        $this->seedPeriod(2026, 5);
        $this->seedPeriod(2025, 4);

        app(DataPurgeService::class)->purgeAll();

        $this->assertSame(0, Batch::query()->count());
        $this->assertSame(0, DB::table('db_wbs_raw')->count());
        $this->assertSame(0, DB::table('db_gc')->count());
        $this->assertSame(0, DB::table('budget_rkap')->count());
    }
}
