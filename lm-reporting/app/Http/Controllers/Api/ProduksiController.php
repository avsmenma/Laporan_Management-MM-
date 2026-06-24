<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProduksiController extends Controller
{
    use AuthorizesReportRequests;

    /** Ukuran tabel → [kolom blok "Bulan Ini" (s/d hari ini), kolom blok "S.D Bulan Ini"]. */
    private const MEASURES = [
        'tbs_diterima' => ['tbs_diterima_sdhari', 'tbs_diterima_sdbulan'],
        'tbs_diolah' => ['tbs_diolah_sdhari', 'tbs_diolah_sdbulan'],
        'restan_akhir' => ['sisa_akhir', 'sisa_akhir'],
        'minyak_sawit' => ['ms_sdhari', 'ms_sdbulan'],
        'inti_sawit' => ['is_sdhari', 'is_sdbulan'],
    ];

    /** Urutan tabel pada output (restan_awal turunan disisipkan paling depan). */
    private const TABLE_ORDER = ['restan_awal', 'tbs_diterima', 'tbs_diolah', 'restan_akhir', 'minyak_sawit', 'inti_sawit'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $dates = DB::table('produksi_pks')
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        if ($dates === []) {
            return response()->json(['dates' => [], 'date' => null, 'plants' => [], 'kebun' => [], 'tables' => [], 'ringkasan' => ['bi' => [], 'sd' => []]]);
        }

        $date = (string) $request->query('date', $dates[0]);
        if (! in_array($date, $dates, true)) {
            $date = $dates[0];
        }

        $rows = DB::table('produksi_pks')->whereDate('posting_date', $date)->get();

        // Plant (kolom): distinct, urut natural.
        $plants = $rows->pluck('plant_code')->filter()->unique()->values()->all();
        sort($plants, SORT_NATURAL);
        $plantDesc = [];
        foreach ($rows as $r) {
            $plantDesc[$r->plant_code] = $plantDesc[$r->plant_code] ?? (string) $r->plant_desc;
        }

        // Kebun (baris): 5E* natural dahulu, lalu sisanya (PHTG/PLSM/PLS/5F..) ikut urutan kemunculan.
        $kebunNama = [];
        $first5e = [];
        $firstOther = [];
        foreach ($rows as $r) {
            $k = (string) $r->kebun_code;
            if ($k === '' || isset($kebunNama[$k])) {
                continue;
            }
            $kebunNama[$k] = (string) $r->nama_kebun;
            if (preg_match('/^5E/i', $k)) {
                $first5e[] = $k;
            } else {
                $firstOther[] = $k;
            }
        }
        sort($first5e, SORT_NATURAL);
        $kebun = array_merge($first5e, $firstOther);

        // Matriks mentah: $mat[measure][block][kebun][plant].
        $mat = [];
        foreach (array_keys(self::MEASURES) as $m) {
            $mat[$m] = ['bi' => [], 'sd' => []];
        }
        foreach ($rows as $r) {
            $k = (string) $r->kebun_code;
            $p = (string) $r->plant_code;
            foreach (self::MEASURES as $m => $cols) {
                foreach (['bi' => 0, 'sd' => 1] as $b => $ci) {
                    $mat[$m][$b][$k][$p] = ($mat[$m][$b][$k][$p] ?? 0.0) + (float) $r->{$cols[$ci]};
                }
            }
        }

        // Restan Awal turunan = Diolah + Restan Akhir − Diterima (per blok).
        $mat['restan_awal'] = ['bi' => [], 'sd' => []];
        foreach (['bi', 'sd'] as $b) {
            foreach ($kebun as $k) {
                foreach ($plants as $p) {
                    $mat['restan_awal'][$b][$k][$p] =
                        ($mat['tbs_diolah'][$b][$k][$p] ?? 0)
                        + ($mat['restan_akhir'][$b][$k][$p] ?? 0)
                        - ($mat['tbs_diterima'][$b][$k][$p] ?? 0);
                }
            }
        }

        $tables = [];
        foreach (self::TABLE_ORDER as $m) {
            $tables[$m] = $this->buildTable($mat[$m], $kebun, $kebunNama, $plants);
        }

        return response()->json([
            'dates' => $dates,
            'date' => $date,
            'plants' => array_map(fn ($p) => ['code' => $p, 'desc' => $plantDesc[$p] ?? ''], $plants),
            'kebun' => array_map(fn ($k) => ['code' => $k, 'nama' => $kebunNama[$k] ?? ''], $kebun),
            'tables' => $tables,
            'ringkasan' => $this->buildRingkasan($tables, $plants),
        ]);
    }

    /**
     * Susun satu tabel: baris per kebun (dua blok) + baris Grand Total.
     * Kuantitas dibulatkan 0 desimal saat emit (round-of-sum: dijumlah dulu lalu dibulatkan).
     *
     * @param  array<string, array<string, array<string, float>>>  $blocks  [block][kebun][plant]
     * @param  array<int, string>  $kebun
     * @param  array<string, string>  $kebunNama
     * @param  array<int, string>  $plants
     */
    private function buildTable(array $blocks, array $kebun, array $kebunNama, array $plants): array
    {
        $colTot = ['bi' => array_fill_keys($plants, 0.0), 'sd' => array_fill_keys($plants, 0.0)];
        $grand = ['bi' => 0.0, 'sd' => 0.0];
        $out = ['rows' => [], 'grand' => ['bi' => [], 'sd' => []]];

        foreach ($kebun as $k) {
            $row = ['kebun' => $k, 'nama' => $kebunNama[$k] ?? '', 'bi' => [], 'sd' => []];
            foreach (['bi', 'sd'] as $b) {
                $rt = 0.0;
                foreach ($plants as $p) {
                    $v = (float) ($blocks[$b][$k][$p] ?? 0);
                    $row[$b][$p] = round($v);
                    $rt += $v;
                    $colTot[$b][$p] += $v;
                }
                $row[$b]['grand'] = round($rt);
                $grand[$b] += $rt;
            }
            $out['rows'][] = $row;
        }

        foreach (['bi', 'sd'] as $b) {
            foreach ($plants as $p) {
                $out['grand'][$b][$p] = round($colTot[$b][$p]);
            }
            $out['grand'][$b]['grand'] = round($grand[$b]);
        }

        return $out;
    }

    /**
     * Ringkasan per plant (+ JLH), dua blok. Memakai grand (col total) tiap tabel.
     * Rendemen = ukuran / TBS Olah × 100 (IFERROR→0); JLH dihitung dari total JLH.
     */
    private function buildRingkasan(array $tables, array $plants): array
    {
        $ring = ['bi' => [], 'sd' => []];
        foreach (['bi', 'sd'] as $b) {
            $sum = ['restan_awal' => 0.0, 'tbs_masuk' => 0.0, 'tbs_olah' => 0.0, 'ms' => 0.0, 'is' => 0.0];
            foreach ($plants as $p) {
                $ra = (float) $tables['restan_awal']['grand'][$b][$p];
                $masuk = (float) $tables['tbs_diterima']['grand'][$b][$p];
                $olah = (float) $tables['tbs_diolah']['grand'][$b][$p];
                $ms = (float) $tables['minyak_sawit']['grand'][$b][$p];
                $is = (float) $tables['inti_sawit']['grand'][$b][$p];
                $rms = $olah > 0 ? $ms / $olah * 100 : 0.0;
                $ris = $olah > 0 ? $is / $olah * 100 : 0.0;
                $ring[$b][$p] = [
                    'restan_awal' => $ra, 'tbs_masuk' => $masuk, 'tbs_olah' => $olah,
                    'restan_akhir' => $ra + $masuk - $olah, 'ms' => $ms, 'is' => $is, 'jumlah' => $ms + $is,
                    'rend_ms' => round($rms, 2), 'rend_is' => round($ris, 2), 'rend_total' => round($rms + $ris, 2),
                ];
                $sum['restan_awal'] += $ra;
                $sum['tbs_masuk'] += $masuk;
                $sum['tbs_olah'] += $olah;
                $sum['ms'] += $ms;
                $sum['is'] += $is;
            }
            $olahJ = $sum['tbs_olah'];
            $rmsJ = $olahJ > 0 ? $sum['ms'] / $olahJ * 100 : 0.0;
            $risJ = $olahJ > 0 ? $sum['is'] / $olahJ * 100 : 0.0;
            $ring[$b]['JLH'] = [
                'restan_awal' => $sum['restan_awal'], 'tbs_masuk' => $sum['tbs_masuk'], 'tbs_olah' => $olahJ,
                'restan_akhir' => $sum['restan_awal'] + $sum['tbs_masuk'] - $olahJ,
                'ms' => $sum['ms'], 'is' => $sum['is'], 'jumlah' => $sum['ms'] + $sum['is'],
                'rend_ms' => round($rmsJ, 2), 'rend_is' => round($risJ, 2), 'rend_total' => round($rmsJ + $risJ, 2),
            ];
        }

        return $ring;
    }
}
