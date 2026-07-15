<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman /produksi/pembelian. Sumber: pembelian_tbs (sheet "Data" ekspor SAP).
 * Mereplikasi sheet TEMPLATE workbook "Pembelian TBS Tahun 2026":
 *  - Summary : baris = 8 pabrik × {Kebun Pihak 3, Kebun Plasma, Jumlah} + Total;
 *              blok kolom Bulan Lalu / Bulan Ini / Sd Bulan Ini (Qty, Rp/Kg, Value)
 *              + RKAP (belum ada sumber → null) + rasio BI/BL, BI/RKAP, SBI/RKAP.
 *  - Rincian : baris = grup PHTG/PLSM → vendor → subtotal → Grand Total;
 *              kolom = per pabrik {Bulan Ini, SD Bulan Ini} × {Qty, Rp/Kg, Value} + Total.
 */
class PembelianTbsController extends Controller
{
    use AuthorizesReportRequests;

    /** Urutan & label pabrik sesuai sheet TEMPLATE (baris 14-37). */
    public const PLANTS = [
        '5F01' => ['nama' => 'PABRIK GUNUNG MELIAU', 'short' => 'Pagun'],
        '5F04' => ['nama' => 'PABRIK RIMBA BELIAN', 'short' => 'Parba'],
        '5F07' => ['nama' => 'PABRIK NGABANG', 'short' => 'Panga'],
        '5F08' => ['nama' => 'PABRIK PARINDU', 'short' => 'Papar'],
        '5F09' => ['nama' => 'PABRIK KEMBAYAN', 'short' => 'Pakem'],
        '5F15' => ['nama' => 'PABRIK PELAIHARI', 'short' => 'Papel'],
        '5F22' => ['nama' => 'PABRIK LONG PINANG', 'short' => 'Palpi'],
        '5F14' => ['nama' => 'PABRIK PAMUKAN', 'short' => 'Papam'],
    ];

