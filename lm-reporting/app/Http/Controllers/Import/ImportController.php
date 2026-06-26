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
     *
     * - Realisasi (wbs/ohc/gc): wajib year; month boleh kosong → auto-detect dari file.
     * - Anggaran (rko_bku/rko_ohc/rko_gc): wajib year saja; tidak memerlukan month & batch.
     */
    public function store(Request $request, SpreadsheetImportService $service): View|RedirectResponse
    {
        $type = (string) $request->input('type');
        $isBudget = SpreadsheetImportService::isBudget($type);
        $isProduksi = SpreadsheetImportService::isProduksi($type);

        $rules = [
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            // Budget & produksi: bulan WAJIB (dipakai sebagai penjaga/filter period).
            // Realisasi/areal: boleh kosong → auto-deteksi dari file.
            'month' => ($isBudget || $isProduksi)
                ? ['required', 'integer', 'min:1', 'max:12']
                : ['nullable', 'integer', 'min:1', 'max:12'],
        ];
        $data = $request->validate($rules);

        $file = $request->file('file');
        $token = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $stored = $file->storeAs('import-staging', "{$token}.{$ext}", 'local');
        $path = Storage::disk('local')->path($stored);

        try {
            // Pratinjau dari sheet pertama (anggaran & realisasi); areal baca sheet "DB".
            $preview = $service->preview($type, $path);
            $detectedMonths = $isBudget ? [] : $service->detectPeriods($path, $type);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);

            return back()->with([
                'status'        => 'Gagal membaca file: '.$e->getMessage(),
                'import_errors' => [],
            ]);
        }

        // Bulan: pakai input user bila ada; jika kosong & terdeteksi tepat 1 → pakai itu.
        $month = isset($data['month']) ? (int) $data['month'] : null;
        if (! $isBudget && $month === null && count($detectedMonths) === 1) {
            $month = $detectedMonths[0];
        }

        return view('import.index', [
            ...$this->indexData(),
            'preview'         => $preview,
            'detected_months' => $detectedMonths,
            'pending'         => [
                'token'     => $token,
                'ext'       => $ext,
                'type'      => $type,
                'year'      => (int) $data['year'],
                'month'     => $month,
                'is_budget' => $isBudget,
                'filename'  => $file->getClientOriginalName(),
            ],
        ]);
    }

    /**
     * Langkah 2: konfirmasi — buat import_jobs + dispatch ProcessImport (async).
     *
     * Mengembalikan JSON 202 dengan job_id dan status_url untuk polling.
     * File staging TIDAK dihapus di sini; job yang menghapusnya setelah selesai.
     */
    public function confirm(Request $request): \Illuminate\Http\JsonResponse
    {
        $type = (string) $request->input('type');

        $rules = [
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext'   => ['required', 'in:xlsx,xls,csv'],
            'type'  => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            // Bulan kini wajib untuk semua jenis import (penjaga period / batch bulan).
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ];
        $data = $request->validate($rules);

        $staged = "import-staging/{$data['token']}.{$data['ext']}";
        if (! Storage::disk('local')->exists($staged)) {
            return response()->json(['message' => 'Berkas pratinjau kedaluwarsa. Unggah ulang.'], 422);
        }

        $job = \App\Models\ImportJob::query()->create([
            'user_id'     => $request->user()->id,
            'type'        => $type,
            'year'        => (int) $data['year'],
            'month'       => (int) $data['month'],
            'filename'    => "{$data['token']}.{$data['ext']}",
            'staged_path' => $staged,
            'ext'         => $data['ext'],
            'status'      => 'queued',
        ]);

        \App\Jobs\ProcessImport::dispatch($job->id);

        return response()->json([
            'job_id'     => $job->id,
            'status_url' => route('import.status', $job),
        ], 202);
    }

    /**
     * Polling status untuk import job yang sedang berjalan.
     * Hanya pemilik job atau Admin yang boleh melihat (cegah IDOR antar-operator).
     */
    public function status(Request $request, \App\Models\ImportJob $importJob): \Illuminate\Http\JsonResponse
    {
        abort_unless(
            $importJob->user_id === $request->user()->id || $request->user()->hasRole('Admin'),
            403,
        );

        return response()->json([
            'status'    => $importJob->status,
            'processed' => $importJob->processed,
            'total'     => $importJob->total,
            'row_count' => $importJob->row_count,
            'error'     => $importJob->error,
        ]);
    }

    /**
     * Batalkan pratinjau: hapus berkas yang ditahan.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext'   => ['required', 'in:xlsx,xls,csv'],
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
            'types'   => SpreadsheetImportService::types(),
            'logs'    => ImportUploadLog::query()
                ->with(['batch', 'user'])
                ->latest('uploaded_at')
                ->limit(20)
                ->get(),
        ];
    }
}
