<?php

namespace App\Domain\Admin;

use App\Models\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Layanan hapus data (khusus Admin). Dua mode:
 *  - Global: per bulan / per tahun / semua — menghapus seluruh data terkait + batch.
 *  - Per target: hanya satu sumber/tabel (mis. "Produksi Kebun — Kebun Sendiri"),
 *    berguna saat salah impor / ingin mengganti data tanpa menghapus yang lain.
 */
class DataPurgeService
{
    /** Tabel yang berelasi ke batch (punya kolom batch_id). */
    private const BATCH_TABLES = [
        'report_lm13', 'report_lm14', 'report_lm16',
        'pks_biaya', 'pks_produksi', 'alokasi_produksi',
        'db_wbs_raw', 'db_gc', 'db_ohc',
        'areal_blok',
        'import_upload_logs',
    ];

    /** Tabel anggaran/areal yang dikunci per-tahun (tanpa batch_id). */
    private const YEAR_TABLES = ['budget_rkap', 'budget_rko', 'budget_source', 'alokasi_areal'];

    /** Tabel produksi snapshot harian (dikunci via posting_date, tanpa batch). */
    private const POSTING_DATE_TABLES = ['produksi_pks', 'produksi_kebun_wb'];

    /** Tabel statis lintas-tahun yang hanya dikosongkan saat "Hapus Semua". */
    private const ALL_ONLY_TABLES = ['realisasi_tahun_lalu', 'db_wbs_tahun_lalu'];

    /**
     * Katalog target hapus selektif: tiap target = satu/lebih (tabel, cakupan, filter).
     * `group` dipakai untuk <optgroup> di UI. `scope`:
     *  - batch        : dihapus via batch_id (bulan→tahun+bulan, tahun→tahun, semua→semua)
     *  - year         : kolom year (bulan & tahun → per tahun; semua → semua)
     *  - posting_date : kolom posting_date (bulan→tahun+bulan, tahun→tahun, semua→semua)
     *  - year_period  : kolom year + period(=bulan)
     *  - all_only     : hanya saat cakupan "semua"
     *
     * @return array<string, array{label: string, group: string, tables: array<int, array{table: string, scope: string, where?: array<string, string>}>}>
     */
    public static function targets(): array
    {
        return [
            'produksi_kebun_sendiri' => [
                'label' => 'Kebun Sendiri', 'group' => 'Produksi Kebun',
                'tables' => [['table' => 'produksi_kebun_wb', 'scope' => 'posting_date', 'where' => ['supply' => 'Kebun Sendiri']]],
            ],
            'produksi_kebun_pembelian' => [
                'label' => 'Pembelian', 'group' => 'Produksi Kebun',
                'tables' => [['table' => 'produksi_kebun_wb', 'scope' => 'posting_date', 'where' => ['supply' => 'Pembelian']]],
            ],
            'produksi_kebun_all' => [
                'label' => 'Semua (Kebun Sendiri + Pembelian)', 'group' => 'Produksi Kebun',
                'tables' => [['table' => 'produksi_kebun_wb', 'scope' => 'posting_date']],
            ],
            'produksi_pks' => [
                'label' => 'Produksi PKS', 'group' => 'Produksi PKS',
                'tables' => [['table' => 'produksi_pks', 'scope' => 'posting_date']],
            ],
            'areal' => [
                'label' => 'Areal (Blok + Alokasi)', 'group' => 'Areal',
                'tables' => [
                    ['table' => 'areal_blok', 'scope' => 'batch'],
                    ['table' => 'alokasi_areal', 'scope' => 'year'],
                ],
            ],
            'realisasi_wbs' => [
                'label' => 'DB WBS', 'group' => 'Realisasi (impor mentah)',
                'tables' => [['table' => 'db_wbs_raw', 'scope' => 'batch']],
            ],
            'realisasi_ohc' => [
                'label' => 'DB OHC', 'group' => 'Realisasi (impor mentah)',
                'tables' => [['table' => 'db_ohc', 'scope' => 'batch']],
            ],
            'realisasi_gc' => [
                'label' => 'DB GC', 'group' => 'Realisasi (impor mentah)',
                'tables' => [['table' => 'db_gc', 'scope' => 'batch']],
            ],
            'anggaran' => [
                'label' => 'RKO / RKAP', 'group' => 'Anggaran',
                'tables' => [
                    ['table' => 'budget_rko', 'scope' => 'year'],
                    ['table' => 'budget_rkap', 'scope' => 'year'],
                    ['table' => 'budget_source', 'scope' => 'year'],
                ],
            ],
            'pabrik_sumber' => [
                'label' => 'Sumber Pabrik (Biaya, Produksi, Alokasi)', 'group' => 'Pabrik (LM16)',
                'tables' => [
                    ['table' => 'pks_biaya', 'scope' => 'batch'],
                    ['table' => 'pks_produksi', 'scope' => 'batch'],
                    ['table' => 'alokasi_produksi', 'scope' => 'batch'],
                ],
            ],
            'pembelian_tbs' => [
                'label' => 'Pembelian TBS', 'group' => 'Pembelian TBS',
                'tables' => [['table' => 'pembelian_tbs', 'scope' => 'year_period']],
            ],
            'penjualan_produk' => [
                'label' => 'Penjualan Produk', 'group' => 'Laba Rugi',
                'tables' => [['table' => 'penjualan_produk', 'scope' => 'year_period']],
            ],
            'laporan' => [
                'label' => 'Hasil Laporan (LM13 / LM14 / LM16)', 'group' => 'Hasil Laporan',
                'tables' => [
                    ['table' => 'report_lm13', 'scope' => 'batch'],
                    ['table' => 'report_lm14', 'scope' => 'batch'],
                    ['table' => 'report_lm16', 'scope' => 'batch'],
                ],
            ],
        ];
    }

