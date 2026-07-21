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
 * Satu tabel dua seksi (I. Kebun, II. PKS) mengikuti template
 * docs/produksi/rekap_produksi/REKAP PRODUKSI.xlsx:
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
 * Baris Plasma (PLSM) & Pihak III (PHTG) dipecah sub-baris per PKS penerima
 * (produksi_pks memang menyimpan baris per plant). Induknya diberi flag `group`
 * (judul kelompok — nilai disembunyikan di UI tapi tetap ikut JUMLAH seksi);
 * sub-baris tidak ikut JUMLAH dan blok RKAP-nya kosong: anggaran U3/U4/U7/U8
 * adalah total per plant, tidak terpecah per pemilik TBS.
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

    /** Nama tampilan khusus kode kebun non-5E. */
    private const KEBUN_ALIAS = ['PLSM' => 'Plasma', 'PHTG' => 'Pihak III'];

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
        $aggSub = []; // sub-baris per PKS utk PLSM/PHTG, kunci "kebun|plant"
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
            if ($p !== '' && isset(self::KEBUN_ALIAS[$k])) {
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
            if ($p !== '' && isset(self::KEBUN_ALIAS[$k])) {
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
        // stabil antar-periode; urut 5E natural → PLSM → PHTG → lainnya. Nama dari ref_unit.
        $kebunCodes = DB::table('produksi_pks')->distinct()->pluck('kebun_code')
            ->map(fn ($k) => (string) $k)->filter(fn ($k) => $k !== '')->values()->all();
        $k5e = array_values(array_filter($kebunCodes, fn ($k) => preg_match('/^5E/i', $k)));
        sort($k5e, SORT_NATURAL);
        $tail = array_values(array_filter($kebunCodes, fn ($k) => ! preg_match('/^5E/i', $k)));
        usort($tail, function ($a, $b) {
            $ord = ['PLSM' => 0, 'PHTG' => 1];
            return ($ord[$a] ?? 9) <=> ($ord[$b] ?? 9) ?: strnatcasecmp($a, $b);
        });
        $kebunCodes = array_merge($k5e, $tail);

        $unitNames = DB::table('ref_unit')->pluck('name', 'code')->all();
        $dataNames = DB::table('produksi_pks')->distinct()->pluck('nama_kebun', 'kebun_code')->all();
        $kebunList = array_map(fn ($k) => [
            'code' => $k,
            'nama' => self::KEBUN_ALIAS[$k] ?? ($unitNames[$k] ?? ($dataNames[$k] ?? $k)),
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

        // Sub-baris per PKS di bawah Plasma/Pihak III (urut natural per plant).
        $childrenKebun = []; // [kebun_code][] => {code, nama, agg}
        $subKeys = array_keys($aggSub);
        sort($subKeys, SORT_NATURAL);
        foreach ($subKeys as $sk) {
            [$k, $p] = explode('|', $sk, 2);
            $childrenKebun[$k][] = ['code' => $p, 'nama' => $unitNames[$p] ?? $p, 'agg' => $aggSub[$sk]];
        }

        $sections = [
            $this->buildSection('kebun', 'I. Kebun', $kebunList, $aggKebun, [], $childrenKebun),
            $this->buildSection('pks', 'II. PKS', $pksList, $aggPks, $rkap),
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
     * Sub-baris ($children, per kode entitas) diemit tepat di bawah induknya
     * dengan flag `sub`; nilainya TIDAK ditambahkan ke JUMLAH (induk sudah mencakup)
     * dan blok RKAP-nya 0 (tidak ada anggaran per pemilik TBS).
     *
     * @param  array<int, array{code: string, nama: string}>  $entities
     * @param  array<string, array<string, array<string, float>>>  $agg  [entity][block][measure]
     * @param  array<string, array<string, array<string, float>>>  $rkap  [entity][rkap_bi|rkap_sd][measure]
     * @param  array<string, array<int, array{code: string, nama: string, agg: array<string, array<string, float>>}>>  $children
     */
    private function buildSection(string $key, string $title, array $entities, array $agg, array $rkap, array $children = []): array
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
            $row = $this->emitRow($e['code'], $e['nama'], $raw);
            $kids = $children[$e['code']] ?? [];
            if ($kids !== []) {
                // Induk yang punya sub-baris jadi judul kelompok: UI menyembunyikan
                // nilainya (rinciannya di sub-baris); JUMLAH tetap dari agregat induk.
                $row['group'] = true;
            }
            $rows[] = $row;

            foreach ($kids as $c) {
                $craw = [];
                foreach ($blocks as $b) {
                    $src = str_starts_with($b, 'rkap_') ? [] : ($c['agg'][$b] ?? []);
                    foreach (array_keys(self::MEASURES) as $m) {
                        $craw[$b][$m] = (float) ($src[$m] ?? 0.0);
                    }
                }
                $rows[] = $this->emitRow($c['code'], $c['nama'], $craw, true);
            }
        }

        return [
            'key' => $key,
            'title' => $title,
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
    private function emitRow(string $code, string $nama, array $raw, bool $sub = false): array
    {
        $rend = fn (array $b, string $m): float => ($b['tbs_diolah'] ?? 0.0) > 0
            ? ($b[$m] ?? 0.0) / $b['tbs_diolah']
            : 0.0;
        $pct = fn (float $n, float $d): float => $d > 0.0 ? round($n / $d * 100, 2) : 0.0;

        $out = ['code' => $code, 'nama' => $nama];
        if ($sub) {
            $out['sub'] = true;
        }
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