    /** Urutan grup batch + label tampilan (kolom "Pembelian" di Summary). */
    private const BATCHES = ['PHTG' => 'Kebun Pihak 3', 'PLSM' => 'Kebun Plasma'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        // Daftar periode tersedia (terbaru dulu); pilih yang diminta bila ada datanya.
        $periods = DB::table('pembelian_tbs')
            ->select('year', DB::raw('period AS month'))->distinct()
            ->orderByDesc('year')->orderByDesc('period')
            ->get()->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null,
                'plants' => $this->plantList(),
                'summary' => ['rows' => [], 'total' => null],
                'rincian' => ['groups' => [], 'grand' => null],
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

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'plants' => $this->plantList(),
            'summary' => $this->summary($year, $month),
            'rincian' => $this->rincian($year, $month),
        ]);
    }

    /** @return array<int, array{code: string, nama: string, short: string}> */
    private function plantList(): array
    {
        $out = [];
        foreach (self::PLANTS as $code => $p) {
            $out[] = ['code' => $code, 'nama' => $p['nama'], 'short' => $p['short']];
        }

        return $out;
    }

    /** Rp/Kg & rasio memakai pola aman: penyebut 0 → 0 (IFERROR Excel). */
    private static function safeDiv(float $a, float $b): float
    {
        return $b != 0.0 ? $a / $b : 0.0;
    }

    /** Blok nilai {qty, rpkg, value} dari pasangan qty+value. */
    private static function blok(float $qty, float $value): array
    {
        return ['qty' => $qty, 'rpkg' => self::safeDiv($value, $qty), 'value' => $value];
    }

    /**
     * Agregat qty+value per plant×batch untuk satu cakupan periode.
     * $op '=' → satu periode; '<=' → kumulatif s.d periode.
     *
     * @return array<string, array{0: float, 1: float}> map "plant|batch" → [qty, value]
     */
    private function aggPlantBatch(int $year, int $period, string $op): array
    {
        if ($period < 1) {
            return [];
        }
        $rows = DB::table('pembelian_tbs')
            ->where('year', $year)->where('period', $op, $period)
            ->selectRaw('plant_code, batch, SUM(qty) AS q, SUM(actual_value) AS v')
            ->groupBy('plant_code', 'batch')->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->plant_code.'|'.$r->batch] = [(float) $r->q, (float) $r->v];
        }

        return $map;
    }

    /**
     * Tab Summary — struktur baris identik TEMPLATE baris 14-38.
     *
     * @return array{rows: array<int, mixed>, total: mixed}
     */
    private function summary(int $year, int $month): array
    {
        $bl = $this->aggPlantBatch($year, $month - 1, '=');   // Bulan Lalu (Januari → kosong)
        $bi = $this->aggPlantBatch($year, $month, '=');       // Bulan Ini
        $sd = $this->aggPlantBatch($year, $month, '<=');      // Sd Bulan Ini

        $rows = [];
        $tot = ['bl' => [0.0, 0.0], 'bi' => [0.0, 0.0], 'sd' => [0.0, 0.0]];
        foreach (self::PLANTS as $code => $p) {
            $baris = [];
            $jml = ['bl' => [0.0, 0.0], 'bi' => [0.0, 0.0], 'sd' => [0.0, 0.0]];
            foreach (self::BATCHES as $batch => $label) {
                $vals = [];
                foreach (['bl' => $bl, 'bi' => $bi, 'sd' => $sd] as $key => $map) {
                    [$q, $v] = $map[$code.'|'.$batch] ?? [0.0, 0.0];
                    $vals[$key] = [$q, $v];
                    $jml[$key][0] += $q;
                    $jml[$key][1] += $v;
                    $tot[$key][0] += $q;
                    $tot[$key][1] += $v;
                }
                $baris[] = $this->summaryRow($label, $batch, $vals);
            }
            $baris[] = $this->summaryRow('Jumlah', 'JML', $jml);
            $rows[] = ['plant_code' => $code, 'plant_nama' => $p['nama'], 'baris' => $baris];
        }

        return ['rows' => $rows, 'total' => $this->summaryRow('Total', 'TOTAL', $tot)];
    }

    /**
     * Satu baris Summary: blok BL/BI/SD + RKAP (null) + rasio.
     * Rasio (baris 13 TEMPLATE): BI/BL = G/D (qty) & I/F (value); BI/RKAP = G/M & I/O;
     * SBI/RKAP = J/P & L/R — RKAP belum ada sumber → rasio RKAP 0.
     *
     * @param  array{bl: array{0: float, 1: float}, bi: array{0: float, 1: float}, sd: array{0: float, 1: float}}  $vals
     */
    private function summaryRow(string $label, string $key, array $vals): array
    {
        [$blQ, $blV] = $vals['bl'];
        [$biQ, $biV] = $vals['bi'];
        [$sdQ, $sdV] = $vals['sd'];

        return [
            'label' => $label,
            'key' => $key,
            'bl' => self::blok($blQ, $blV),
            'bi' => self::blok($biQ, $biV),
            'sd' => self::blok($sdQ, $sdV),
            'rkap_bi' => null,
            'rkap_sd' => null,
            'ratio' => [
                'bibl_qty' => self::safeDiv($biQ, $blQ),
                'bibl_val' => self::safeDiv($biV, $blV),
                'birkap_qty' => 0.0,
                'birkap_val' => 0.0,
                'sbirkap_qty' => 0.0,
                'sbirkap_val' => 0.0,
            ],
        ];
    }

    /**
     * Tab Rincian — grup PHTG/PLSM → vendor (urut kode) → subtotal → Grand Total.
     * Tiap baris: per plant {bi, sd} × {qty, rpkg, value} + total {bi, sd} × {qty, value}.
     *
     * @return array{groups: array<int, mixed>, grand: mixed}
     */
    private function rincian(int $year, int $month): array
    {
        $bi = $this->aggVendorPlant($year, $month, '=');
        $sd = $this->aggVendorPlant($year, $month, '<=');

        $groups = [];
        $grand = $this->emptyAgg();
        foreach (array_keys(self::BATCHES) as $batch) {
            $sdVend = $sd[$batch] ?? [];
            $biVend = $bi[$batch] ?? [];
            $codes = array_keys($sdVend + $biVend);
            sort($codes); // urut kode vendor ascending (spt sheet RINCIAN)

            $rows = [];
            $sub = $this->emptyAgg();
            foreach ($codes as $code) {
                $b = $biVend[$code] ?? null;
                $s = $sdVend[$code] ?? null;
                $row = [
                    'vendor_code' => (string) $code,
                    'vendor_name' => (string) ($s['name'] ?? $b['name'] ?? ''),
                    'plants' => [],
                    'total' => [
                        'bi' => ['qty' => 0.0, 'value' => 0.0],
                        'sd' => ['qty' => 0.0, 'value' => 0.0],
                    ],
                ];
                foreach (array_keys(self::PLANTS) as $pc) {
                    $cell = [];
                    foreach (['bi' => $b, 'sd' => $s] as $key => $src) {
                        [$q, $v] = $src['plants'][$pc] ?? [0.0, 0.0];
                        $cell[$key] = self::blok($q, $v);
                        $row['total'][$key]['qty'] += $q;
                        $row['total'][$key]['value'] += $v;
                        $sub[$key][$pc][0] += $q;
                        $sub[$key][$pc][1] += $v;
                    }
                    $row['plants'][$pc] = $cell;
                }
                $rows[] = $row;
            }

            $groups[] = [
                'batch' => $batch,
                'label' => self::BATCHES[$batch],
                'rows' => $rows,
                'subtotal' => $this->aggRow($sub),
            ];
            foreach (['bi', 'sd'] as $key) {
                foreach (array_keys(self::PLANTS) as $pc) {
                    $grand[$key][$pc][0] += $sub[$key][$pc][0];
                    $grand[$key][$pc][1] += $sub[$key][$pc][1];
                }
            }
        }

        return ['groups' => $groups, 'grand' => $this->aggRow($grand)];
    }

    /** Kerangka akumulator subtotal/grand: [bi|sd][plant] → [qty, value]. */
    private function emptyAgg(): array
    {
        $agg = ['bi' => [], 'sd' => []];
        foreach (array_keys(self::PLANTS) as $pc) {
            $agg['bi'][$pc] = [0.0, 0.0];
            $agg['sd'][$pc] = [0.0, 0.0];
        }

        return $agg;
    }

    /** Akumulator → baris subtotal/grand berbentuk sama dengan baris vendor. */
    private function aggRow(array $agg): array
    {
        $row = ['plants' => [], 'total' => [
            'bi' => ['qty' => 0.0, 'value' => 0.0],
            'sd' => ['qty' => 0.0, 'value' => 0.0],
        ]];
        foreach (array_keys(self::PLANTS) as $pc) {
            foreach (['bi', 'sd'] as $key) {
                [$q, $v] = $agg[$key][$pc];
                $row['plants'][$pc][$key] = self::blok($q, $v);
                $row['total'][$key]['qty'] += $q;
                $row['total'][$key]['value'] += $v;
            }
        }

        return $row;
    }

    /**
     * Agregat per batch×vendor×plant untuk satu cakupan periode.
     *
     * @return array<string, array<string, array{name: string, plants: array<string, array{0: float, 1: float}>}>>
     *         map [batch][vendor_code] → {name, plants: plant → [qty, value]}
     */
    private function aggVendorPlant(int $year, int $period, string $op): array
    {
        $rows = DB::table('pembelian_tbs')
            ->where('year', $year)->where('period', $op, $period)
            ->selectRaw('batch, vendor_code, vendor_name, plant_code, SUM(qty) AS q, SUM(actual_value) AS v')
            ->groupBy('batch', 'vendor_code', 'vendor_name', 'plant_code')->get();

        $map = [];
        foreach ($rows as $r) {
            $batch = (string) $r->batch;
            $code = (string) ($r->vendor_code ?? '');
            $map[$batch][$code] ??= ['name' => (string) ($r->vendor_name ?? ''), 'plants' => []];
            $pc = (string) $r->plant_code;
            $cur = $map[$batch][$code]['plants'][$pc] ?? [0.0, 0.0];
            $map[$batch][$code]['plants'][$pc] = [$cur[0] + (float) $r->q, $cur[1] + (float) $r->v];
        }

        return $map;
    }
}
