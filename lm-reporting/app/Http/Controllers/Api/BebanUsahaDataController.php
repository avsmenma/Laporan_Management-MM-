<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\BebanUsahaController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman Beban Administrasi (page=admin), Beban Ops Lainnya (page=bol) &
 * Beban Penjualan (page=penj).
 * Sumber: beban_usaha_gl (ekspor line-item GL SAP). Nilai per baris dikembalikan
 * SEJAJAR INDEKS dengan daftar baris di BebanUsahaController (satu sumber struktur).
 *
 * Aturan (tervalidasi selisih 0 vs workbook "LM BEBAN USAHA", kolom sd Bulan Mei 2026):
 *  - ADMIN : baris = SUM(amount) per Cost Element BPC Desc (persis label baris).
 *            Tab ADMI KS = Summary × %Proporsi ABS Sawit; ADMI KR = Summary ×
 *            %Proporsi ABS Karet — %Proporsi per bulan dari tab PROPORSI
 *            (beban_usaha_proporsi, input manual; % = nilai_proporsi/total_nilai).
 *            Kumulatif = Σ (nilai bulan × %proporsi bulan itu); bulan tanpa baris
 *            proporsi dianggap 0%.
 *  - PENJ  : baris = SUM(amount) per Klasifikasi LM Induk (persis label baris) —
 *            seluruh nilai masuk seksi 860.1 Kelapa Sawit (data = biaya penjualan
 *            CPO/PK); klasifikasi di luar peta → baris Lain - Lain Sawit; seksi
 *            Karet tetap kosong (tak ada sumber).
 *  - BOL   : baris = pemetaan Kodering→posisi baris; KSO dipecah per profit center
 *            (A119@5E12→KSO Kumai, @5E09→KSO Kembayan Noyan, @5E14→KSO Kebun Pamukan SDE;
 *            A124@5F11→Batubara Danau Salak). Kodering/profit center di luar peta →
 *            baris Lain - Lain. Kolom Regional Office = profit center 5R..; Kebun dan
 *            Pabrik = sisanya. Tab KARET = A119@5E12 (KSO Kumai) + A123@5F20 (PKR);
 *            KELAPA SAWIT = Summary − KARET.
 */
class BebanUsahaDataController extends Controller
{
    use AuthorizesReportRequests;

    /** Kodering BOL → posisi baris rincian (indeks 0-based pada daftar rincian). */
    public const BOL_DETAIL_BY_CODE = [
        'A154' => 4,   // Beban Rugi Penurunan Nilai Aset Tetap
        'A123' => 6,   // Biaya Operasional Pabrik Kebun / Diluar Harga Pokok
        'A136' => 8,   // Selisih Kas Opname/Gudang
        'A125' => 10,  // Denda Pajak/ Dampak Perhitungan TER PPh 21
        'A153' => 11,  // Koordinasi Pemda/Instansi Terkait/Iuran, Sumbangan, dan Rapat
        'A135' => 13,  // Coorporate Social Responsibility ( CSR )
        'A168' => 17,  // Lain - Lain
        'A109' => 25,  // Biaya Pengurusan Kebun Plasma
    ];

    /** Posisi baris Lain - Lain (tampungan kodering/profit center di luar peta). */
    public const BOL_DETAIL_LAIN = 17;

    /** A119 (KSO) → posisi baris KSO per prefiks profit center. */
    public const BOL_KSO_A119_BY_PC = [
        '5E12' => 5,   // Biaya KSO Kumai - CV. Murutuwu Putra
        '5E09' => 6,   // Biaya KSO Kembayan - CV Noyan Persada Jaya
        '5E14' => 10,  // Biaya KSO Kebun Pamukan - PT Sumber Daya Energi (SDE)
    ];

    /** A124 → posisi baris KSO Biaya Operasional Batubara Danau Salak (PT.MAS). */
    public const BOL_KSO_A124 = 2;

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $page = (string) $request->query('page');
        abort_unless(in_array($page, ['admin', 'bol', 'penj'], true), 422, 'Parameter page tidak dikenal.');
        $reportType = match ($page) {
            'admin' => 'ADMIN',
            'bol' => 'BOL',
            default => 'PENJ',
        };

