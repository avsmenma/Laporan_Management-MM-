<?php

namespace Database\Seeders;

use App\Models\Batch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MinimalTestDataSeeder extends Seeder
{
    /**
     * Seed minimal data untuk testing LM13 & LM14.
     * Kebun: Danau Salak (5E11), Periode: Mei 2026 (period=5).
     */
    public function run(): void
    {
        // 1. Buat batch Mei 2026
        $batch = Batch::query()->firstOrCreate(
            ['year' => 2026, 'month' => 5],
            [
                'code' => 'Batch #2026-05',
                'status' => 'draft',
                'processed_at' => now(),
            ]
        );

        $this->command->info("✓ Batch {$batch->code} (ID: {$batch->id})");

        // 2. Seed DB WBS (biaya langsung) untuk Danau Salak
        $this->seedDbWbs($batch);

        // 3. Seed DB BTL (biaya staf) untuk Danau Salak
        $this->seedDbBtl($batch);

        // 4. Seed Alokasi Produksi (TBS, CPO, Kernel)
        $this->seedAlokasiProduksi($batch);

        // 5. Seed Alokasi Areal (luas TM)
        $this->seedAlokasiAreal($batch);

        // 6. Seed Budget RKAP & RKO
        $this->seedBudget($batch);

        // 7. Seed Realisasi Tahun Lalu
        $this->seedTahunLalu($batch);

        $this->command->info('✓ Minimal test data berhasil di-seed.');
        $this->command->info('  Siap generate: php artisan report:generate --type=LM14 --batch=1 --unit=5E11');
    }

    private function seedDbWbs(Batch $batch): void
    {
        DB::table('db_wbs')->where('batch_id', $batch->id)->where('plant_code', '5E11')->delete();

        // Sample biaya tanaman untuk Danau Salak
        $activities = [
            '41-01' => 'TM - PEMEL JALAN MANUAL - ACCESS ROAD',
            '43-01' => 'TM - PENGENDALIAN GULMA MANUAL',
            '45-01' => 'TM - PEMUPUKAN NPK',
            '48-01' => 'TM - PANEN MANUAL',
            '16-01' => 'TM - ANGKUT TBS KE PABRIK',
        ];

        $count = 0;
        foreach ($activities as $kode => $nama) {
            for ($period = 1; $period <= 5; $period++) {
                DB::table('db_wbs')->insert([
                    'batch_id' => $batch->id,
                    'komoditi' => 'KS',
                    'plant_code' => '5E11',
                    'period' => $period,
                    'aktivitas' => $kode,
                    'job_name' => $nama,
                    'cost_element' => '51100001',
                    'cost_element_desc' => 'Biaya Tenaga Kerja',
                    'klasifikasi_code' => '1',
                    'nilai' => rand(5000000, 15000000),
                    'fisik' => null,
                ]);
                $count++;
            }
        }

        $this->command->info("  ✓ DB WBS: {$count} baris");
    }

    private function seedDbBtl(Batch $batch): void
    {
        DB::table('db_btl')->where('batch_id', $batch->id)->where('plant_code', '5E11')->delete();

        // Gaji staf overhead
        $ccCodes = ['BT01', 'BT02', 'BT05'];
        $count = 0;

        foreach ($ccCodes as $cc) {
            for ($period = 1; $period <= 5; $period++) {
                DB::table('db_btl')->insert([
                    'batch_id' => $batch->id,
                    'komoditi' => 'KS',
                    'plant_code' => '5E11',
                    'unit_kerja' => 'Kebun Danau Salak',
                    'period' => $period,
                    'kode_cc' => $cc,
                    'co_object_name' => "Overhead {$cc}",
                    'cost_element' => '51100001',
                    'cost_element_name' => 'Gaji Staf',
                    'klasifikasi_code' => '1',
                    'nilai' => rand(3000000, 8000000),
                ]);
                $count++;
            }
        }

        $this->command->info("  ✓ DB BTL: {$count} baris");
    }

    private function seedAlokasiProduksi(Batch $batch): void
    {
        DB::table('alokasi_produksi')->where('batch_id', $batch->id)->where('kebun_code', '5E11')->delete();

        $produkList = [
            'Stok Awal TBS' => 50000,
            'TBS Diterima' => 1200000,
            'TBS Dijual' => 100000,
            'TBS Olah' => 1100000,
            'CPO' => 220000,
            'Kernel' => 55000,
            'TBS Restan Loading Ramp' => 50000,
        ];

        $count = 0;
        foreach ($produkList as $produk => $jumlahPerBulan) {
            for ($month = 1; $month <= 5; $month++) {
                $pabrikCode = in_array($produk, ['TBS Olah', 'CPO', 'Kernel']) ? '5F01' : null;

                DB::table('alokasi_produksi')->insert([
                    'batch_id' => $batch->id,
                    'year' => $batch->year,
                    'month' => $month,
                    'kebun_code' => '5E11',
                    'pabrik_code' => $pabrikCode,
                    'produk' => $produk,
                    'jumlah' => $jumlahPerBulan + rand(-10000, 10000),
                ]);
                $count++;
            }
        }

        $this->command->info("  ✓ Alokasi Produksi: {$count} baris");
    }

    private function seedAlokasiAreal(Batch $batch): void
    {
        DB::table('alokasi_areal')->updateOrInsert(
            ['year' => $batch->year, 'kebun_code' => '5E11'],
            [
                'real_thn_lalu' => 4500.00,
                'real_thn_ini' => 4600.00,
                'rko' => 4600.00,
                'rkap' => 4700.00,
            ]
        );

        $this->command->info('  ✓ Alokasi Areal: 1 baris (5E11)');
    }

    private function seedBudget(Batch $batch): void
    {
        // Ambil kode dari template LM14
        $templateCodes = DB::table('lm_template_row')
            ->where('report_type', 'LM14')
            ->where('komoditi', 'KS')
            ->whereNotNull('kode')
            ->where('row_type', 'detail')
            ->pluck('kode')
            ->take(30);

        foreach ($templateCodes as $kode) {
            DB::table('budget_rkap')->updateOrInsert(
                ['year' => $batch->year, 'komoditi' => 'KS', 'plant_code' => '5E11', 'report_type' => 'LM14', 'kode' => $kode],
                ['nilai' => rand(10000000, 50000000)]
            );

            DB::table('budget_rko')->updateOrInsert(
                ['year' => $batch->year, 'komoditi' => 'KS', 'plant_code' => '5E11', 'report_type' => 'LM14', 'kode' => $kode],
                ['nilai' => rand(10000000, 50000000)]
            );
        }

        $this->command->info("  ✓ Budget: {$templateCodes->count()} kode × 2");
    }

    private function seedTahunLalu(Batch $batch): void
    {
        $templateCodes = DB::table('lm_template_row')
            ->where('report_type', 'LM14')
            ->where('komoditi', 'KS')
            ->whereNotNull('kode')
            ->where('row_type', 'detail')
            ->pluck('kode')
            ->take(30);

        foreach ($templateCodes as $kode) {
            for ($period = 1; $period <= 5; $period++) {
                DB::table('realisasi_tahun_lalu')->updateOrInsert(
                    ['year' => 2025, 'komoditi' => 'KS', 'plant_code' => '5E11', 'report_type' => 'LM14', 'kode' => $kode, 'period' => $period],
                    ['nilai' => rand(5000000, 30000000)]
                );
            }
        }

        $this->command->info("  ✓ Tahun Lalu: {$templateCodes->count()} kode × 5 periode");
    }
}
