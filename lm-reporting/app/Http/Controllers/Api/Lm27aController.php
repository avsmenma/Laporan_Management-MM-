<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data tabel LM-27A (Perhitungan Laba/Rugi) — halaman /laba-rugi/lm27a.
 *
 * Tahap ini mengisi blok PENJUALAN baris "Lokal" dari sumber yang SAMA dengan
 * LM-34 (`penjualan_produk`, ekspor GL SAP) sehingga angkanya pasti sama dengan
 * /laba-rugi/penjualan tab LM 34 kolom "Jumlah Nilai Penjualan → Realisasi →
 * s.d Bulan ini" (LM-27A memang laporan kumulatif 1 Januari s/d bulan filter):
 *   Kelapa Sawit = Jumlah TBS + Jumlah A (CPO + Inti Sawit)
 *   Karet        = Jumlah C (Lump)
 *   Jumlah       = Jumlah Lokal ( A + B + C )
 * Pemetaan baris→sumber diambil dari Lm34Controller supaya satu sumber kebenaran.
 *
 * Belum ada sumber (dirender '-' di UI): baris Ekspor (template LM-34 tidak punya
 * seksi ekspor), Perubahan Nilai Wajar Aset Biologis, serta seluruh blok Harga
 * Pokok Penjualan, Biaya Usaha, dan seterusnya.
 */
class Lm27aController extends Controller
{
    use AuthorizesReportRequests;

    /**
     * Kunci baris LM-34 yang menyusun tiap kolom budidaya pada baris "Lokal".
     *
     * TBS masuk Kelapa Sawit — di LM-34 TBS berdiri di luar "Jumlah A", tetapi
     * "Jumlah Lokal" tetap menjumlahnya. Dibuktikan sel G10 LM-27A.xlsx (Juni 2026):
     * 1.097.058.874.647 = 14.854.548.687 (Jumlah TBS) + 1.082.204.325.960 (Jumlah A).
     */
    private const LOKAL_BUDIDAYA = [
        'ks' => ['jml_tbs', 'jml_a'],
        'kr' => ['jml_c'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $periods = DB::table('penjualan_produk')
            ->select('year', DB::raw('period AS month'))->distinct()
            ->orderByDesc('year')->orderByDesc('period')
            ->get()->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null, 'values' => [],
            ]);
        }

        // Tanpa parameter → adopsi periode terbaru. Dengan parameter → persis
        // periode itu; bulan tanpa data → nilai 0 (semua sel '-'), TIDAK diam-diam
        // jatuh ke bulan lain.
        $year = $request->query('year');
        $month = $request->query('month');
        if ($year === null || $month === null) {
            $year = $periods[0]['year'];
            $month = $periods[0]['month'];
        }
        $year = (int) $year;
        $month = (int) $month;

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'values' => ['lokal' => $this->lokal($year, $month)],
        ]);
    }

    /**
     * Nilai penjualan lokal s.d bulan terpilih per kolom budidaya.
     * Nilai GL tersimpan kredit (negatif) → dibalik tanda agar positif seperti
     * Excel; nota debit/koreksi tetap mengurangi (bukan nilai mutlak).
     *
     * @return array<string, float>
     */
    private function lokal(int $year, int $month): array
    {
        $out = [];
        foreach (self::LOKAL_BUDIDAYA as $col => $lm34Keys) {
            $keys = [];
            foreach ($lm34Keys as $k) {
                $keys = array_merge($keys, Lm34Controller::detailKeysOf($k));
            }
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                $out[$col] = 0.0;

                continue;
            }

            $q = DB::table('penjualan_produk')
                ->where('year', $year)->where('period', '<=', $month);
            Lm34Controller::applySourceFilter($q, $keys);
            $sum = (float) $q->sum('amount');
            $out[$col] = $sum == 0.0 ? 0.0 : -$sum;
        }

        return $out;
    }
}
