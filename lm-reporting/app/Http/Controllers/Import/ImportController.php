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

        $rules = [
            'type' => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
        if (! $isBudget) {
            $rules['month'] = ['nullable', 'integer', 'min:1', 'max:12'];
        }
        $data = $request->validate($rules);

        $file = $request->file('file');
        $token = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $stored = $file->storeAs('import-staging', "{$token}.{$ext}", 'local');
        $path = Storage::disk('local')->path($stored);

        try {
            if ($isBudget) {
                // Anggaran: tidak ada pratinjau tabel berat; cukup ringkasan ringan.
                $preview = [
                    'type'    => $type,
                    'label'   => SpreadsheetImportService::types()[$type],
                    'columns' => [],
                    'rows'    => [],
                    'total'   => 0,
                    'budget'  => true,
                ];
                $detectedMonths = [];
            } else {
                $preview = $service->preview($type, $path);
                $detectedMonths = $service->detectPeriods($path, $type);
            }
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
                'month'     => $isBudget ? null : $month,
                'is_budget' => $isBudget,
                'filename'  => $file->getClientOriginalName(),
            ],
        ]);
    }

    /**
     * Langkah 2: konfirmasi — baca ulang file yang ditahan lalu simpan ke database.
     *
     * - Anggaran: panggil importBudget(year, type, path, uid), tandai semua batch tahun itu.
     * - Realisasi: resolve/buat batch dari year+month, panggil import(), tandai batch.
     */
    public function confirm(Request $request, SpreadsheetImportService $service): RedirectResponse
    {
        $type = (string) $request->input('type');
        $isBudget = SpreadsheetImportService::isBudget($type);

        $rules = [
            'token' => ['required', 'regex:/^[0-9a-fA-F\-]{36}$/'],
            'ext'   => ['required', 'in:xlsx,xls,csv'],
            'type'  => ['required', 'in:'.implode(',', array_keys(SpreadsheetImportService::types()))],
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
        if (! $isBudget) {
            $rules['month'] = ['required', 'integer', 'min:1', 'max:12'];
        }
        $data = $request->validate($rules);

        $stored = "import-staging/{$data['token']}.{$data['ext']}";
        if (! Storage::disk('local')->exists($stored)) {
            return redirect()->route('import.index')->with([
                'status'        => 'Berkas pratinjau kedaluwarsa. Unggah ulang.',
                'import_errors' => [],
            ]);
        }
        $path = Storage::disk('local')->path($stored);
        $uid = $request->user()->id;

        try {
            if ($isBudget) {
                $result = $service->importBudget((int) $data['year'], $type, $path, $uid);
                // Tandai semua batch tahun itu perlu diproses ulang.
                Batch::query()->where('year', $data['year'])->update(['needs_regenerate' => true]);
                $msg = "Import anggaran {$type} selesai: {$result->rowCount} baris budget.";
            } else {
                $batch = $this->resolveBatch((int) $data['year'], (int) $data['month']);
                $result = $service->import($type, $batch, $path, $uid);
                $batch->forceFill(['needs_regenerate' => true])->save();
                $msg = "Import {$type} ke {$batch->code} selesai: {$result->rowCount} baris.";
            }
        } finally {
            Storage::disk('local')->delete($stored);
        }

        return redirect()->route('import.index')->with([
            'status'        => $msg,
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
            'ext'   => ['required', 'in:xlsx,xls,csv'],
        ]);

        Storage::disk('local')->delete("import-staging/{$data['token']}.{$data['ext']}");

        return redirect()->route('import.index');
    }

    /**
     * Cari atau buat batch (status draft) berdasarkan year+month.
     * Unique constraint (year, month) dijaga oleh firstOrCreate.
     */
    private function resolveBatch(int $year, int $month): Batch
    {
        return Batch::query()->firstOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'code'             => "Batch #{$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                'status'           => 'draft',
                'needs_regenerate' => true,
            ],
        );
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
