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

    /** Tabel kuantitas (restan_awal turunan disisipkan paling depan). */
    private const QTY_TABLES = ['restan_awal', 'tbs_diterima', 'tbs_diolah', 'restan_akhir', 'minyak_sawit', 'inti_sawit'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $dates = DB::table('produksi_pks')
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        if ($dates === []) {
            return response()->json(['periods' => [], 'year' => null, 'month' => null, 'date' => null, 'plants' => [], 'kebun' => [], 'tables' => [], 'ringkasan' => ['bi' => [], 'sd' => []]]);
        }

        // Periode = (tahun, bulan). Tiap periode diwakili oleh tanggal posting
        // TERBARU di bulan itu (snapshot s.d bulan paling lengkap). Karena $dates
        // urut menurun, kemunculan pertama tiap "Y-m" adalah tanggal terbaru.
        $latestByPeriod = [];
        foreach ($dates as $d) {
            $key = substr($d, 0, 7); // "YYYY-MM"
            if (! isset($latestByPeriod[$key])) {
                $latestByPeriod[$key] = $d;
            }
        }
        $periods = [];
        foreach (array_keys($latestByPeriod) as $key) {
            [$yy, $mm] = explode('-', $key);
            $periods[] = ['year' => (int) $yy, 'month' => (int) $mm];
        }
        usort($periods, fn ($a, $b) => ($b['year'] <=> $a['year']) ?: ($b['month'] <=> $a['month']));

        $defYear = $periods[0]['year'];
        $defMonth = $periods[0]['month'];
        $year = (int) $request->query('year', $defYear);
        $month = (int) $request->query('month', $defMonth);
        $pkey = sprintf('%04d-%02d', $year, $month);
        if (! isset($latestByPeriod[$pkey])) {
            // Periode diminta tak punya data → jatuh ke periode terbaru.
            $year = $defYear;
            $month = $defMonth;
            $pkey = sprintf('%04d-%02d', $year, $month);
        }
        $date = $latestByPeriod[$pkey];

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
        foreach (self::QTY_TABLES as $m) {
            $tables[$m] = $this->buildTable($mat[$m], $kebun, $kebunNama, $plants);
        }
        // Dua tabel rendemen (%) per kebun×plant = ukuran / TBS Diolah × 100.
        $tables['rend_minyak'] = $this->buildRendemenTable($mat['minyak_sawit'], $mat['tbs_diolah'], $kebun, $kebunNama, $plants);
        $tables['rend_inti'] = $this->buildRendemenTable($mat['inti_sawit'], $mat['tbs_diolah'], $kebun, $kebunNama, $plants);

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'date' => $date,
            'plants' => array_map(fn ($p) => ['code' => $p, 'desc' => $plantDesc[$p] ?? ''], $plants),
            'kebun' => array_map(fn ($k) => ['code' => $k, 'nama' => $kebunNama[$k] ?? ''], $kebun),
            'tables' => $tables,
            'ringkasan' => $this->buildRingkasan($tables, $mat, $kebun, $plants),
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
     * Tabel rendemen (%) = ukuran / TBS Diolah × 100 per sel (IFERROR→0).
     * Total baris/kolom/grand = rasio dari JUMLAH MENTAH (Σnum / Σden × 100),
     * bukan rata-rata sel — konsisten round-of-sum. Dibulatkan 2 desimal saat emit.
     * Bentuk keluaran identik buildTable agar bisa dipakai pivot frontend yang sama.
     *
     * @param  array<string, array<string, array<string, float>>>  $num  [block][kebun][plant]
     * @param  array<string, array<string, array<string, float>>>  $den  [block][kebun][plant]
     * @param  array<int, string>  $kebun
     * @param  array<string, string>  $kebunNama
     * @param  array<int, string>  $plants
     */
    private function buildRendemenTable(array $num, array $den, array $kebun, array $kebunNama, array $plants): array
    {
        $colNum = ['bi' => array_fill_keys($plants, 0.0), 'sd' => array_fill_keys($plants, 0.0)];
        $colDen = ['bi' => array_fill_keys($plants, 0.0), 'sd' => array_fill_keys($plants, 0.0)];
        $gNum = ['bi' => 0.0, 'sd' => 0.0];
        $gDen = ['bi' => 0.0, 'sd' => 0.0];
        $out = ['rows' => [], 'grand' => ['bi' => [], 'sd' => []]];

        $pct = fn (float $n, float $d): float => $d > 0 ? round($n / $d * 100, 2) : 0.0;

        foreach ($kebun as $k) {
            $row = ['kebun' => $k, 'nama' => $kebunNama[$k] ?? '', 'bi' => [], 'sd' => []];
            foreach (['bi', 'sd'] as $b) {
                $rNum = 0.0;
                $rDen = 0.0;
                foreach ($plants as $p) {
                    $n = (float) ($num[$b][$k][$p] ?? 0);
                    $d = (float) ($den[$b][$k][$p] ?? 0);
                    $row[$b][$p] = $pct($n, $d);
                    $rNum += $n;
                    $rDen += $d;
                    $colNum[$b][$p] += $n;
                    $colDen[$b][$p] += $d;
                }
                $row[$b]['grand'] = $pct($rNum, $rDen);
                $gNum[$b] += $rNum;
                $gDen[$b] += $rDen;
            }
            $out['rows'][] = $row;
        }

        foreach (['bi', 'sd'] as $b) {
            foreach ($plants as $p) {
                $out['grand'][$b][$p] = $pct($colNum[$b][$p], $colDen[$b][$p]);
            }
            $out['grand'][$b]['grand'] = $pct($gNum[$b], $gDen[$b]);
        }

        return $out;
    }

    /**
     * Ringkasan per plant (+ JLH), dua blok.
     *
     * Kolom kuantitas (restan_awal, tbs_masuk, tbs_olah, restan_akhir, ms, is, jumlah)
     * memakai grand (col total) tiap tabel (sudah dibulatkan saat emit).
     *
     * Rendemen WAJIB round-of-sum murni: pembilang/penyebut memakai total kolom MENTAH
     * (belum dibulatkan) yang dijumlah ulang dari matriks. Rendemen = ukuran / TBS Olah × 100
     * (IFERROR→0); JLH rendemen dihitung dari total JLH mentah. Dibulatkan 2 desimal saat emit.
     *
     * @param  array<string, array<string, array<string, array<string, float>>>>  $mat  [measure][block][kebun][plant]
     * @param  array<int, string>  $kebun
     * @param  array<int, string>  $plants
     */
    private function buildRingkasan(array $tables, array $mat, array $kebun, array $plants): array
    {
        $ring = ['bi' => [], 'sd' => []];
        foreach (['bi', 'sd'] as $b) {
            $sumJ = ['olah' => 0.0, 'ms' => 0.0, 'is' => 0.0];
            foreach ($plants as $p) {
                // Kuantitas (emit): pakai grand tabel yang sudah dibulatkan.
                $ra = (float) $tables['restan_awal']['grand'][$b][$p];
                $masuk = (float) $tables['tbs_diterima']['grand'][$b][$p];
                $olah = (float) $tables['tbs_diolah']['grand'][$b][$p];
                $ms = (float) $tables['minyak_sawit']['grand'][$b][$p];
                $is = (float) $tables['inti_sawit']['grand'][$b][$p];

                // Rendemen: total kolom MENTAH (round-of-sum murni).
                $olahRaw = $this->colTotalRaw($mat, 'tbs_diolah', $b, $kebun, $p);
                $msRaw = $this->colTotalRaw($mat, 'minyak_sawit', $b, $kebun, $p);
                $isRaw = $this->colTotalRaw($mat, 'inti_sawit', $b, $kebun, $p);
                $rms = $olahRaw > 0 ? $msRaw / $olahRaw * 100 : 0.0;
                $ris = $olahRaw > 0 ? $isRaw / $olahRaw * 100 : 0.0;

                $ring[$b][$p] = [
                    'restan_awal' => $ra, 'tbs_masuk' => $masuk, 'tbs_olah' => $olah,
                    'restan_akhir' => $ra + $masuk - $olah, 'ms' => $ms, 'is' => $is, 'jumlah' => $ms + $is,
                    'rend_ms' => round($rms, 2), 'rend_is' => round($ris, 2), 'rend_total' => round($rms + $ris, 2),
                ];

                $sumJ['olah'] += $olahRaw;
                $sumJ['ms'] += $msRaw;
                $sumJ['is'] += $isRaw;
            }

            // JLH kuantitas (emit): jumlah dari nilai per-plant yang sudah dibulatkan.
            $jra = $jmasuk = $jolah = $jms = $jis = 0.0;
            foreach ($plants as $p) {
                $jra += $ring[$b][$p]['restan_awal'];
                $jmasuk += $ring[$b][$p]['tbs_masuk'];
                $jolah += $ring[$b][$p]['tbs_olah'];
                $jms += $ring[$b][$p]['ms'];
                $jis += $ring[$b][$p]['is'];
            }

            // JLH rendemen: dari total JLH mentah.
            $rmsJ = $sumJ['olah'] > 0 ? $sumJ['ms'] / $sumJ['olah'] * 100 : 0.0;
            $risJ = $sumJ['olah'] > 0 ? $sumJ['is'] / $sumJ['olah'] * 100 : 0.0;
            $ring[$b]['JLH'] = [
                'restan_awal' => $jra, 'tbs_masuk' => $jmasuk, 'tbs_olah' => $jolah,
                'restan_akhir' => $jra + $jmasuk - $jolah,
                'ms' => $jms, 'is' => $jis, 'jumlah' => $jms + $jis,
                'rend_ms' => round($rmsJ, 2), 'rend_is' => round($risJ, 2), 'rend_total' => round($rmsJ + $risJ, 2),
            ];
        }

        return $ring;
    }

    /**
     * Total kolom (per plant) MENTAH untuk satu measure & blok: Σ atas semua kebun.
     *
     * @param  array<string, array<string, array<string, array<string, float>>>>  $mat
     * @param  array<int, string>  $kebun
     */
    private function colTotalRaw(array $mat, string $measure, string $b, array $kebun, string $p): float
    {
        $sum = 0.0;
        foreach ($kebun as $k) {
            $sum += (float) ($mat[$measure][$b][$k][$p] ?? 0);
        }

        return $sum;
    }
}
