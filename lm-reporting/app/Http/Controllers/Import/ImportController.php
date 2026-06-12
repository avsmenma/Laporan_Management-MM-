<?php

namespace App\Http\Controllers\Import;

use App\Domain\Import\SpreadsheetImportService;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\ImportUploadLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function index(): View
    {
        return view('import.index', $this->indexData());
    }

    /**
     * Langkah 1: unggah file lalu tampilkan PRATINJAU (belum disimpan ke database).
     */
    public function store(Request $request, SpreadsheetImportService $service): View|RedirectResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'exists:batch,id'],
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $file = $request->file('file');
        $token = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $stored = $file->storeAs('import-staging', "{$token}.{$ext}", 'local');

        try {
            $preview = $service->preview($data['type'], Storage::disk('local')->path($stored));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);

            return back()->with([
                'status' => 'Gagal membaca file untuk pratinjau: '.$exception->getMessage(),
                'import_errors' => [],
            ]);
        }

        return view('import.index', [
            ...$this->indexData(),
            'preview' => $preview,
            'pending' => [
                'token' => $token,
                'ext' => $ext,
                'type' => $data['type'],
                'batch_id' => (int) $data['batch_id'],
                'filename' => $file->getClientOriginalName(),
            ],
        ]);
    }

    /**
     * Langkah 2: konfirmasi — baca ulang file yang ditahan lalu simpan ke database.
     */
    public function confirm(Request $request, SpreadsheetImportService $service): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext' => ['required', 'in:xlsx,xls,csv'],
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'batch_id' => ['required', 'exists:batch,id'],
        ]);

        $stored = "import-staging/{$data['token']}.{$data['ext']}";
        if (! Storage::disk('local')->exists($stored)) {
            return redirect()->route('import.index')->with([
                'status' => 'Berkas pratinjau tidak ditemukan (mungkin sudah kedaluwarsa). Silakan unggah ulang.',
                'import_errors' => [],
            ]);
        }

        $batch = Batch::query()->findOrFail($data['batch_id']);

        try {
            $result = $service->import($data['type'], $batch, Storage::disk('local')->path($stored), $request->user()->id);
        } finally {
            Storage::disk('local')->delete($stored);
        }

        return redirect()->route('import.index')->with([
            'status' => "Import selesai: {$result->rowCount} baris tersimpan, {$result->errorCount()} error.",
            'import_errors' => $result->errors,
        ]);
    }

    /**
     * Batalkan pratinjau: hapus berkas yang ditahan.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext' => ['required', 'in:xlsx,xls,csv'],
        ]);

        Storage::disk('local')->delete("import-staging/{$data['token']}.{$data['ext']}");

        return redirect()->route('import.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function indexData(): array
    {
        return [
            'batches' => Batch::query()->orderByDesc('year')->orderByDesc('month')->get(),
            'types' => SpreadsheetImportService::types(),
            'logs' => ImportUploadLog::query()
                ->with(['batch', 'user'])
                ->latest('uploaded_at')
                ->limit(20)
                ->get(),
        ];
    }
}
