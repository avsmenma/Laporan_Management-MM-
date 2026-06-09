<?php

namespace App\Http\Controllers\Import;

use App\Domain\Import\SpreadsheetImportService;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\ImportUploadLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function index(): View
    {
        return view('import.index', [
            'batches' => Batch::query()->orderByDesc('year')->orderByDesc('month')->get(),
            'types' => SpreadsheetImportService::types(),
            'logs' => ImportUploadLog::query()
                ->with(['batch', 'user'])
                ->latest('uploaded_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(Request $request, SpreadsheetImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'exists:batch,id'],
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $batch = Batch::query()->findOrFail($data['batch_id']);
        $result = $service->import($data['type'], $batch, $request->file('file'), $request->user()->id);

        return back()->with([
            'status' => "Import selesai: {$result->rowCount} baris tersimpan, {$result->errorCount()} error.",
            'import_errors' => $result->errors,
        ]);
    }
}
