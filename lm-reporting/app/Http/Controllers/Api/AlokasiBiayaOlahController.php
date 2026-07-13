<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman "Alokasi Biaya Olah" (Pabrik). Fase ini hanya menyediakan KERANGKA
 * tabel (daftar periode, kolom PKS, baris Kebun) untuk 4 tab: Summary, Biaya
 * Pengolahan, Biaya Overhead, Biaya Depresiasi. Nilai/perhitungan menyusul.
 *
 * Sumber kerangka: produksi_cpo_inti (matriks Kebun × PKS per periode).
 */
class AlokasiBiayaOlahController extends Controller
{
    use AuthorizesReportRequests;

    /**
     * Urutan kolom PKS mengikuti file acuan (Sheet2 CONTOH PRODUKSI PKS V2):
     * Pagun, Parba, Panga, Papar, Pakem, Papam, Papel, Pasam, Palpi.
     */
    private const PLANT_ORDER = ['5F01', '5F04', '5F07', '5F08', '5F09', '5F14', '5F15', '5F21', '5F22'];

    private const PLANT_SHORT = [
        '5F01' => 'Pagun', '5F04' => 'Parba', '5F07' => 'Panga', '5F08' => 'Papar',
        '5F09' => 'Pakem', '5F14' => 'Papam', '5F15' => 'Papel', '5F21' => 'Pasam', '5F22' => 'Palpi',
    ];

    /**
     * Baris pool biaya (paling atas tiap tab) ditarik dari subtotal LM16 kolom "Olah"
     * (report_lm16.bi_olah) per unit PKS. template_id = baris subtotal LM16:
     *   570 = "Jumlah Biaya Pengolahan", 592 = "Jumlah Biaya Overhead",
     *   593 = "Biaya Depresiasi (Penyusutan)".
     * Fase ini baru mengisi Biaya Pengolahan; overhead/depresiasi menyusul.
     */
    private const POOL_LM16_TEMPLATE = [
        'pengolahan' => 570,
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $periodsRaw = DB::table('produksi_cpo_inti')
            ->select('year', 'month')->distinct()
            ->orderByDesc('year')->orderByDesc('month')
            ->get();

        $periods = $periodsRaw->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null, 'plants' => [], 'kebun' => [],
            ]);
        }

        $defYear = $periods[0]['year'];
        $defMonth = $periods[0]['month'];
        $year = (int) $request->query('year', $defYear);
        $month = (int) $request->query('month', $defMonth);

        $exists = collect($periods)->contains(fn ($p) => $p['year'] === $year && $p['month'] === $month);
        if (! $exists) {
            $year = $defYear;
            $month = $defMonth;
        }

        $rows = DB::table('produksi_cpo_inti')
            ->where('year', $year)->where('month', $month)
            ->get();

        // Kolom PKS: urut acuan dahulu, lalu sisanya (bila ada) mengikuti kemunculan.
        $present = $rows->pluck('plant_code')->filter()->unique()->all();
        $ordered = array_values(array_filter(self::PLANT_ORDER, fn ($p) => in_array($p, $present, true)));
        foreach ($present as $p) {
            if (! in_array($p, $ordered, true)) {
                $ordered[] = $p;
            }
        }
        $plantShortByCode = [];
        foreach ($rows as $r) {
            $plantShortByCode[$r->plant_code] = $plantShortByCode[$r->plant_code] ?? ($r->plant_short ?: null);
        }
        $plants = array_map(fn ($p) => [
            'code' => $p,
            'name' => self::PLANT_SHORT[$p] ?? ($plantShortByCode[$p] ?? $p),
        ], $ordered);

        // Baris Kebun: 5E* natural dahulu, lalu PHTG/PLSM/lainnya ikut urutan kemunculan.
        $nama = [];
        $first5e = [];
        $others = [];
        foreach ($rows as $r) {
            $k = (string) $r->kebun_code;
            if ($k === '' || isset($nama[$k])) {
                continue;
            }
            $nama[$k] = (string) $r->nama_kebun;
            if (preg_match('/^5E/i', $k)) {
                $first5e[] = $k;
            } else {
                $others[] = $k;
            }
        }
        sort($first5e, SORT_NATURAL);
        $kebunCodes = array_merge($first5e, $others);
        $kebun = array_map(fn ($k) => ['code' => $k, 'nama' => $nama[$k] ?? ''], $kebunCodes);

        // Baris pool biaya per tab (LM16 kolom Olah). Butuh batch periode ini.
        $batchId = DB::table('batch')->where('year', $year)->where('month', $month)->value('id');
        $pools = [];
        foreach (self::POOL_LM16_TEMPLATE as $tab => $templateId) {
            $pools[$tab] = $this->poolFromLm16($batchId !== null ? (int) $batchId : null, $templateId, $plants);
        }

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'plants' => $plants,
            'kebun' => $kebun,
            'pools' => $pools,
        ]);
    }

    /**
     * Nilai pool biaya per PKS = report_lm16.bi_olah (kolom "Olah") pada baris subtotal
     * $templateId, untuk $batchId. Mengembalikan map {plant_code: nilai, ..., grand: total}.
     * Nilai null bila tak ada batch/data (ditampilkan '-' di UI).
     *
     * @param  array<int, array{code:string, name:string}>  $plants
     * @return array<string, float|null>
     */
    private function poolFromLm16(?int $batchId, int $templateId, array $plants): array
    {
        $out = [];
        foreach ($plants as $p) {
            $out[$p['code']] = null;
        }
        $out['grand'] = null;

        if ($batchId === null) {
            return $out;
        }

        $vals = DB::table('report_lm16 as r')
            ->join('ref_unit as u', 'u.id', '=', 'r.unit_id')
            ->where('r.batch_id', $batchId)
            ->where('r.template_id', $templateId)
            ->pluck('r.bi_olah', 'u.code');

        $grand = 0.0;
        $any = false;
        foreach ($plants as $p) {
            if ($vals->has($p['code'])) {
                $v = (float) $vals->get($p['code']);
                $out[$p['code']] = $v;
                $grand += $v;
                $any = true;
            }
        }
        $out['grand'] = $any ? $grand : null;

        return $out;
    }
}
