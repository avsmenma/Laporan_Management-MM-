<?php

namespace App\Http\Controllers\Report;

use App\Domain\Report\ReportGenerateService;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProsesLaporanController extends Controller
{
    public function store(Request $request, ReportGenerateService $generator): RedirectResponse
    {
        $data = $request->validate(['batch_id' => ['required', 'exists:batch,id']]);
        $batch = Batch::query()->findOrFail($data['batch_id']);

        $summary = $generator->generateBatch($batch);

        return back()->with('status', "Proses laporan {$batch->code} selesai: "
            ."LM14={$summary['lm14']}, LM13={$summary['lm13']}, LM16={$summary['lm16']} ({$summary['units']} unit).");
    }
}