    /**
     * Hapus satu target (tanpa menyentuh batch atau tabel lain).
     *
     * @return array<string, int>
     */
    public function purgeTarget(string $target, string $mode, ?int $year, ?int $month): array
    {
        $def = self::targets()[$target] ?? null;
        if ($def === null) {
            return [];
        }

        return DB::transaction(function () use ($def, $mode, $year, $month) {
            $counts = [];
            foreach ($def['tables'] as $entry) {
                $counts[$entry['table']] = ($counts[$entry['table']] ?? 0)
                    + $this->deleteTargetEntry($entry, $mode, $year, $month);
            }

            return $this->clean($counts);
        });
    }

    /**
     * @param  array{table: string, scope: string, where?: array<string, string>}  $entry
     */
    private function deleteTargetEntry(array $entry, string $mode, ?int $year, ?int $month): int
    {
        $table = $entry['table'];
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $where = $entry['where'] ?? [];

        $q = fn () => DB::table($table)->where($where);

        return match ($entry['scope']) {
            'batch' => $this->deleteBatchTable($table, $mode, $year, $month, $where),
            'year' => $mode === 'all'
                ? $q()->delete()
                : ($year !== null ? $q()->where('year', $year)->delete() : 0),
            'posting_date' => match ($mode) {
                'all' => $q()->delete(),
                'year' => $year !== null ? $q()->whereYear('posting_date', $year)->delete() : 0,
                default => ($year !== null && $month !== null)
                    ? $q()->whereYear('posting_date', $year)->whereMonth('posting_date', $month)->delete() : 0,
            },
            'year_period' => match ($mode) {
                'all' => $q()->delete(),
                'year' => $year !== null ? $q()->where('year', $year)->delete() : 0,
                default => ($year !== null && $month !== null)
                    ? $q()->where('year', $year)->where('period', $month)->delete() : 0,
            },
            'all_only' => $mode === 'all' ? $q()->delete() : 0,
            default => 0,
        };
    }

    /**
     * @param  array<string, string>  $where
     */
    private function deleteBatchTable(string $table, string $mode, ?int $year, ?int $month, array $where = []): int
    {
        if (! Schema::hasColumn($table, 'batch_id')) {
            return 0;
        }
        if ($mode === 'all') {
            return DB::table($table)->where($where)->delete();
        }
        $batchIds = $this->batchIds($mode, $year, $month);
        if ($batchIds === []) {
            return 0;
        }

        return DB::table($table)->where($where)->whereIn('batch_id', $batchIds)->delete();
    }

    /**
     * @return array<int, int>
     */
    private function batchIds(string $mode, ?int $year, ?int $month): array
    {
        $q = Batch::query();
        if ($year !== null) {
            $q->where('year', $year);
        }
        if ($mode === 'month' && $month !== null) {
            $q->where('month', $month);
        }

        return $q->pluck('id')->all();
    }

    /**
     * @return array<string, int> jumlah baris terhapus per tabel
     */
    public function purgeByMonth(int $year, int $month): array
    {
        return DB::transaction(function () use ($year, $month) {
            $batchIds = Batch::query()->where('year', $year)->where('month', $month)->pluck('id')->all();
            $counts = $this->deleteBatchScoped($batchIds);

            foreach (self::POSTING_DATE_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $counts[$table] = DB::table($table)
                        ->whereYear('posting_date', $year)->whereMonth('posting_date', $month)->delete();
                }
            }

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

            foreach (self::POSTING_DATE_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    $counts[$table] = DB::table($table)->whereYear('posting_date', $year)->delete();
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
            $tables = array_merge(self::BATCH_TABLES, self::YEAR_TABLES, self::POSTING_DATE_TABLES, self::ALL_ONLY_TABLES);

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
