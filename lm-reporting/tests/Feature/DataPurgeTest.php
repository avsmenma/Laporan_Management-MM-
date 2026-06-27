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
        DB::table('budget_source')->insert([
            'year' => $year, 'komoditi' => 'KS', 'plant_code' => '5E11', 'report_type' => 'LM14', 'kode' => '99-01', 'source' => 'BKU', 'nilai' => 100,
        ]);
        DB::table('db_wbs_tahun_lalu')->insert(['year' => $year, 'komoditi' => 'KS']);

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
        $this->assertSame(1, DB::table('budget_source')->count());
    }

    public function test_purge_by_year_deletes_batch_data_and_annual_budget(): void
    {
        $this->seedPeriod(2026, 5);
        $this->seedPeriod(2025, 4);

        app(DataPurgeService::class)->purgeByYear(2026);

        $this->assertSame(0, Batch::query()->where('year', 2026)->count());
        $this->assertSame(0, DB::table('budget_rkap')->where('year', 2026)->count());
        $this->assertSame(0, DB::table('budget_source')->where('year', 2026)->count());
        // Tahun lain tetap utuh.
        $this->assertSame(1, Batch::query()->where('year', 2025)->count());
        $this->assertSame(1, DB::table('db_wbs_raw')->count());
        $this->assertSame(1, DB::table('budget_source')->where('year', 2025)->count());
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
        $this->assertSame(0, DB::table('budget_source')->count());
        $this->assertSame(0, DB::table('db_wbs_tahun_lalu')->count());
    }

    private function seedKebunWb(): void
    {
        $row = fn (string $supply, float $w) => [
            'posting_date' => '2026-05-31', 'plant_code' => '5F01',
            'weight_netto' => $w, 'supply' => $supply,
            'goods_recipient' => $supply === 'Kebun Sendiri' ? '5E01' : null,
        ];
        DB::table('produksi_kebun_wb')->insert([
            $row('Kebun Sendiri', 100), $row('Kebun Sendiri', 200), $row('Pembelian', 300),
        ]);
    }

    public function test_target_produksi_kebun_sendiri_deletes_only_supply_kebun_sendiri(): void
    {
        $this->seedKebunWb();

        $counts = app(DataPurgeService::class)->purgeTarget('produksi_kebun_sendiri', 'month', 2026, 5);

        $this->assertSame(2, $counts['produksi_kebun_wb']);
        $this->assertSame(0, DB::table('produksi_kebun_wb')->where('supply', 'Kebun Sendiri')->count());
        // Pembelian tetap utuh.
        $this->assertSame(1, DB::table('produksi_kebun_wb')->where('supply', 'Pembelian')->count());
    }

    public function test_target_produksi_kebun_bulan_lain_tidak_terhapus(): void
    {
        $this->seedKebunWb();

        // Hapus bulan 4 (tak ada datanya) → tidak menyentuh data Mei.
        app(DataPurgeService::class)->purgeTarget('produksi_kebun_all', 'month', 2026, 4);
        $this->assertSame(3, DB::table('produksi_kebun_wb')->count());

        // Hapus bulan 5 → semua 3 baris terhapus.
        app(DataPurgeService::class)->purgeTarget('produksi_kebun_all', 'month', 2026, 5);
        $this->assertSame(0, DB::table('produksi_kebun_wb')->count());
    }

    public function test_target_areal_only_touches_areal_tables(): void
    {
        $batchId = $this->seedPeriod(2026, 5);
        DB::table('areal_blok')->insert(['batch_id' => $batchId, 'komoditi' => 'KS']);
        DB::table('alokasi_areal')->insert(['year' => 2026, 'kebun_code' => '5E01']);

        app(DataPurgeService::class)->purgeTarget('areal', 'month', 2026, 5);

        $this->assertSame(0, DB::table('areal_blok')->count());
        $this->assertSame(0, DB::table('alokasi_areal')->count());
        // Tabel lain & batch tidak tersentuh.
        $this->assertSame(1, DB::table('db_wbs_raw')->count());
        $this->assertSame(1, Batch::query()->count());
    }

    public function test_halaman_data_menampilkan_value_target_yang_benar(): void
    {
        $admin = \App\Models\User::factory()->create([
            'role_id' => \App\Models\Role::query()->firstOrCreate(['name' => 'Admin'])->id,
        ]);

        $resp = $this->actingAs($admin)->get('/data')->assertOk();
        // Value <option> harus berupa key target (bukan indeks numerik dari groupBy).
        $resp->assertSee('value="produksi_kebun_sendiri"', false);
        $resp->assertSee('value="produksi_kebun_pembelian"', false);
        $resp->assertSee('value="areal"', false);
        $resp->assertDontSee('<option value="0">', false);
    }

    public function test_endpoint_target_requires_admin_and_konfirmasi(): void
    {
        $this->seedKebunWb();
        $admin = \App\Models\User::factory()->create([
            'role_id' => \App\Models\Role::query()->firstOrCreate(['name' => 'Admin'])->id,
        ]);

        $this->actingAs($admin)
            ->post('/data/purge', [
                'target' => 'produksi_kebun_pembelian', 'mode' => 'month',
                'year' => 2026, 'month' => 5, 'konfirmasi' => 'HAPUS',
            ])
            ->assertRedirect();

        $this->assertSame(0, DB::table('produksi_kebun_wb')->where('supply', 'Pembelian')->count());
        $this->assertSame(2, DB::table('produksi_kebun_wb')->where('supply', 'Kebun Sendiri')->count());
    }
}