        $periods = DB::table('beban_usaha_gl')
            ->where('report_type', $reportType)
            ->select('year', DB::raw('period AS month'))->distinct()
            ->orderByDesc('year')->orderByDesc('month')
            ->get()->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json(['periods' => [], 'year' => null, 'month' => null, 'values' => null]);
        }

        $year = $periods[0]['year'];
        $month = $periods[0]['month'];
        $reqYear = $request->integer('year');
        $reqMonth = $request->integer('month');
        if ($reqYear && $reqMonth) {
            foreach ($periods as $p) {
                if ($p['year'] === $reqYear && $p['month'] === $reqMonth) {
                    $year = $reqYear;
                    $month = $reqMonth;
                    break;
                }
            }
        }

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'values' => match ($page) {
                'admin' => $this->adminValues($year, $month),
                'bol' => $this->bolValues($year, $month),
                default => $this->penjValues($year, $month),
            },
        ]);
    }

    /**
     * Peta struktur baris Beban Penjualan (dipakai juga drill-down):
     * [0] meta per indeks {t, sec ('860.0' Karet | '860.1' Sawit), u},
     * [1] label rincian Sawit → indeks, [2] indeks subtotal per seksi, [3] indeks total.
     *
     * @return array{0: array<int, array{t: string, sec: ?string, u: string}>, 1: array<string, int>, 2: array<string, int>, 3: int}
     */
    public static function penjRowLayout(): array
    {
        $rows = BebanUsahaController::rowsBebanPenjualan();
        $sec = null;
        $meta = [];
        $sawitByLabel = [];
        $subtotals = [];
        $iTotal = -1;
        foreach ($rows as $i => $r) {
            $t = $r['t'] ?? 'detail';
            if ($t === 'header') {
                $sec = $r['k'];
            }
            $meta[$i] = ['t' => $t, 'sec' => $sec, 'u' => $r['u']];
            if ($t === 'subtotal') {
                $subtotals[(string) $sec] = $i;
            } elseif ($t === 'total') {
                $iTotal = $i;
            } elseif ($t === 'detail' && $sec === '860.1') {
                $sawitByLabel[$r['u']] = $i;
            }
        }

        return [$meta, $sawitByLabel, $subtotals, $iTotal];
    }

    /**
     * Nilai halaman Beban Penjualan (tab tunggal 'all'), sejajar indeks dengan
     * BebanUsahaController::rowsBebanPenjualan(). Tiap sel: {bln, sd, sdbl}.
     * Seluruh klasifikasi masuk seksi Kelapa Sawit; di luar peta → Lain - Lain.
     */
    private function penjValues(int $year, int $month): array
    {
        [$meta, $sawitByLabel, $subtotals, $iTotal] = self::penjRowLayout();
        $iLain = $sawitByLabel['Lain - Lain'];

        $tab = array_fill(0, count($meta), ['bln' => 0.0, 'sd' => 0.0, 'sdbl' => 0.0]);
        $agg = DB::table('beban_usaha_gl')
            ->where('report_type', 'PENJ')->where('year', $year)
            ->selectRaw('period, class_desc, SUM(amount) AS v')
            ->groupBy('period', 'class_desc')->get();
        foreach ($agg as $r) {
            $i = $sawitByLabel[trim((string) $r->class_desc)] ?? $iLain;
            $this->accumulate($tab[$i], (int) $r->period, $month, (float) $r->v);
        }

        // Subtotal per seksi = Σ rincian seksi itu; Jumlah Seluruh = Σ subtotal.
        foreach (['bln', 'sd', 'sdbl'] as $f) {
            $all = 0.0;
            foreach ($subtotals as $sec => $iSub) {
                $sum = 0.0;
                foreach ($meta as $i => $m) {
                    if ($m['t'] === 'detail' && $m['sec'] === $sec) {
                        $sum += $tab[$i][$f];
                    }
                }
                $tab[$iSub][$f] = $sum;
                $all += $sum;
            }
            $tab[$iTotal][$f] = $all;
        }

        return ['all' => $this->roundRows($tab)];
    }

    /**
     * Nilai halaman Beban Administrasi per tab, sejajar indeks dengan
     * BebanUsahaController::rowsBebanAdministrasi() (29 rincian + Jumlah +
     * Depresiasi + Total). Tiap sel: {bln, sd, sdbl}. Tab ks/kr = nilai summary
     * per periode × %Proporsi ABS Sawit/Karet bulan itu (tab PROPORSI);
     * bulan tanpa baris proporsi → 0%.
     */
    private function adminValues(int $year, int $month): array
    {
        $rows = BebanUsahaController::rowsBebanAdministrasi();
        $idxByLabel = [];
        foreach ($rows as $i => $r) {
            if (($r['t'] ?? 'detail') === 'detail') {
                $idxByLabel[$r['u']] = $i;
            }
        }
        $iLain = $idxByLabel['Lain-Lain'];
        $iJumlah = $this->findIndex($rows, 'subtotal');
        $iTotal = $this->findIndex($rows, 'total');
        $iDepre = $idxByLabel['Beban Depresiasi dan Amortisasi'];

        // %Proporsi per bulan: ks = ABS Sawit, kr = ABS Karet (nilai/total, aman 0).
        $pct = []; // [period => ['ks' => .., 'kr' => ..]]
        $prop = DB::table('beban_usaha_proporsi')->where('year', $year)
            ->get(['month', 'uraian', 'total_nilai', 'nilai_proporsi']);
        foreach ($prop as $r) {
            $total = (float) $r->total_nilai;
            $key = trim((string) $r->uraian) === 'ABS Karet' ? 'kr' : 'ks';
            $pct[(int) $r->month][$key] = $total == 0.0 ? 0.0 : (float) $r->nilai_proporsi / $total;
        }

        // Agregat per periode × klasifikasi (label di-TRIM saat impor).
        $mk = fn (): array => array_fill(0, count($rows), ['bln' => 0.0, 'sd' => 0.0, 'sdbl' => 0.0]);
        $tabs = ['summary' => $mk(), 'ks' => $mk(), 'kr' => $mk()];
        $agg = DB::table('beban_usaha_gl')
            ->where('report_type', 'ADMIN')->where('year', $year)
            ->selectRaw('period, class_desc, SUM(amount) AS v')
            ->groupBy('period', 'class_desc')->get();
        foreach ($agg as $r) {
            $i = $idxByLabel[trim((string) $r->class_desc)] ?? $iLain;
            $p = (int) $r->period;
            $v = (float) $r->v;
            $this->accumulate($tabs['summary'][$i], $p, $month, $v);
            $this->accumulate($tabs['ks'][$i], $p, $month, $v * ($pct[$p]['ks'] ?? 0.0));
            $this->accumulate($tabs['kr'][$i], $p, $month, $v * ($pct[$p]['kr'] ?? 0.0));
        }

        // Jumlah = Σ rincian (sebelum baris subtotal); Total = Jumlah + Depresiasi.
        foreach ($tabs as &$tab) {
            foreach (['bln', 'sd', 'sdbl'] as $f) {
                $jml = 0.0;
                for ($i = 0; $i < $iJumlah; $i++) {
                    $jml += $tab[$i][$f];
                }
                $tab[$iJumlah][$f] = $jml;
                $tab[$iTotal][$f] = $jml + $tab[$iDepre][$f];
            }
            $tab = $this->roundRows($tab);
        }
        unset($tab);

        return $tabs;
    }

    /**
     * Nilai halaman Beban Ops Lainnya per tab, sejajar indeks dengan
     * BebanUsahaController::rowsBolSummary()/rowsBolKsKr() (posisi baris identik).
     * Tiap sel: {ro, kp, bln, sd, sdbl} — ro/kp = pecahan Bulan Ini.
     */
    private function bolValues(int $year, int $month): array
    {
        $rows = BebanUsahaController::rowsBolSummary();
        $iJumlah = $this->findIndex($rows, 'subtotal');            // Jumlah rincian
        $iJumlahKso = $this->findIndex($rows, 'subtotal', $iJumlah + 1); // Jumlah Biaya KSO
        $iTotal = $this->findIndex($rows, 'total');
        $ksoBase = $iJumlah + 1;

        // Akumulator per periode × baris: [t]=total, [ro]=porsi RO, [k]=porsi karet, [kro]=karet RO.
        $per = []; // [period][rowIndex] => ['t'=>..,'ro'=>..,'k'=>..,'kro'=>..]
        $agg = DB::table('beban_usaha_gl')
            ->where('report_type', 'BOL')->where('year', $year)
            ->selectRaw('period, class_code, profit_center, SUM(amount) AS v')
            ->groupBy('period', 'class_code', 'profit_center')->get();
        foreach ($agg as $r) {
            $p = (int) $r->period;
            $code = trim((string) $r->class_code);
            $pc4 = substr((string) $r->profit_center, 0, 4);
            $v = (float) $r->v;

            if ($code === 'A119') {
                $pos = isset(self::BOL_KSO_A119_BY_PC[$pc4])
                    ? $ksoBase + self::BOL_KSO_A119_BY_PC[$pc4]
                    : self::BOL_DETAIL_LAIN;
            } elseif ($code === 'A124') {
                $pos = $ksoBase + self::BOL_KSO_A124;
            } else {
                $pos = self::BOL_DETAIL_BY_CODE[$code] ?? self::BOL_DETAIL_LAIN;
            }

            $isRo = str_starts_with((string) $r->profit_center, '5R');
            $isKaret = ($code === 'A119' && $pc4 === '5E12') || ($code === 'A123' && $pc4 === '5F20');

            $cell = &$per[$p][$pos];
            $cell ??= ['t' => 0.0, 'ro' => 0.0, 'k' => 0.0, 'kro' => 0.0];
            $cell['t'] += $v;
            $cell['ro'] += $isRo ? $v : 0.0;
            $cell['k'] += $isKaret ? $v : 0.0;
            $cell['kro'] += ($isKaret && $isRo) ? $v : 0.0;
            unset($cell);
        }

        $n = count($rows);
        $mk = fn (): array => array_fill(0, $n, ['ro' => 0.0, 'kp' => 0.0, 'bln' => 0.0, 'sd' => 0.0, 'sdbl' => 0.0]);
        $tabs = ['summary' => $mk(), 'ks' => $mk(), 'kr' => $mk()];

        foreach ($per as $p => $perRow) {
            foreach ($perRow as $i => $c) {
                $split = [
                    'summary' => [$c['t'], $c['ro']],
                    'kr' => [$c['k'], $c['kro']],
                    'ks' => [$c['t'] - $c['k'], $c['ro'] - $c['kro']],
                ];
                foreach ($split as $tab => [$v, $ro]) {
                    $this->accumulate($tabs[$tab][$i], $p, $month, $v);
                    if ($p === $month) {
                        $tabs[$tab][$i]['ro'] += $ro;
                        $tabs[$tab][$i]['kp'] += $v - $ro;
                    }
                }
            }
        }

        // Jumlah = Σ rincian; Jumlah KSO = Σ baris KSO; Total = Jumlah + Jumlah KSO.
        foreach ($tabs as &$tab) {
            foreach (['ro', 'kp', 'bln', 'sd', 'sdbl'] as $f) {
                $jml = 0.0;
                for ($i = 0; $i < $iJumlah; $i++) {
                    $jml += $tab[$i][$f];
                }
                $kso = 0.0;
                for ($i = $ksoBase; $i < $iJumlahKso; $i++) {
                    $kso += $tab[$i][$f];
                }
                $tab[$iJumlah][$f] = $jml;
                $tab[$iJumlahKso][$f] = $kso;
                $tab[$iTotal][$f] = $jml + $kso;
            }
            $tab = $this->roundRows($tab);
        }

        return $tabs;
    }

    /** Tambahkan nilai satu periode ke akumulator bln/sd/sdbl sesuai posisi bulan. */
    private function accumulate(array &$cell, int $period, int $month, float $v): void
    {
        if ($period === $month) {
            $cell['bln'] += $v;
        }
        if ($period <= $month) {
            $cell['sd'] += $v;
        }
        if ($period <= $month - 1) {
            $cell['sdbl'] += $v;
        }
    }

    /** Indeks baris pertama bertipe $type mulai dari $from. */
    private function findIndex(array $rows, string $type, int $from = 0): int
    {
        for ($i = $from, $n = count($rows); $i < $n; $i++) {
            if (($rows[$i]['t'] ?? 'detail') === $type) {
                return $i;
            }
        }

        abort(500, 'Struktur baris tidak dikenal.');
    }

    /** Bulatkan seluruh nilai baris ke rupiah utuh. */
    private function roundRows(array $rows): array
    {
        return array_map(
            fn (array $cell): array => array_map(fn ($v) => $v === null ? null : round((float) $v), $cell),
            $rows,
        );
    }
}
