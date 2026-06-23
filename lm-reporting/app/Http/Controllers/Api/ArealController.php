<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArealController extends Controller
{
    private const STATUS_ORDER = ['ATTP', 'TBM', 'TM', 'TU'];

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->query('year');
        $month = (int) $request->query('month');
        $komoditi = strtoupper((string) $request->query('komoditi', 'KS'));
        $unit = (string) $request->query('unit');

        $batch = Batch::query()->where('year', $year)->where('month', $month)->first();
        if (! $batch) {
            return response()->json(['afds' => [], 'rows' => []]);
        }

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

        $emptyCells = fn () => array_fill_keys($afds, ['luas' => 0, 'pokok' => 0]);
        $out = [];
        $grand = ['cells' => $emptyCells(), 'luas' => 0, 'pokok' => 0];

        foreach ($statuses as $s) {
            $years = array_keys($agg[$s]);
            sort($years);
            $sub = ['cells' => $emptyCells(), 'luas' => 0, 'pokok' => 0];
            foreach ($years as $t) {
                $cells = $emptyCells();
                $rowTot = ['luas' => 0, 'pokok' => 0];
                foreach ($afds as $a) {
                    $luas = round($agg[$s][$t][$a]['luas'] ?? 0, 2);
                    $pokok = (int) ($agg[$s][$t][$a]['pokok'] ?? 0);
                    $cells[$a] = ['luas' => $luas, 'pokok' => $pokok];
                    $rowTot['luas'] += $luas;
                    $rowTot['pokok'] += $pokok;
                    $sub['cells'][$a]['luas'] += $luas;
                    $sub['cells'][$a]['pokok'] += $pokok;
                    $grand['cells'][$a]['luas'] += $luas;
                    $grand['cells'][$a]['pokok'] += $pokok;
                }
                $rowTot['luas'] = round($rowTot['luas'], 2);
                $sub['luas'] += $rowTot['luas'];
                $sub['pokok'] += $rowTot['pokok'];
                $out[] = ['type' => 'detail', 'status' => $s, 'tahun_tanam' => $t === 0 ? null : $t, 'cells' => $cells, 'total' => $rowTot];
            }
            $sub['luas'] = round($sub['luas'], 2);
            $out[] = ['type' => 'subtotal', 'status' => $s, 'label' => $s.' Total', 'cells' => $this->roundCells($sub['cells']), 'total' => ['luas' => $sub['luas'], 'pokok' => $sub['pokok']]];
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
