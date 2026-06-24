<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArealController extends Controller
{
    use AuthorizesReportRequests;

    private const STATUS_ORDER = ['TM', 'ATTP', 'TBM', 'TU'];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'komoditi' => ['nullable', 'in:KS,KR'],
            'unit' => ['nullable', 'string', 'max:20'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $komoditi = strtoupper((string) ($data['komoditi'] ?? 'KS'));
        $unit = (string) ($data['unit'] ?? '');

        $batch = Batch::query()->where('year', $year)->where('month', $month)->first();
        if (! $batch) {
            return response()->json(['afds' => [], 'rows' => []]);
        }

        // Otorisasi: Viewer hanya boleh lihat batch final/locked (sama seperti report-data lain).
        $this->checkBatchAccess($batch);

        $rows = ArealBlok::query()
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->when($unit !== '' && $unit !== 'ALL', fn ($q) => $q->where('plant_code', $unit))
            ->get(['status_blok_petak', 'divisi', 'tahun_tanam', 'luas_tanam', 'total_pokok_produktif']);

        // AFD dinamis, urut numerik.
        $afds = $rows->pluck('divisi')->filter()->unique()->values()->all();
        sort($afds, SORT_NATURAL);

        // Agregasi: status → tahun → afd → {luas,pokok}
        $agg = [];
        foreach ($rows as $r) {
            $s = (string) $r->status_blok_petak;
            $t = $r->tahun_tanam !== null ? (int) $r->tahun_tanam : 0;
            $a = (string) $r->divisi;
            $agg[$s][$t][$a]['luas'] = ($agg[$s][$t][$a]['luas'] ?? 0) + (float) $r->luas_tanam;
            $agg[$s][$t][$a]['pokok'] = ($agg[$s][$t][$a]['pokok'] ?? 0) + (int) $r->total_pokok_produktif;
        }

        $statuses = array_keys($agg);
        usort($statuses, function ($x, $y) {
            $ix = array_search($x, self::STATUS_ORDER, true);
            $iy = array_search($y, self::STATUS_ORDER, true);
            $ix = $ix === false ? 999 : $ix;
            $iy = $iy === false ? 999 : $iy;

            return $ix === $iy ? strcmp($x, $y) : $ix <=> $iy;
        });

        // Akumulasi SEMUA nilai sebagai float MENTAH (belum dibulatkan).
        // Pembulatan 2-desimal hanya diterapkan saat menyusun output (round-of-sum,
        // bukan sum-of-rounded) agar total persis seperti pivot Excel.
        $emptyCells = fn () => array_fill_keys($afds, ['luas' => 0.0, 'pokok' => 0]);
        $out = [];
        $grand = ['cells' => $emptyCells(), 'luas' => 0.0, 'pokok' => 0];

        foreach ($statuses as $s) {
            $years = array_keys($agg[$s]);
            sort($years);
            $sub = ['cells' => $emptyCells(), 'luas' => 0.0, 'pokok' => 0];
            foreach ($years as $t) {
                $cells = $emptyCells();
                $rowTotRaw = ['luas' => 0.0, 'pokok' => 0];
                foreach ($afds as $a) {
                    $rawLuas = (float) ($agg[$s][$t][$a]['luas'] ?? 0);
                    $pokok = (int) ($agg[$s][$t][$a]['pokok'] ?? 0);
                    $cells[$a] = ['luas' => round($rawLuas, 2), 'pokok' => $pokok];
                    $rowTotRaw['luas'] += $rawLuas;
                    $rowTotRaw['pokok'] += $pokok;
                    $sub['cells'][$a]['luas'] += $rawLuas;
                    $sub['cells'][$a]['pokok'] += $pokok;
                    $grand['cells'][$a]['luas'] += $rawLuas;
                    $grand['cells'][$a]['pokok'] += $pokok;
                }
                $sub['luas'] += $rowTotRaw['luas'];
                $sub['pokok'] += $rowTotRaw['pokok'];
                $out[] = ['type' => 'detail', 'status' => $s, 'tahun_tanam' => $t === 0 ? null : $t, 'cells' => $cells, 'total' => ['luas' => round($rowTotRaw['luas'], 2), 'pokok' => $rowTotRaw['pokok']]];
            }
            $out[] = ['type' => 'subtotal', 'status' => $s, 'label' => $s.' Total', 'cells' => $this->roundCells($sub['cells']), 'total' => ['luas' => round($sub['luas'], 2), 'pokok' => $sub['pokok']]];
            $grand['luas'] += $sub['luas'];
            $grand['pokok'] += $sub['pokok'];
        }

        if ($statuses !== []) {
            $out[] = ['type' => 'grandtotal', 'label' => 'Grand Total', 'cells' => $this->roundCells($grand['cells']), 'total' => ['luas' => round($grand['luas'], 2), 'pokok' => $grand['pokok']]];
        }

        return response()->json(['afds' => $afds, 'rows' => $out]);
    }

    private function roundCells(array $cells): array
    {
        foreach ($cells as $k => $v) {
            $cells[$k] = ['luas' => round($v['luas'], 2), 'pokok' => (int) $v['pokok']];
        }

        return $cells;
    }
}
