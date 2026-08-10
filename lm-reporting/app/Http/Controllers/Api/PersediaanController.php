<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman Persediaan Akhir Hasil Produksi (/laba-rugi/persediaan).
 *
 * Kolom PRODUKSI (Kg) tab KELAPA SAWIT dari `produksi_pks` (tabel yang sama
 * dengan halaman /produksi/pks):
 * - Minyak Sawit       ← Σ ms_sdbulan
 * - Inti Sawit         ← Σ is_sdbulan
 * - Tandan Buah Segar  ← Σ tbs_diterima_sdbulan (TBS DITERIMA, keputusan user)
 * per plant PKS pada SNAPSHOT posting_date TERBARU di bulan filter — angkanya
 * identik dengan baris Grand Total blok "S.D Bulan Ini" halaman produksi.
 *
 * Kolom PENGELUARAN → PENJUALAN (Kg) dari `penjualan_produk` (tabel halaman
 * /laba-rugi/penjualan): Σ qty s.d bulan filter per plant — identik blok
 * "SD BULAN INI → QTY" tab PLANT. Minyak Sawit ← material CPO, Inti Sawit ←
 * material INTI SAWIT (keputusan user); TBS tidak diisi.
 *
 * Kolom lain belum ada sumber → frontend menampilkan '-'.
 */
class PersediaanController extends Controller
{
    use AuthorizesReportRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $dates = DB::table('produksi_pks')
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        // Periode = (tahun, bulan), diwakili tanggal posting TERBARU bulan itu
        // (snapshot s.d bulan paling lengkap) — pola sama dengan ProduksiController.
        $latestByPeriod = [];
        foreach ($dates as $d) {
            $key = substr($d, 0, 7); // "YYYY-MM"
            $latestByPeriod[$key] = $latestByPeriod[$key] ?? $d;
        }
        $periods = [];
        foreach (array_keys($latestByPeriod) as $key) {
            [$yy, $mm] = explode('-', $key);
            $periods[] = ['year' => (int) $yy, 'month' => (int) $mm];
        }
        usort($periods, fn ($a, $b) => ($b['year'] <=> $a['year']) ?: ($b['month'] <=> $a['month']));

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null, 'date' => null,
                'produksi' => null, 'penjualan' => null,
            ]);
        }

        // Tanpa parameter → adopsi periode terbaru. Dengan parameter → persis
        // periode itu; bulan tanpa snapshot → produksi null (semua sel '-'),
        // TIDAK diam-diam jatuh ke bulan lain.
        $year = $request->query('year');
        $month = $request->query('month');
        if ($year === null || $month === null) {
            $year = $periods[0]['year'];
            $month = $periods[0]['month'];
        }
        $year = (int) $year;
        $month = (int) $month;
        $date = $latestByPeriod[sprintf('%04d-%02d', $year, $month)] ?? null;

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'date' => $date,
            'produksi' => $date === null ? null : $this->produksiPerPlant($date),
            // Penjualan TIDAK bergantung snapshot produksi — periode tanpa
            // snapshot tetap bisa punya angka penjualan.
            'penjualan' => $this->penjualanPerPlant($year, $month),
        ]);
    }

    /**
     * Σ kolom *_sdbulan per plant PKS pada snapshot $date, dibulatkan 0 desimal
     * setelah dijumlah (round-of-sum) — identik Grand Total halaman produksi.
     *
     * @return array{ms: array<string, float>, is: array<string, float>, tbs: array<string, float>}
     */
    private function produksiPerPlant(string $date): array
    {
        $rows = DB::table('produksi_pks')
            ->selectRaw('plant_code, SUM(ms_sdbulan) AS ms, SUM(is_sdbulan) AS inti, SUM(tbs_diterima_sdbulan) AS tbs')
            ->whereDate('posting_date', $date)
            ->whereNotNull('plant_code')
            ->where('plant_code', '!=', '')
            ->groupBy('plant_code')
            ->get();

        $out = ['ms' => [], 'is' => [], 'tbs' => []];
        foreach ($rows as $r) {
            $p = (string) $r->plant_code;
            $out['ms'][$p] = round((float) $r->ms);
            $out['is'][$p] = round((float) $r->inti);
            $out['tbs'][$p] = round((float) $r->tbs);
        }

        return $out;
    }

    /** material_desc penjualan_produk → kunci produk halaman Persediaan. */
    private const PENJUALAN_MATERIAL = ['ms' => 'CPO', 'is' => 'INTI SAWIT'];

    /**
     * Σ qty penjualan s.d bulan filter per plant (4 digit awal profit center) —
     * identik blok "SD BULAN INI → QTY" tab PLANT halaman Penjualan. Qty GL
     * tersimpan kredit (negatif) → dibalik agar positif seperti di layar.
     *
     * @return array{ms: array<string, float>, is: array<string, float>}
     */
    private function penjualanPerPlant(int $year, int $month): array
    {
        $rows = DB::table('penjualan_produk')
            ->where('year', $year)
            ->where('period', '<=', $month)
            ->whereIn('material_desc', array_values(self::PENJUALAN_MATERIAL))
            // Dipotong jadi 4 digit di PHP (bukan LEFT/SUBSTR di SQL) agar sama
            // hasilnya di MySQL maupun SQLite yang dipakai test.
            ->selectRaw('material_desc, profit_center, SUM(qty) AS qty')
            ->groupBy('material_desc', 'profit_center')
            ->get();

        $out = ['ms' => [], 'is' => []];
        foreach ($rows as $r) {
            $key = array_search((string) $r->material_desc, self::PENJUALAN_MATERIAL, true);
            if ($key === false) {
                continue;
            }
            $plant = substr((string) $r->profit_center, 0, 4);
            $out[$key][$plant] = ($out[$key][$plant] ?? 0) + round(-1 * (float) $r->qty);
        }

        return $out;
    }
}
