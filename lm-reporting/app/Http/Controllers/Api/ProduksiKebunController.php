<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman /produksi/kebun. Sumber: produksi_kebun_wb (sheet ZESTHLE020).
 * Menyajikan pivot Kebun Sendiri (mereplikasi sheet VIEW2): baris=kebun
 * (Goods Recipient), kolom=Afdeling, nilai=ΣWeight netto.
 * Pivot Pembelian sudah dipindah ke halaman /produksi/pembelian
 * (PembelianTbsController, sumber pembelian_tbs dengan nilai rupiah).
 */
class ProduksiKebunController extends Controller
{
    use AuthorizesReportRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $table = 'produksi_kebun_wb';

        $dates = DB::table($table)
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        if ($dates === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null,
                'afdeling' => [],
                'kebun_sendiri' => ['rows' => [], 'grand' => ['afd' => [], 'grand_total' => 0]],
            ]);
        }

        // Periode (tahun, bulan) dari tanggal posting; pilih terbaru bila tak diminta.
        $periods = [];
        $seenPeriod = [];
        foreach ($dates as $d) {
            $key = substr($d, 0, 7);
            if (! isset($seenPeriod[$key])) {
                $seenPeriod[$key] = true;
                [$yy, $mm] = explode('-', $key);
                $periods[] = ['year' => (int) $yy, 'month' => (int) $mm];
            }
        }
        usort($periods, fn ($a, $b) => ($b['year'] <=> $a['year']) ?: ($b['month'] <=> $a['month']));

        // Pakai periode diminta bila ada datanya; selain itu fallback ke periode terbaru.
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

        // Blok "Bulan Ini" = bulan terpilih saja.
        $baseBI = fn () => DB::table($table)
            ->whereYear('posting_date', $year)
            ->whereMonth('posting_date', $month);
        // Blok "s.d Bulan Ini" = kumulatif bulan terpilih + seluruh bulan sebelumnya (tahun sama).
        $baseSD = fn () => DB::table($table)
            ->whereYear('posting_date', $year)
            ->whereMonth('posting_date', '<=', $month);

        // Sub-kolom (Afdeling / Short Plant) & daftar baris diambil dari cakupan s.d
        // (superset dari bulan ini) agar kedua blok memakai kolom & baris identik.
        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'afdeling' => $this->afdelingColumns($baseSD),
            'kebun_sendiri' => $this->kebunSendiri($baseBI, $baseSD),
        ]);
    }

    /** Kolom Afdeling (urut natural: AFD01, AFD02, AFD02A, AFD03, ...). */
    private function afdelingColumns(callable $base): array
    {
        $afds = $base()->where('supply', 'Kebun Sendiri')->whereNotNull('afdeling')
            ->distinct()->pluck('afdeling')->all();
        sort($afds);

        return array_values($afds);
    }

    /**
     * Pivot Kebun Sendiri gabungan 2 blok (Bulan Ini + s.d), baris per kebun + Grand Total.
     * Tiap baris membawa nilai `bi` (bulan ini) & `sd` (kumulatif) untuk Afdeling & Grand Total.
     */
    private function kebunSendiri(callable $baseBI, callable $baseSD): array
    {
        $bi = $this->pivotKebunSendiri($baseBI);
        $sd = $this->pivotKebunSendiri($baseSD);

        // Union kode kebun (s.d umumnya superset; union tetap dipakai utk aman).
        $codes = array_keys($sd['rows'] + $bi['rows']);
        sort($codes);

        $rows = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            $b = $bi['rows'][$code] ?? null;
            $s = $sd['rows'][$code] ?? null;
            $rows[] = [
                'goods_recipient' => $code,
                'unit_kerja' => (string) ($s['unit_kerja'] ?? $b['unit_kerja'] ?? ''),
                'bi' => ['afd' => $b['afd'] ?? [], 'grand_total' => $b['grand_total'] ?? 0.0],
                'sd' => ['afd' => $s['afd'] ?? [], 'grand_total' => $s['grand_total'] ?? 0.0],
            ];
        }

        return [
            'rows' => $rows,
            'grand' => [
                'bi' => ['afd' => $bi['grandAfd'], 'grand_total' => $bi['grandTotal']],
                'sd' => ['afd' => $sd['grandAfd'], 'grand_total' => $sd['grandTotal']],
            ],
        ];
    }

    /** Hitung pivot kebun sendiri utk satu cakupan → keyed by goods_recipient. */
    private function pivotKebunSendiri(callable $base): array
    {
        $grouped = $base()->where('supply', 'Kebun Sendiri')
            ->select('goods_recipient', 'desc_plant_kebun', 'afdeling', DB::raw('SUM(weight_netto) AS total'))
            ->groupBy('goods_recipient', 'desc_plant_kebun', 'afdeling')
            ->get();

        $rows = [];
        $grandAfd = [];
        $grandTotal = 0.0;
        foreach ($grouped as $r) {
            $code = (string) $r->goods_recipient;
            if (! isset($rows[$code])) {
                $rows[$code] = [
                    'unit_kerja' => (string) ($r->desc_plant_kebun ?? ''),
                    'afd' => [],
                    'grand_total' => 0.0,
                ];
            }
            $val = (float) $r->total;
            $afd = (string) ($r->afdeling ?? '');
            if ($afd !== '') {
                $rows[$code]['afd'][$afd] = ($rows[$code]['afd'][$afd] ?? 0) + $val;
                $grandAfd[$afd] = ($grandAfd[$afd] ?? 0) + $val;
            }
            $rows[$code]['grand_total'] += $val;
            $grandTotal += $val;
        }

        return ['rows' => $rows, 'grandAfd' => $grandAfd, 'grandTotal' => $grandTotal];
    }

}
