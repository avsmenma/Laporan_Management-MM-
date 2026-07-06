<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Penjelajah isi database (read-only). Tujuan: melihat data tabel tanpa membuka
 * MySQL. Anti-lag: SELALU server-side pagination (LIMIT/OFFSET) — tidak pernah
 * memuat seluruh tabel ke memori/halaman. Nama tabel & kolom divalidasi terhadap
 * daftar nyata (whitelist) agar tidak bisa dipakai untuk injeksi.
 */
class DatabaseViewerController extends Controller
{
    private const MAX_PER_PAGE = 200;

    private const DEFAULT_PER_PAGE = 50;

    /** Hanya tabel sumber dan report yang boleh dijelajahi. */
    private const ALLOWED_TABLES = [
        'db_wbs_raw',
        'db_wbs_tahun_lalu',
        'db_ohc',
        'db_gc',
        'pks_biaya',
        'pks_produksi',
        'produksi_kebun_wb',
        'produksi_pks',
        'alokasi_produksi',
        'areal_blok',
        'alokasi_areal',
        'report_lm13',
        'report_lm14',
        'report_lm16',
    ];

    /** Batas panjang teks sel yang dikirim ke frontend (jaga DOM tetap ringan). */
    private const MAX_CELL_LEN = 300;

    public function index(): View
    {
        return view('admin.database', [
            'tables' => $this->tableList(),
        ]);
    }

    /**
     * Data satu tabel ter-paginasi (JSON, dipanggil via AJAX).
     */
    public function data(Request $request): JsonResponse
    {
        $tables = $this->tableNames();
        $table = (string) $request->query('table', '');

        if (! in_array($table, $tables, true)) {
            return response()->json(['success' => false, 'message' => 'Tabel tidak dikenal.'], 404);
        }

        $columns = Schema::getColumnListing($table);
        if ($columns === []) {
            return response()->json(['success' => false, 'message' => 'Tabel tidak memiliki kolom.'], 404);
        }

        $perPage = $this->clampPerPage((int) $request->query('per_page', self::DEFAULT_PER_PAGE));
        $page = max(1, (int) $request->query('page', 1));
        $year = $request->query('year');
        $month = $request->query('month');

        $query = DB::table($table);

        // Filter berdasarkan year dan month jika tabel memiliki batch_id
        if (in_array('batch_id', $columns, true) && ($year || $month)) {
            $query->join('batch', $table.'.batch_id', '=', 'batch.id');
            if ($year) {
                $query->where('batch.year', '=', (int) $year);
            }
            if ($month) {
                $query->where('batch.month', '=', (int) $month);
            }
            // Select hanya kolom dari tabel asli, bukan kolom dari batch
            $query->select($table.'.*');
        }

        // COUNT(*) pada tabel besar (ratusan ribu baris) berat, jadi hanya dihitung
        // saat tabel/filter berubah (count=1). Navigasi antar-halaman memakai count=0
        // dan frontend mempertahankan total yang sudah diketahui — agar tidak lag.
        $wantCount = $request->query('count', '1') !== '0';
        $total = $wantCount ? (clone $query)->count() : null;
        $lastPage = $total !== null ? max(1, (int) ceil($total / $perPage)) : null;
        if ($lastPage !== null) {
            $page = min($page, $lastPage);
        }

        // Urutkan berdasarkan primary key bila ada (pagination stabil), jika tidak
        // ada pakai kolom pertama.
        $orderColumn = in_array('id', $columns, true) ? 'id' : $columns[0];

        $rows = $query
            ->orderBy($orderColumn)
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row) => $this->formatRow((array) $row, $columns))
            ->all();

        $count = count($rows);
        $from = $count === 0 ? 0 : (($page - 1) * $perPage) + 1;

        // List tahun dan bulan yang tersedia (jika tabel punya batch_id)
        $hasBatch = in_array('batch_id', $columns, true);
        $years = $hasBatch ? $this->availableYears() : [];
        $months = $hasBatch ? $this->availableMonths() : [];

        return response()->json([
            'success' => true,
            'table' => $table,
            'columns' => $columns,
            'rows' => $rows,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'page' => $page,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $count === 0 ? 0 : $from + $count - 1,
            ],
            'filters' => [
                'has_batch' => $hasBatch,
                'years' => $years,
                'months' => $months,
                'selected_year' => $year,
                'selected_month' => $month,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columns
     * @return array<int, string|null>
     */
    private function formatRow(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            if ($value === null) {
                $out[] = null;

                continue;
            }

            $text = (string) $value;
            $out[] = mb_strlen($text) > self::MAX_CELL_LEN
                ? mb_substr($text, 0, self::MAX_CELL_LEN).'…'
                : $text;
        }

        return $out;
    }

    /**
     * Daftar tabel + perkiraan jumlah baris (dari information_schema, instan —
     * tidak menghitung COUNT(*) tiap tabel yang bisa berat).
     *
     * @return array<int, array{name: string, rows: int}>
     */
    private function tableList(): array
    {
        $names = $this->tableNames();
        $approx = collect(DB::select(
            'SELECT TABLE_NAME AS t, TABLE_ROWS AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [DB::getDatabaseName()]
        ))->pluck('c', 't');

        return collect($names)
            ->map(fn (string $name) => ['name' => $name, 'rows' => (int) ($approx[$name] ?? 0)])
            ->all();
    }

    /**
     * Nama semua tabel di database aktif (whitelist untuk validasi).
     *
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        $existing = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->all();

        // Hanya tabel yang di-whitelist DAN benar-benar ada di database.
        return array_values(array_intersect(self::ALLOWED_TABLES, $existing));
    }

    private function clampPerPage(int $perPage): int
    {
        if ($perPage < 10) {
            return 10;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * Daftar tahun yang tersedia di tabel batch.
     *
     * @return array<int, int>
     */
    private function availableYears(): array
    {
        return DB::table('batch')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->all();
    }

    /**
     * Daftar bulan yang tersedia di tabel batch.
     *
     * @return array<int, array{value: int, label: string}>
     */
    private function availableMonths(): array
    {
        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return DB::table('batch')
            ->distinct()
            ->orderBy('month')
            ->pluck('month')
            ->map(fn ($m) => ['value' => $m, 'label' => $monthNames[$m] ?? "Bulan $m"])
            ->all();
    }
}
