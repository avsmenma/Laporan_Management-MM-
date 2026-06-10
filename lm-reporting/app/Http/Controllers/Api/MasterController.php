<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MasterController extends Controller
{
    /**
     * Dropdown unit dengan filter type & komoditi.
     *
     * GET /api/units?type=KEBUN&komoditi=KS
     */
    public function units(Request $request): JsonResponse
    {
        $query = RefUnit::query();

        if ($request->filled('type')) {
            $query->where('type', strtoupper($request->type));
        }

        if ($request->filled('komoditi')) {
            $komoditi = strtoupper($request->komoditi);

            if ($request->type === 'KEBUN') {
                // Untuk kebun, filter via relasi komoditis
                $query->whereHas('komoditis', fn ($q) => $q->where('komoditi', $komoditi));
            } else {
                // Untuk pabrik, filter langsung di ref_unit.komoditi
                $query->where('komoditi', $komoditi);
            }
        }

        if ($request->filled(['batch', 'report_type'])) {
            $reportTable = match (strtoupper((string) $request->report_type)) {
                'LM13' => 'report_lm13',
                'LM14' => 'report_lm14',
                'LM16' => 'report_lm16',
                default => null,
            };

            if ($reportTable !== null) {
                $unitIds = DB::table($reportTable)
                    ->where('batch_id', $request->batch)
                    ->distinct()
                    ->pluck('unit_id');

                $query->whereIn('id', $unitIds);
            }
        }

        $units = $query->orderBy('code')->get(['id', 'code', 'name', 'type', 'komoditi']);

        return response()->json([
            'success' => true,
            'data' => $units,
        ]);
    }

    /**
     * Dropdown batch/periode.
     *
     * GET /api/batches
     */
    public function batches(): JsonResponse
    {
        $batches = Batch::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get(['id', 'code', 'year', 'month', 'status', 'processed_at']);

        return response()->json([
            'success' => true,
            'data' => $batches->map(fn ($batch) => [
                'id' => $batch->id,
                'code' => $batch->code,
                'year' => $batch->year,
                'month' => $batch->month,
                'period' => $batch->month, // Alias untuk frontend
                'status' => $batch->status,
                'processed_at' => $batch->processed_at?->format('Y-m-d H:i:s'),
                'label' => "{$batch->code} (Periode {$batch->month}/{$batch->year})",
            ]),
        ]);
    }
}
