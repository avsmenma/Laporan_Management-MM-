<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\BebanUsahaController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman Beban Administrasi (page=admin) & Beban Ops Lainnya (page=bol).
 * Sumber: beban_usaha_gl (ekspor line-item GL SAP). Nilai per baris dikembalikan
 * SEJAJAR INDEKS dengan daftar baris di BebanUsahaController (satu sumber struktur).
 *
 * Aturan (tervalidasi selisih 0 vs workbook "LM BEBAN USAHA", kolom sd Bulan Mei 2026):
 *  - ADMIN : baris = SUM(amount) per Cost Element BPC Desc (persis label baris).
 *            Tab ADMI KS/KR SENGAJA tanpa nilai (semua sel '-') — logika alokasi
 *            proporsional lama dihapus; cara tampil baru menyusul.
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
    private const BOL_DETAIL_BY_CODE = [
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
    private const BOL_DETAIL_LAIN = 17;

    /** A119 (KSO) → posisi baris KSO per prefiks profit center. */
    private const BOL_KSO_A119_BY_PC = [
        '5E12' => 5,   // Biaya KSO Kumai - CV. Murutuwu Putra
        '5E09' => 6,   // Biaya KSO Kembayan - CV Noyan Persada Jaya
        '5E14' => 10,  // Biaya KSO Kebun Pamukan - PT Sumber Daya Energi (SDE)
    ];

    /** A124 → posisi baris KSO Biaya Operasional Batubara Danau Salak (PT.MAS). */
    private const BOL_KSO_A124 = 2;

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $page = (string) $request->query('page');
        abort_unless(in_array($page, ['admin', 'bol'], true), 422, 'Parameter page tidak dikenal.');
        $reportType = $page === 'admin' ? 'ADMIN' : 'BOL';

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
            'values' => $page === 'admin' ? $this->adminValues($year, $month) : $this->bolValues($year, $month),
        ]);
    }

    /**
     * Nilai halaman Beban Administrasi, sejajar indeks dengan
     * BebanUsahaController::rowsBebanAdministrasi() (29 rincian + Jumlah +
     * Depresiasi + Total). Tiap sel: {bln, sd, sdbl}. Hanya tab 'summary' yang
     * diisi — tab ADMI KS/KR tidak dikembalikan sehingga UI merender '-'.
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

        // Agregat per periode × klasifikasi (label di-TRIM saat impor).
        $summary = array_fill(0, count($rows), ['bln' => 0.0, 'sd' => 0.0, 'sdbl' => 0.0]);
        $agg = DB::table('beban_usaha_gl')
            ->where('report_type', 'ADMIN')->where('year', $year)
            ->selectRaw('period, class_desc, SUM(amount) AS v')
            ->groupBy('period', 'class_desc')->get();
        foreach ($agg as $r) {
            $i = $idxByLabel[trim((string) $r->class_desc)] ?? $iLain;
            $this->accumulate($summary[$i], (int) $r->period, $month, (float) $r->v);
        }

        // Jumlah = Σ rincian (sebelum baris subtotal); Total = Jumlah + Depresiasi.
        foreach (['bln', 'sd', 'sdbl'] as $f) {
            $jml = 0.0;
            for ($i = 0; $i < $iJumlah; $i++) {
                $jml += $summary[$i][$f];
            }
            $summary[$iJumlah][$f] = $jml;
            $summary[$iTotal][$f] = $jml + $summary[$iDepre][$f];
        }

        return ['summary' => $this->roundRows($summary)];
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
