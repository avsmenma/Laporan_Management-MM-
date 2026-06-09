<?php

namespace Database\Seeders;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestDataMei2026Seeder extends Seeder
{
    /**
     * Seed data sample dari workbook Mei 2026 untuk testing LM13 & LM14.
     * Target: Kebun Danau Salak (5E11) periode 5 (Mei 2026).
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

        $this->command->info("✓ Batch {$batch->code} created (ID: {$batch->id})");

        // 2. Import data dari workbook Kebun Sawit Mei 2026
        $workbookPath = base_path('../docs/reference/Lampiran_LM_Kebun_Sawit_Mei_2026.xlsx');

        if (! file_exists($workbookPath)) {
            $this->command->warn("⚠ Workbook tidak ditemukan: {$workbookPath}");
            $this->command->info('  Silakan import manual via UI atau letakkan file di docs/reference/');

            return;
        }

        $importService = app(SpreadsheetImportService::class);

        // Import DB WBS (sheet "DB WBS")
        $this->command->info('Importing DB WBS...');
        try {
            $result = $importService->import('wbs', $batch, $workbookPath);
            $this->command->info("  ✓ DB WBS: {$result->rowsInserted} baris imported");
        } catch (\Exception $e) {
            $this->command->error("  ✗ DB WBS failed: {$e->getMessage()}");
        }

        // Import DB BTL (sheet "DB BTL")
        $this->command->info('Importing DB BTL...');
        try {
            $result = $importService->import('btl', $batch, $workbookPath);
            $this->command->info("  ✓ DB BTL: {$result->rowsInserted} baris imported");
        } catch (\Exception $e) {
            $this->command->error("  ✗ DB BTL failed: {$e->getMessage()}");
        }

        // Import Alokasi Produksi (sheet "Alokasi")
        $this->command->info('Importing Alokasi Produksi...');
        try {
            $result = $importService->import('alokasi_produksi', $batch, $workbookPath);
            $this->command->info("  ✓ Alokasi Produksi: {$result->rowsInserted} baris imported");
        } catch (\Exception $e) {
            $this->command->error("  ✗ Alokasi Produksi failed: {$e->getMessage()}");
        }

        // Import Alokasi Areal (sheet "Alokasi" blok III)
        $this->command->info('Importing Alokasi Areal...');
        try {
            $result = $importService->import('alokasi_areal', $batch, $workbookPath);
            $this->command->info("  ✓ Alokasi Areal: {$result->rowsInserted} baris imported");
        } catch (\Exception $e) {
            $this->command->error("  ✗ Alokasi Areal failed: {$e->getMessage()}");
        }

        // Import Budget RKAP & RKO (jika ada sheet terpisah, atau buat dummy)
        $this->command->info('Creating dummy budget data...');
        $this->seedDummyBudget($batch);

        // Import Realisasi Tahun Lalu (dummy untuk 2025)
        $this->command->info('Creating dummy tahun lalu data...');
        $this->seedDummyTahunLalu($batch);

        $this->command->info('');
        $this->command->info('✓ Data sample Mei 2026 berhasil di-seed.');
        $this->command->info('  Siap untuk generate LM14 & LM13 untuk Kebun Danau Salak (5E11).');
    }

    /**
     * Seed dummy budget RKAP & RKO untuk testing (nilai sample).
     */
    private function seedDummyBudget(Batch $batch): void
    {
        // Ambil beberapa kode dari template LM14 untuk buat budget sample
        $templateCodes = DB::table('lm_template_row')
            ->where('report_type', 'LM14')
            ->where('komoditi', 'KS')
            ->whereNotNull('kode')
            ->where('row_type', 'detail')
            ->pluck('kode')
            ->take(20);

        foreach ($templateCodes as $kode) {
            // RKAP
            DB::table('budget_rkap')->updateOrInsert(
                [
                    'year' => $batch->year,
                    'komoditi' => 'KS',
                    'plant_code' => '5E11',
                    'report_type' => 'LM14',
                    'kode' => $kode,
                ],
                ['nilai' => rand(5000000, 50000000)] // sudah × 1000
            );

            // RKO
            DB::table('budget_rko')->updateOrInsert(
                [
                    'year' => $batch->year,
                    'komoditi' => 'KS',
                    'plant_code' => '5E11',
                    'report_type' => 'LM14',
                    'kode' => $kode,
                ],
                ['nilai' => rand(5000000, 50000000)] // sudah × 1000
            );
        }

        $this->command->info("  ✓ Budget: {$templateCodes->count()} kode × 2 (RKAP & RKO)");
    }

    /**
     * Seed dummy realisasi tahun lalu (2025) untuk testing.
     */
    private function seedDummyTahunLalu(Batch $batch): void
    {
        $templateCodes = DB::table('lm_template_row')
            ->where('report_type', 'LM14')
            ->where('komoditi', 'KS')
            ->whereNotNull('kode')
            ->where('row_type', 'detail')
            ->pluck('kode')
            ->take(20);

        foreach ($templateCodes as $kode) {
            DB::table('realisasi_tahun_lalu')->updateOrInsert(
                [
                    'year' => $batch->year - 1, // 2025
                    'komoditi' => 'KS',
                    'plant_code' => '5E11',
                    'report_type' => 'LM14',
                    'kode' => $kode,
                    'period' => $batch->month,
                ],
                ['nilai' => rand(3000000, 30000000)]
            );
        }

        $this->command->info("  ✓ Tahun Lalu: {$templateCodes->count()} kode periode {$batch->month}");
    }
}
