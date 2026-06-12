<?php

namespace App\Domain\Admin;

use App\Models\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layanan hapus data (khusus Admin): per bulan, per tahun, atau seluruh data.
 * Menghapus data laporan & impor yang terkait, lalu batch periode bersangkutan.
 */
class DataPurgeService
{
    /** Tabel yang berelasi ke batch (punya kolom batch_id). */
    private const BATCH_TABLES = [
        'report_lm13', 'report_lm14', 'report_lm16',
        'pks_biaya', 'pks_produksi', 'alokasi_produksi',
        'db_wbs_raw', 'db_gc', 'db_ohc',
        'import_upload_logs',
    ];

    /** Tabel anggaran/areal yang dikunci per-tahun (tanpa batch_id). */
    private const YEAR_TABLES = ['budget_rkap', 'budget_rko', 'alokasi_areal'];

    /**
     * @return array<string, int> jumlah baris terhapus per tabel
     */
    public function purgeByMonth(int $year, int $month): array
    {
        return DB::transaction(function () use ($year, $month) {
            $batchIds = Batch::query()->where('year', $year)->where('month', $month)->pluck('id')->all();
            $counts = $this->deleteBatchScoped($batchIds);

            // realisasi tahun lalu terkunci per tahun + period (bulan)
            if (Schema::hasTable('realisasi_tahun_lalu')) {
                $counts['realisasi_tahun_lalu'] = DB::table('realisasi_tahun_lalu')
                    ->where('year', $year)->where('period', $month)->delete();
            }

            $counts['batch'] = Batch::query()->whereIn('id', $batchIds)->delete();

            return $this->clean($counts);
        });
    }

    /**
     * @return array<string, int>
     */
    public function purgeByYear(int $year): array
    {
        return DB::transaction(function () use ($year) {
            $batchIds = Batch::query()->where('year', $year)->pluck('id')->all();
            $counts = $this->deleteBatchScoped($batchIds);

            foreach (self::YEAR_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $counts[$table] = DB::table($table)->where('year', $year)->delete();
                }
            }

            if (Schema::hasTable('realisasi_tahun_lalu')) {
                $counts['realisasi_tahun_lalu'] = DB::table('realisasi_tahun_lalu')->where('year', $year)->delete();
            }

            $counts['batch'] = Batch::query()->where('year', $year)->delete();

            return $this->clean($counts);
        });
    }

    /**
     * @return array<string, int>
     */
    public function purgeAll(): array
    {
        return DB::transaction(function () {
            $counts = [];
            $tables = array_merge(self::BATCH_TABLES, self::YEAR_TABLES, ['realisasi_tahun_lalu']);

            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $counts[$table] = DB::table($table)->delete();
                }
            }

            $counts['batch'] = DB::table('batch')->delete();

            return $this->clean($counts);
        });
    }

    /**
     * @param  array<int, int>  $batchIds
     * @return array<string, int>
     */
    private function deleteBatchScoped(array $batchIds): array
    {
        $counts = [];
        if ($batchIds === []) {
            return $counts;
        }

        foreach (self::BATCH_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'batch_id')) {
                $counts[$table] = DB::table($table)->whereIn('batch_id', $batchIds)->delete();
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function clean(array $counts): array
    {
        return array_filter($counts, fn ($n) => $n > 0);
    }
}
