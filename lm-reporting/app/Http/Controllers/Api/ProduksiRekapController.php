<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REKAP PRODUKSI (menu Produksi → Rekap Produksi).
 *
 * Satu tabel TIGA seksi (I. Kebun, II. Plasma/Pihak III, III. PKS) mengikuti
 * template docs/produksi/rekap_produksi/REKAP PRODUKSI.xlsx:
 * blok BULAN LALU / BULAN INI / S.D BULAN INI / RKAP BULAN INI / RKAP S.D BULAN INI
 * (masing-masing: TBS Diterima, TBS Diolah, MS, IS, Rend MS, Rend IS)
 * + blok rasio BI/BL, BI/RKAP, S.D BI/RKAP (TBS, RMS, RIS).
 *
 * Sumber angka = produksi_pks (halaman /produksi/pks):
 * - BULAN INI      = kolom *_sdhari  snapshot periode terpilih
 * - S.D BULAN INI  = kolom *_sdbulan snapshot periode terpilih
 * - BULAN LALU     = kolom *_sdhari  snapshot periode bulan sebelumnya
 * RKAP (khusus seksi PKS, per plant) = budget_rkap report_type LM16,
 * kode U3 (TBS masuk), U4 (TBS diolah), U7 (MS), U8 (IS); period NULL = tahunan
 * ikut kedua mode (pola Lm16Service). Anggaran per-kebun tidak tersedia → 0.
 *
 * Seksi II. Plasma/Pihak III (kebun_code PLSM/PHTG) dikelompokkan per PKS
 * penerima: baris judul kelompok (kode + nama PKS, flag `group`, tanpa nilai),
 * baris PLASMA, baris PIHAK 3, lalu JUMLAH per PKS (flag `subtotal`).
 * Blok RKAP seksi ini kosong: anggaran U3/U4/U7/U8 adalah total per plant,
 * tidak terpecah per pemilik TBS.
 */
class ProduksiRekapController extends Controller
{
    use AuthorizesReportRequests;

    /** measure => [kolom blok bulan-ini (sdhari), kolom blok s.d-bulan (sdbulan)] */
    private const MEASURES = [
        'tbs_diterima' => ['tbs_diterima_sdhari', 'tbs_diterima_sdbulan'],
        'tbs_diolah' => ['tbs_diolah_sdhari', 'tbs_diolah_sdbulan'],
        'ms' => ['ms_sdhari', 'ms_sdbulan'],
        'is' => ['is_sdhari', 'is_sdbulan'],
    ];

    /** Kode anggaran LM16 (U{urutan}) => measure rekap. */
    private const RKAP_CODES = ['U3' => 'tbs_diterima', 'U4' => 'tbs_diolah', 'U7' => 'ms', 'U8' => 'is'];

