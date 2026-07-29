<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data tabel LM 34 (Daftar Penjualan Ekspor dan Lokal) — tab pada /laba-rugi/penjualan.
 *
 * Sumber: TABEL YANG SAMA dengan halaman Penjualan (`penjualan_produk`, ekspor GL SAP),
 * diagregasi persis seperti tab PLANT. Pemetaan baris dibuktikan oleh sheet "Sheet1"
 * pada docs/laba_rugi/lm34/LM34.xlsx (12 tangkapan layar tab PLANT + label sel):
 *   B13/N13 → TBS × plant kebun (5E)   B14/N14 → TBS × plant pabrik (5F)
 *   B19/N19 → grup CPO (Jumlah)        B20/N20 → grup INTI SAWIT (Jumlah)
 *   B33/N33 → Lump × selain Batulicin  B34/N34 → Lump × 5E13000001 (Batulicin)
 * Kolom Bulan ini = period == bulan; s.d Bulan ini = period <= bulan (tahun sama).
 *
 * Belum ada sumber (dirender '-'): kolom Anggaran (RKAP penjualan), Jumlah Nilai
 * Dollar FOB, Selisih Lebih(Kurang), serta baris hasil titip olah / Gula / RSS /
 * Cutting / Brown Crepe / Sir-20.
 */
class Lm34Controller extends Controller
{
    use AuthorizesReportRequests;

    /** Nama material pada penjualan_produk (material_desc). */
    private const MAT_TBS = 'TBS (TANDAN BUAH SEGAR)';

    private const MAT_CPO = 'CPO';

    private const MAT_PK = 'INTI SAWIT';

    private const MAT_LUMP = 'Lump';

    /** Kebun Batulicin — satu-satunya Lump yang punya baris sendiri di template. */
    private const PC_BATULICIN = '5E13000001';

    /** Material yang punya baris di LM-34; di luar ini dilaporkan sebagai belum terpetakan. */
    private const MAT_KNOWN = [self::MAT_TBS, self::MAT_CPO, self::MAT_PK, self::MAT_LUMP];

