<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom `period` (bulan 1-12) ke budget_rko & budget_rkap.
     *
     * Sebelumnya RKO/RKAP disimpan TAHUNAN (tanpa periode) sehingga nilainya muncul
     * sama di semua bulan. Kolom ini membuat anggaran bisa per-bulan, selaras dengan
     * kolom `period` yang sudah ada di budget_source (sumber drill-down).
     *
     * NULLABLE & makna NULL = "anggaran tahunan/berlaku semua bulan" (kompatibel mundur:
     * baris lama tanpa periode tetap tampil di tiap bulan). Baris ber-periode difilter
     * `= bulan` (kolom bulan ini) atau `<= bulan` (kolom s.d. bulan ini) di mesin laporan.
     */
    public function up(): void
    {
        foreach (['budget_rkap' => 'idx_rkap', 'budget_rko' => 'idx_rko'] as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->tinyInteger('period')->nullable()->after('kode');
                $blueprint->dropIndex($index);
                $blueprint->index(
                    ['year', 'komoditi', 'plant_code', 'report_type', 'kode', 'period'],
                    $index,
                );
            });
        }
    }

    public function down(): void
    {
        foreach (['budget_rkap' => 'idx_rkap', 'budget_rko' => 'idx_rko'] as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
                $blueprint->dropColumn('period');
                $blueprint->index(
                    ['year', 'komoditi', 'plant_code', 'report_type', 'kode'],
                    $index,
                );
            });
        }
    }
};
