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

        // Filter opsional: kolom = nilai (exact) atau LIKE (contains). Hanya kolom
        // yang benar-benar ada yang diterima; nilai selalu di-bind (parameterized).
        $filterColumn = (string) $request->query('column', '');
        $filterOp = $request->query('op') === 'eq' ? 'eq' : 'contains';
        $filterValue = (string) $request->query('value', '');
        $applyFilter = $filterColumn !== '' && in_array($filterColumn, $columns, true) && $filterValue !== '';

        $query = DB::table($table);
        if ($applyFilter) {
            $filterOp === 'eq'
                ? $query->where($filterColumn, $filterValue)
                : $query->where($filterColumn, 'like', '%'.$filterValue.'%');
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
        return collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->filter(fn ($name) => is_string($name) && ! str_starts_with($name, 'migrations'))
            ->sort()
            ->values()
            ->all();
    }

    private function clampPerPage(int $perPage): int
    {
        if ($perPage < 10) {
            return 10;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