    /**
     * Aturan sumber per kunci baris rincian. Kunci yang tidak ada di sini = baris
     * template tanpa sumber data (nilai 0 → dirender '-').
     *
     * mat      : material_desc persis.
     * pcPrefix : awalan profit center (5E = kebun, 5F = pabrik).
     * pcIn     : daftar profit center yang diambil.
     * pcNotIn  : daftar profit center yang dikecualikan (penampung sisa).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sourceRules(): array
    {
        return [
            // TBS: dijual kebun (5E) = kebun sendiri; dijual pabrik (5F) = plasma + pihak III.
            'tbs_sendiri' => ['mat' => self::MAT_TBS, 'pcPrefix' => '5E'],
            'tbs_pihak3' => ['mat' => self::MAT_TBS, 'pcPrefix' => '5F'],
            'cpo' => ['mat' => self::MAT_CPO],
            'pk' => ['mat' => self::MAT_PK],
            // Lump: baris Batulicin berdiri sendiri, kebun karet lain masuk baris penampung.
            'lump_lain' => ['mat' => self::MAT_LUMP, 'pcNotIn' => [self::PC_BATULICIN]],
            'lump_btl' => ['mat' => self::MAT_LUMP, 'pcIn' => [self::PC_BATULICIN]],
        ];
    }

    /**
     * Struktur baris persis sheet LM-34 (urutan & label verbatim, termasuk duplikat
     * "- Sir - 20 KAR" yang memang ada di template).
     *
     * t   : section|header|detail|subtotal|total
     * k   : kunci baris (dipakai nilai & drill-down)
     * sum : daftar kunci yang dijumlahkan (subtotal/total)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowLayout(): array
    {
        $karetKosong = [
            'rss1' => '- RSS. 1', 'rss2' => '- RSS. 2', 'rss3' => '- RSS. 3', 'rss4' => '- RSS. 4',
            'cut_a' => '- Cutting A', 'cut_b' => '- Cutting B',
            'bc1' => '- Brown Crepe 1 x', 'bc2' => '- Brown Crepe 2 x',
            'sir_kar1' => '- Sir - 20 KAR', 'sir_kab' => '- Sir - 20 KAB', 'sir_kar2' => '- Sir - 20 KAR',
            'sir_kau' => '- Sir - 20 KAU', 'sir_kay' => '- Sir - 20 KAY', 'sir_kbb' => '- Sir - 20 KBB',
            'sir_kby' => '- Sir - 20 KBY', 'sir_kbf' => '- Sir - 20 KBF', 'sir_kbj' => '- Sir - 20 KBJ',
            'sir_kbs' => '- Sir - 20 KBS',
        ];

        $rows = [
            ['u' => 'L o k a l', 't' => 'section'],
            ['u' => 'T B S', 't' => 'header'],
            ['u' => 'Kebun Sendiri', 't' => 'detail', 'k' => 'tbs_sendiri'],
            ['u' => 'Kebun Plasma + Pihak III', 't' => 'detail', 'k' => 'tbs_pihak3'],
            ['u' => 'Jumlah', 't' => 'subtotal', 'k' => 'jml_tbs', 'sum' => ['tbs_sendiri', 'tbs_pihak3']],
            ['u' => 'A. Kelapa Sawit ( Kg )', 't' => 'header'],
            ['u' => '- Minyak Sawit ( CPO )', 't' => 'detail', 'k' => 'cpo'],
            ['u' => '- Inti Sawit ( PK )', 't' => 'detail', 'k' => 'pk'],
            ['u' => '- Minyak Sawit ( CPO ) hasil titip olah', 't' => 'detail', 'k' => 'cpo_titip'],
            ['u' => '- Inti Sawit ( PK ) hasil titip olah', 't' => 'detail', 'k' => 'pk_titip'],
            ['u' => 'Jumlah A.', 't' => 'subtotal', 'k' => 'jml_a', 'sum' => ['cpo', 'pk', 'cpo_titip', 'pk_titip']],
            ['u' => 'B. G u l a', 't' => 'header'],
            ['u' => '- G u l a', 't' => 'detail', 'k' => 'gula'],
            ['u' => 'Jumlah B.', 't' => 'subtotal', 'k' => 'jml_b', 'sum' => ['gula']],
            ['u' => 'C. Karet ( Kg )', 't' => 'header'],
            ['u' => '- Lump Kering (Sinta,Lokal,Kumai, Tambarangan & Dasal)', 't' => 'detail', 'k' => 'lump_lain'],
            ['u' => '- Lump Kering (Batu Licin)', 't' => 'detail', 'k' => 'lump_btl'],
        ];
        foreach ($karetKosong as $k => $u) {
            $rows[] = ['u' => $u, 't' => 'detail', 'k' => $k];
        }
        $rows[] = ['u' => 'Jumlah C.', 't' => 'subtotal', 'k' => 'jml_c', 'sum' => array_merge(['lump_lain', 'lump_btl'], array_keys($karetKosong))];
        // Ikut template: Jumlah Lokal = SUM(B15, B26, B53) → TBS + A + C; Jumlah B
        // (Gula) memang TIDAK ikut dijumlah di Excel (nilainya selalu 0 di regional ini).
        $rows[] = ['u' => 'Jumlah Lokal ( A + B + C )', 't' => 'total', 'k' => 'lokal', 'sum' => ['jml_tbs', 'jml_a', 'jml_c']];
        // Seksi Ekspor tidak ada di template → baris penutup = Jumlah Lokal.
        $rows[] = ['u' => 'Jumlah Ekspor + Lokal', 't' => 'total', 'k' => 'total', 'sum' => ['lokal']];

        return $rows;
    }

    /**
     * Kunci baris rincian bersumber yang tercakup oleh sebuah baris (subtotal/total
     * dijabarkan sampai baris rincian). Baris tanpa sumber → array kosong.
     *
     * @return array<int, string>
     */
    public static function detailKeysOf(string $key): array
    {
        $rules = self::sourceRules();
        $byKey = [];
        foreach (self::rowLayout() as $r) {
            if (isset($r['k'])) {
                $byKey[$r['k']] = $r;
            }
        }
        if (! isset($byKey[$key])) {
            return [];
        }

        $out = [];
        $walk = function (string $k) use (&$walk, $byKey, $rules, &$out): void {
            $row = $byKey[$k] ?? null;
            if ($row === null) {
                return;
            }
            if (isset($row['sum'])) {
                foreach ($row['sum'] as $child) {
                    $walk($child);
                }

                return;
            }
            if (isset($rules[$k])) {
                $out[] = $k;
            }
        };
        $walk($key);

        return array_values(array_unique($out));
    }

