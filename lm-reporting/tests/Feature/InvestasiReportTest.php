<?php

namespace Tests\Feature;

use App\Domain\Report\InvestasiService;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestasiReportTest extends TestCase
{
    use RefreshDatabase;

    private function batch(): Batch
    {
        return Batch::query()->create([
            'code' => 'Batch #2026-01',
            'year' => 2026,
            'month' => 1,
            'status' => 'final',
            'processed_at' => '2026-01-20 08:00:00',
        ]);
    }

    /**
     * Cari baris detail berdasarkan (plant, fase, tahun_tanam).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function detail(array $rows, string $plant, string $fase, int $tahun): array
    {
        foreach ($rows as $row) {
            if (($row['row_type'] ?? null) === 'detail'
                && $row['plant'] === $plant
                && $row['fase'] === $fase
                && (int) $row['tahun_tanam'] === $tahun) {
                return $row;
            }
        }

        $this->fail("Baris detail tidak ditemukan: {$plant} / {$fase} / {$tahun}");
    }

    public function test_rekap_menghitung_realisasi_pokok_dan_rasio(): void
    {
        $this->seed();
        $batch = $this->batch();

        DB::table('investasi_wbs')->insert([
            ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01', 'fase' => 'b. TBM-1', 'tahun_tanam' => 2025, 'period' => 1, 'nilai' => 100],
            ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01', 'fase' => 'b. TBM-1', 'tahun_tanam' => 2025, 'period' => 1, 'nilai' => 50],
            ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01', 'fase' => 'e. >TBM-3', 'tahun_tanam' => 2022, 'period' => 1, 'nilai' => 300],
        ]);

        DB::table('areal_blok')->insert([
            ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01', 'status_blok_petak' => 'TBM', 'tahun_tanam' => 2025, 'total_pokok' => 10, 'luas_ha' => 5],
        ]);

        $result = app(InvestasiService::class)->buildRekap($batch, null, 'KS');
        $rows = $result['rows'];

        // Kolom sesuai definisi.
        $this->assertSame('plant', $result['columns'][0]['key']);
        $this->assertSame('cap_sbi', $result['columns'][count($result['columns']) - 1]['key']);

        // Blok 3: b. TBM-1 2025 → real 150 (100+50), pokok 10, ha 5.
        $row = $this->detail($rows, '5E01', 'b. TBM-1', 2025);
        $this->assertEquals(150, $row['rbi_real']);
        $this->assertEquals(150, $row['rsbi_real']);
        $this->assertEquals(10, $row['rbi_pokok']);
        $this->assertEquals(5, $row['rbi_ha']);
        $this->assertEquals(15, $row['rbi_rp_pkk']);
        $this->assertEquals(30, $row['rbi_rp_ha']);
        $this->assertEquals(0, $row['kbi_rkap']);
        $this->assertEquals(0, $row['cap_bi']);

        // Blok 6: e. TBM-4 2022 ← investasi_wbs fase 'e. >TBM-3' 2022.
        $row2 = $this->detail($rows, '5E01', 'e. TBM-4', 2022);
        $this->assertEquals(300, $row2['rsbi_real']);

        // Subtotal blok 3 (b. TBM-1) memuat 150 dari 5E01.
        $subtotalBlock3 = null;
        $seenTbm1 = false;
        foreach ($rows as $row) {
            if (($row['row_type'] ?? null) === 'detail' && $row['fase'] === 'b. TBM-1') {
                $seenTbm1 = true;
            }
            if ($seenTbm1 && ($row['row_type'] ?? null) === 'subtotal') {
                $subtotalBlock3 = $row;
                break;
            }
        }
        $this->assertNotNull($subtotalBlock3);
        $this->assertSame('Jumh', $subtotalBlock3['fase']);
        $this->assertGreaterThanOrEqual(150, $subtotalBlock3['rbi_real']);
    }

    public function test_rekap2_menghitung_saldo_mutasi_aset(): void
    {
        $this->seed();
        $batch = $this->batch();

        $base = ['batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01', 'fase' => 'b. TBM-1', 'tahun_tanam' => 2025];

        // Insert per baris: kolom antar baris berbeda (bulk insert butuh kolom seragam).
        foreach ([
            ['klasifikasi' => 'Non Project', 'apc_start' => 1000],
            ['klasifikasi' => 'Borrowing', 'apc_start' => 200],
            ['acquisition' => 500],
            ['transfer' => 300, 'dk_flag' => 'D'],
            ['transfer' => 100, 'dk_flag' => 'K'],
            ['impair_pengurangan' => 40],
            ['reklas_debet' => 10, 'impair_awal' => 20],
        ] as $mut) {
            DB::table('investasi_asset')->insert(array_merge($base, $mut));
        }

        $result = app(InvestasiService::class)->buildRekap2($batch, null, 'KS');

        // 36 kolom.
        $this->assertCount(36, $result['columns']);

        $row = $this->detail($result['rows'], '5E01', 'b. TBM-1', 2025);

        // Saldo Awal.
        $this->assertEquals(200, $row['sa_borrowing']);
        $this->assertEquals(1000, $row['sa_murni']);
        $this->assertEquals(1200, $row['sa_jlh']);
        $this->assertEquals(30, $row['sa_impair']);
        $this->assertEquals(1230, $row['sa_total']);

        // Penambahan.
        $this->assertEquals(500, $row['pn_murni']);
        $this->assertEquals(300, $row['pn_reklas']);
        $this->assertEquals(800, $row['pn_jlh']);

        // Pengurangan.
        $this->assertEquals(100, $row['pg_reklas']);
        $this->assertEquals(40, $row['pg_impair']);
        $this->assertEquals(140, $row['pg_jlh']);

        // Saldo Akhir.
        $this->assertEquals(1900, $row['sk_murni']);
        $this->assertEquals(200, $row['sk_borrowing']);
        $this->assertEquals(2100, $row['sk_jlh']);
        $this->assertEquals(70, $row['sk_impair']);
        $this->assertEquals(2170, $row['sk_total']);
    }
}