    /** Kode kebun pemilik TBS seksi II => label baris tampilan. */
    private const PLASMA_OWNERS = ['PLSM' => 'PLASMA', 'PHTG' => 'PIHAK 3'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $dates = DB::table('produksi_pks')
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        if ($dates === []) {
            return response()->json(['periods' => [], 'year' => null, 'month' => null, 'date' => null, 'sections' => []]);
        }

        // Periode = (tahun, bulan); tiap periode diwakili tanggal posting TERBARU
        // bulan itu (pola sama dgn ProduksiController).
        $latestByPeriod = [];
        foreach ($dates as $d) {
            $key = substr($d, 0, 7);
            $latestByPeriod[$key] = $latestByPeriod[$key] ?? $d;
        }
        $periods = [];
        foreach (array_keys($latestByPeriod) as $key) {
            [$yy, $mm] = explode('-', $key);
            $periods[] = ['year' => (int) $yy, 'month' => (int) $mm];
        }
        usort($periods, fn ($a, $b) => ($b['year'] <=> $a['year']) ?: ($b['month'] <=> $a['month']));

        $year = (int) $request->query('year', $periods[0]['year']);
        $month = (int) $request->query('month', $periods[0]['month']);
        $pkey = sprintf('%04d-%02d', $year, $month);
        if (! isset($latestByPeriod[$pkey])) {
            $year = $periods[0]['year'];
            $month = $periods[0]['month'];
            $pkey = sprintf('%04d-%02d', $year, $month);
        }
        $date = $latestByPeriod[$pkey];

        // Periode bulan lalu (rollover tahun); bisa tidak punya data → blok BULAN LALU 0.
        $prevYear = $month === 1 ? $year - 1 : $year;
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevKey = sprintf('%04d-%02d', $prevYear, $prevMonth);
        $prevDate = $latestByPeriod[$prevKey] ?? null;

        $rows = DB::table('produksi_pks')->whereDate('posting_date', $date)->get();
        $prevRows = $prevDate
            ? DB::table('produksi_pks')->whereDate('posting_date', $prevDate)->get()
            : collect();

        // ---- Agregat mentah per entitas: [entity][block][measure] => float ----
        // Seksi Kebun dikunci kebun_code (Σ lintas plant); seksi PKS dikunci plant_code.
        $aggKebun = [];
        $aggPks = [];
        $aggSub = []; // seksi Plasma/Pihak III: kunci "kebun|plant" (PLSM/PHTG per PKS)
        $add = function (&$agg, string $key, string $block, object $r, int $ci): void {
            foreach (self::MEASURES as $m => $cols) {
                $agg[$key][$block][$m] = ($agg[$key][$block][$m] ?? 0.0) + (float) $r->{$cols[$ci]};
            }
        };
        foreach ($rows as $r) {
            $k = (string) $r->kebun_code;
            $p = (string) $r->plant_code;
            if ($k !== '') {
                $add($aggKebun, $k, 'bi', $r, 0);
                $add($aggKebun, $k, 'sd', $r, 1);
            }
            if ($p !== '') {
                $add($aggPks, $p, 'bi', $r, 0);
                $add($aggPks, $p, 'sd', $r, 1);
            }
            if ($p !== '' && isset(self::PLASMA_OWNERS[$k])) {
                $add($aggSub, $k.'|'.$p, 'bi', $r, 0);
                $add($aggSub, $k.'|'.$p, 'sd', $r, 1);
            }
        }
        foreach ($prevRows as $r) {
            $k = (string) $r->kebun_code;
            $p = (string) $r->plant_code;
            if ($k !== '') {
                $add($aggKebun, $k, 'bl', $r, 0);
            }
            if ($p !== '') {
                $add($aggPks, $p, 'bl', $r, 0);
            }
            if ($p !== '' && isset(self::PLASMA_OWNERS[$k])) {
                $add($aggSub, $k.'|'.$p, 'bl', $r, 0);
            }
        }

        // ---- RKAP per plant (LM16, kode U3/U4/U7/U8). period NULL = tahunan → ikut keduanya. ----
        $rkap = []; // [plant][block(rkap_bi|rkap_sd)][measure] => float
        $budget = DB::table('budget_rkap')
            ->where('year', $year)->where('report_type', 'LM16')
            ->whereIn('kode', array_keys(self::RKAP_CODES))
            ->where(fn ($q) => $q->whereNull('period')->orWhere('period', '<=', $month))
            ->get(['plant_code', 'kode', 'period', 'nilai']);
        foreach ($budget as $b) {
            $m = self::RKAP_CODES[$b->kode];
            $p = (string) $b->plant_code;
            $inBi = $b->period === null || (int) $b->period === $month;
            $rkap[$p]['rkap_sd'][$m] = ($rkap[$p]['rkap_sd'][$m] ?? 0.0) + (float) $b->nilai;
            if ($inBi) {
                $rkap[$p]['rkap_bi'][$m] = ($rkap[$p]['rkap_bi'][$m] ?? 0.0) + (float) $b->nilai;
            }
        }

        // ---- Daftar baris ----
        // Kebun: distinct kebun_code seluruh data produksi_pks (semua periode) agar baris
        // stabil antar-periode; PLSM/PHTG dipisah ke seksi II. Urut 5E natural → lainnya.
        $kebunCodes = DB::table('produksi_pks')->distinct()->pluck('kebun_code')
            ->map(fn ($k) => (string) $k)
            ->filter(fn ($k) => $k !== '' && ! isset(self::PLASMA_OWNERS[$k]))->values()->all();
        $k5e = array_values(array_filter($kebunCodes, fn ($k) => preg_match('/^5E/i', $k)));
        sort($k5e, SORT_NATURAL);
        $tail = array_values(array_filter($kebunCodes, fn ($k) => ! preg_match('/^5E/i', $k)));
        sort($tail, SORT_NATURAL);
        $kebunCodes = array_merge($k5e, $tail);

        $unitNames = DB::table('ref_unit')->pluck('name', 'code')->all();
        $dataNames = DB::table('produksi_pks')->distinct()->pluck('nama_kebun', 'kebun_code')->all();
        $kebunList = array_map(fn ($k) => [
            'code' => $k,
            'nama' => $unitNames[$k] ?? ($dataNames[$k] ?? $k),
        ], $kebunCodes);

        // PKS: master ref_unit PABRIK komoditi KS (PKR di luar lingkup) — semua baris
        // tampil walau belum ada data; tambah kode dari data bila di luar master.
        $pksCodes = DB::table('ref_unit')->where('type', 'PABRIK')->where('komoditi', 'KS')
            ->orderBy('code')->pluck('code')->map(fn ($c) => (string) $c)->all();
        foreach (array_keys($aggPks) as $p) {
            if (! in_array($p, $pksCodes, true)) {
                $pksCodes[] = $p;
            }
        }
        $pksList = array_map(fn ($p) => [
            'code' => $p,
            'nama' => $unitNames[$p] ?? $p,
        ], $pksCodes);

        // Seksi II: kelompok per PKS penerima TBS Plasma/Pihak III (urut natural).
        $plasmaPlants = []; // [plant][owner(PLSM|PHTG)] => agg [block][measure]
        foreach ($aggSub as $sk => $agg) {
            [$k, $p] = explode('|', $sk, 2);
            $plasmaPlants[$p][$k] = $agg;
        }
        ksort($plasmaPlants, SORT_NATURAL);

        $sections = [
            $this->buildSection('kebun', 'I. Kebun', $kebunList, $aggKebun, []),
            $this->buildPlasmaSection($plasmaPlants, $unitNames),
            $this->buildSection('pks', 'III. PKS', $pksList, $aggPks, $rkap),
        ];

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'date' => $date,
            'prev' => ['year' => $prevYear, 'month' => $prevMonth, 'date' => $prevDate],
            'sections' => $sections,
        ]);
    }

    /**
     * Susun satu seksi: baris per entitas + baris JUMLAH (round-of-sum: total
     * dihitung dari agregat mentah, rasio & rendemen dari jumlah mentah).
     *
     * @param  array<int, array{code: string, nama: string}>  $entities
     * @param  array<string, array<string, array<string, float>>>  $agg  [entity][block][measure]
     * @param  array<string, array<string, array<string, float>>>  $rkap  [entity][rkap_bi|rkap_sd][measure]
     */
    private function buildSection(string $key, string $title, array $entities, array $agg, array $rkap): array
    {
        $blocks = ['bl', 'bi', 'sd', 'rkap_bi', 'rkap_sd'];
        $totals = [];
        foreach ($blocks as $b) {
            $totals[$b] = array_fill_keys(array_keys(self::MEASURES), 0.0);
        }

        $rows = [];
        foreach ($entities as $e) {
            $raw = [];
            foreach ($blocks as $b) {
                $src = str_starts_with($b, 'rkap_')
                    ? ($rkap[$e['code']][$b] ?? [])
                    : ($agg[$e['code']][$b] ?? []);
                foreach (array_keys(self::MEASURES) as $m) {
                    $raw[$b][$m] = (float) ($src[$m] ?? 0.0);
                    $totals[$b][$m] += $raw[$b][$m];
                }
            }
            $rows[] = $this->emitRow($e['code'], $e['nama'], $raw);
        }

        return [
            'key' => $key,
            'title' => $title,
            'rows' => $rows,
            'total' => $this->emitRow('', 'JUMLAH', $totals),
        ];
    }

    /**
     * Seksi II. Plasma/Pihak III — kelompok per PKS penerima, mengikuti contoh
     * Excel user: baris judul (kode + nama PKS, flag `group`, tanpa nilai),
     * baris PLASMA, baris PIHAK 3, lalu JUMLAH per PKS (flag `subtotal`,
     * round-of-sum dari agregat mentah kedua pemilik). JUMLAH seksi = Σ semua
     * kelompok. Blok RKAP kosong (anggaran tidak terpecah per pemilik TBS).
     *
     * @param  array<string, array<string, array<string, array<string, float>>>>  $plants  [plant][owner][block][measure]
     * @param  array<string, string>  $unitNames
     */
    private function buildPlasmaSection(array $plants, array $unitNames): array
    {
        $blocks = ['bl', 'bi', 'sd', 'rkap_bi', 'rkap_sd'];
        $zeros = [];
        foreach ($blocks as $b) {
            $zeros[$b] = array_fill_keys(array_keys(self::MEASURES), 0.0);
        }
        $totals = $zeros;

        $rows = [];
        foreach ($plants as $p => $byOwner) {
            $head = $this->emitRow($p, $unitNames[$p] ?? $p, $zeros);
            $head['group'] = true;
            $rows[] = $head;

            $sum = $zeros;
            foreach (self::PLASMA_OWNERS as $owner => $label) {
                $raw = $zeros;
                foreach ($blocks as $b) {
                    if (str_starts_with($b, 'rkap_')) {
                        continue;
                    }
                    foreach (array_keys(self::MEASURES) as $m) {
                        $v = (float) ($byOwner[$owner][$b][$m] ?? 0.0);
                        $raw[$b][$m] = $v;
                        $sum[$b][$m] += $v;
                        $totals[$b][$m] += $v;
                    }
                }
                $rows[] = $this->emitRow('', $label, $raw);
            }

            $sub = $this->emitRow('', 'JUMLAH', $sum);
            $sub['subtotal'] = true;
            $rows[] = $sub;
        }

        return [
            'key' => 'plasma',
            'title' => 'II. Plasma/Pihak III',
            'rows' => $rows,
            'total' => $this->emitRow('', 'JUMLAH', $totals),
        ];
    }

    /**
     * Emit satu baris: kuantitas dibulatkan 0 desimal, rendemen (%) 2 desimal,
     * rasio antar-blok (%) 2 desimal. Penyebut 0 → 0 (pola IFERROR/0).
     *
     * Rasio TBS memakai TBS Diolah (dasar rendemen); RMS/RIS = perbandingan
     * rendemen antar blok.
     *
     * @param  array<string, array<string, float>>  $raw  [block][measure] mentah
     */
    private function emitRow(string $code, string $nama, array $raw): array
    {
        $rend = fn (array $b, string $m): float => ($b['tbs_diolah'] ?? 0.0) > 0
            ? ($b[$m] ?? 0.0) / $b['tbs_diolah']
            : 0.0;
        $pct = fn (float $n, float $d): float => $d > 0.0 ? round($n / $d * 100, 2) : 0.0;

        $out = ['code' => $code, 'nama' => $nama];
        foreach ($raw as $block => $vals) {
            $out[$block] = [
                'tbs_diterima' => round($vals['tbs_diterima']),
                'tbs_diolah' => round($vals['tbs_diolah']),
                'ms' => round($vals['ms']),
                'is' => round($vals['is']),
                'rend_ms' => round($rend($vals, 'ms') * 100, 2),
                'rend_is' => round($rend($vals, 'is') * 100, 2),
            ];
        }

        $ratio = fn (array $num, array $den): array => [
            'tbs' => $pct($num['tbs_diolah'], $den['tbs_diolah']),
            'rms' => $pct($rend($num, 'ms'), $rend($den, 'ms')),
            'ris' => $pct($rend($num, 'is'), $rend($den, 'is')),
        ];
        $out['ratio'] = [
            'bi_bl' => $ratio($raw['bi'], $raw['bl']),
            'bi_rkap' => $ratio($raw['bi'], $raw['rkap_bi']),
            'sd_rkap' => $ratio($raw['sd'], $raw['rkap_sd']),
        ];

        return $out;
    }
}