    /**
     * Terapkan filter sumber gabungan (OR antar kunci) ke query penjualan_produk.
     *
     * @param  array<int, string>  $keys
     */
    public static function applySourceFilter(Builder $q, array $keys): void
    {
        $rules = self::sourceRules();
        $q->where(function ($w) use ($keys, $rules): void {
            foreach ($keys as $k) {
                $rule = $rules[$k] ?? null;
                if ($rule === null) {
                    continue;
                }
                $w->orWhere(function ($w2) use ($rule): void {
                    $w2->where('material_desc', $rule['mat']);
                    if (isset($rule['pcPrefix'])) {
                        $w2->where('profit_center', 'like', $rule['pcPrefix'].'%');
                    }
                    if (isset($rule['pcIn'])) {
                        $w2->whereIn('profit_center', $rule['pcIn']);
                    }
                    if (isset($rule['pcNotIn'])) {
                        $w2->whereNotIn('profit_center', $rule['pcNotIn']);
                    }
                });
            }
        });
    }

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $periods = DB::table('penjualan_produk')
            ->select('year', DB::raw('period AS month'))->distinct()
            ->orderByDesc('year')->orderByDesc('period')
            ->get()->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null,
                'rows' => $this->rows([]), 'unmapped' => [],
            ]);
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

        [$values, $unmapped] = $this->aggregate($year, $month);

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'rows' => $this->rows($values),
            'unmapped' => $unmapped,
        ]);
    }

    /** Pola aman: penyebut 0 → null ('-' di UI), bukan #DIV/0! seperti Excel. */
    private static function safeDiv(float $a, float $b): ?float
    {
        return $b != 0.0 ? $a / $b : null;
    }

    /**
     * Agregat penjualan_produk per kunci baris + daftar material di luar peta.
     * Nilai GL tersimpan kredit (negatif) → dibalik tanda agar positif seperti Excel;
     * nota debit/koreksi tetap mengurangi (jadi bukan nilai mutlak per baris).
     *
     * @return array{0: array<string, array{vol_bln: float, vol_sd: float, nil_bln: float, nil_sd: float}>, 1: array<int, array<string, mixed>>}
     */
    private function aggregate(int $year, int $month): array
    {
        $raw = DB::table('penjualan_produk')
            ->where('year', $year)->where('period', '<=', $month)
            ->selectRaw(
                'material_desc, profit_center,'
                .' COALESCE(SUM(CASE WHEN period = ? THEN qty END), 0) AS q_bln,'
                .' COALESCE(SUM(CASE WHEN period = ? THEN amount END), 0) AS v_bln,'
                .' COALESCE(SUM(qty), 0) AS q_sd, COALESCE(SUM(amount), 0) AS v_sd',
                [$month, $month]
            )
            ->groupBy('material_desc', 'profit_center')
            ->get();

        $rules = self::sourceRules();
        $values = [];
        $unmapped = [];
        foreach ($raw as $r) {
            $mat = (string) $r->material_desc;
            $pc = (string) ($r->profit_center ?? '');
            $qBln = -1 * (float) $r->q_bln;
            $vBln = -1 * (float) $r->v_bln;
            $qSd = -1 * (float) $r->q_sd;
            $vSd = -1 * (float) $r->v_sd;

            if (! in_array($mat, self::MAT_KNOWN, true)) {
                $unmapped[$mat] ??= ['material' => $mat, 'qty_sd' => 0.0, 'nilai_sd' => 0.0];
                $unmapped[$mat]['qty_sd'] += $qSd;
                $unmapped[$mat]['nilai_sd'] += $vSd;

                continue;
            }

            foreach ($rules as $key => $rule) {
                if ($rule['mat'] !== $mat) {
                    continue;
                }
                if (isset($rule['pcPrefix']) && ! str_starts_with($pc, $rule['pcPrefix'])) {
                    continue;
                }
                if (isset($rule['pcIn']) && ! in_array($pc, $rule['pcIn'], true)) {
                    continue;
                }
                if (isset($rule['pcNotIn']) && in_array($pc, $rule['pcNotIn'], true)) {
                    continue;
                }
                $values[$key] ??= ['vol_bln' => 0.0, 'vol_sd' => 0.0, 'nil_bln' => 0.0, 'nil_sd' => 0.0];
                $values[$key]['vol_bln'] += $qBln;
                $values[$key]['nil_bln'] += $vBln;
                $values[$key]['vol_sd'] += $qSd;
                $values[$key]['nil_sd'] += $vSd;
            }
        }

        return [$values, array_values($unmapped)];
    }

    /**
     * Baris siap render: label + tipe + nilai tiap kolom template.
     * Anggaran (volume & nilai), Dollar FOB, dan Selisih belum punya sumber → null
     * (dirender '-'); Selisih menyusul begitu RKAP penjualan tersedia (= s.d realisasi − s.d anggaran).
     *
     * @param  array<string, array{vol_bln: float, vol_sd: float, nil_bln: float, nil_sd: float}>  $values
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $values): array
    {
        $rules = self::sourceRules();
        $out = [];
        $acc = [];

        foreach (self::rowLayout() as $def) {
            $row = ['u' => $def['u'], '_type' => $def['t']];
            $key = $def['k'] ?? null;

            if ($key === null || $def['t'] === 'section' || $def['t'] === 'header') {
                $out[] = $row;

                continue;
            }

            if (isset($def['sum'])) {
                $v = ['vol_bln' => 0.0, 'vol_sd' => 0.0, 'nil_bln' => 0.0, 'nil_sd' => 0.0];
                foreach ($def['sum'] as $child) {
                    foreach ($v as $f => $_) {
                        $v[$f] += $acc[$child][$f] ?? 0.0;
                    }
                }
            } else {
                $v = $values[$key] ?? ['vol_bln' => 0.0, 'vol_sd' => 0.0, 'nil_bln' => 0.0, 'nil_sd' => 0.0];
            }
            $acc[$key] = $v;

            $row['_k'] = $key;
            // Baris bisa dirinci bila punya (atau mencakup) baris bersumber.
            $row['_drill'] = isset($rules[$key]) || self::detailKeysOf($key) !== [];
            $row['vol_r_bln'] = $v['vol_bln'];
            $row['vol_r_sd'] = $v['vol_sd'];
            $row['vol_a_bln'] = null;
            $row['vol_a_sd'] = null;
            $row['hrg_bln_r'] = self::safeDiv($v['nil_bln'], $v['vol_bln']);
            $row['hrg_bln_a'] = null;
            $row['hrg_sd_r'] = self::safeDiv($v['nil_sd'], $v['vol_sd']);
            $row['hrg_sd_a'] = null;
            $row['fob_bln'] = null;
            $row['fob_sd'] = null;
            $row['nil_r_bln'] = $v['nil_bln'];
            $row['nil_r_sd'] = $v['nil_sd'];
            $row['nil_a_bln'] = null;
            $row['nil_a_sd'] = null;
            $row['selisih'] = null;

            $out[] = $row;
        }

        return $out;
    }
}
