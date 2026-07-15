<?php

namespace App\Jobs;

use App\Domain\Import\SpreadsheetImportService;
use App\Models\Batch;
use App\Models\ImportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $importJobId) {}

    public function handle(SpreadsheetImportService $service): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job === null) {
            return;
        }

        $path = Storage::disk('local')->path($job->staged_path);
        if (! Storage::disk('local')->exists($job->staged_path)) {
            $job->forceFill(['status' => 'failed', 'error' => 'Berkas staging tidak ditemukan.'])->save();

            return;
        }

        $isBudget = SpreadsheetImportService::isBudget($job->type);
        $job->forceFill(['status' => 'processing', 'total' => $service->rowCountForType($job->type, $path)])->save();

        // Throttle update DB: maksimal tiap 500 baris.
        $onProgress = function (int $n) use ($job): void {
            if ($n - $job->processed >= 500) {
                $job->forceFill(['processed' => $n])->save();
            }
        };

        // Batch yang terdampak impor ini → diregenerasi otomatis setelah sukses.
        $affected = [];

        try {
            $month = $job->month !== null ? (int) $job->month : null;
            if (SpreadsheetImportService::isProduksiKebun($job->type)) {
                $result = $service->importProduksiKebun($path, $job->user_id, $onProgress, (int) $job->year, $month);
                $affected = $this->batchIdsForPeriod((int) $job->year, $month);
            } elseif (SpreadsheetImportService::isPembelianTbs($job->type)) {
                // Seluruh periode pada tahun terpilih diimpor (file setahun berjalan);
                // tidak menyentuh report batch → tanpa regenerasi.
                $result = $service->importPembelianTbs($path, $job->user_id, $onProgress, (int) $job->year);
            } elseif (SpreadsheetImportService::isPenjualanProduk($job->type)) {
                // Sama seperti pembelian TBS: multi-periode per tahun, tanpa regenerasi.
                $result = $service->importPenjualanProduk($path, $job->user_id, $onProgress, (int) $job->year);
            } elseif (SpreadsheetImportService::isProduksi($job->type)) {
                $result = $service->importProduksi($path, $job->user_id, $onProgress, (int) $job->year, $month);
                $affected = $this->batchIdsForPeriod((int) $job->year, $month);
                // Materialisasi ulang PRODUKSI CPO + INTI (Alokasi Biaya Olah) untuk
                // periode ini — otomatis mengikuti perubahan angka produksi.
                if ($month !== null) {
                    app(\App\Domain\Report\ProduksiCpoIntiService::class)->generate((int) $job->year, $month);
                } else {
                    app(\App\Domain\Report\ProduksiCpoIntiService::class)->generateAll();
                }
            } elseif ($isBudget) {
                $result = $service->importBudget((int) $job->year, $job->type, $path, $job->user_id, $onProgress, $month);
                // Anggaran terkunci per tahun → seluruh batch tahun itu perlu regenerasi.
                Batch::query()->where('year', $job->year)->update(['needs_regenerate' => true]);
                $affected = $this->batchIdsForPeriod((int) $job->year, null);
            } else {
                $batch = Batch::query()->firstOrCreate(
                    ['year' => $job->year, 'month' => $job->month],
                    ['code' => "Batch #{$job->year}-".str_pad((string) $job->month, 2, '0', STR_PAD_LEFT), 'status' => 'draft', 'needs_regenerate' => true],
                );
                $result = $service->import($job->type, $batch, $path, $job->user_id, $onProgress);
                $batch->forceFill(['needs_regenerate' => true])->save();
                $affected = [$batch->id];
            }

            $job->forceFill([
                'status' => 'done',
                'processed' => $result->rowCount,
                'row_count' => $result->rowCount,
            ])->save();
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
        } finally {
            Storage::disk('local')->delete($job->staged_path);
        }

        // Regenerasi laporan otomatis (di-antrikan, hanya bila impor sukses & ada batch).
        if ($job->status === 'done' && $affected !== []) {
            RegenerateReports::dispatch(array_values(array_unique($affected)));
        }
    }

    /**
     * Batch untuk satu periode. $month null → semua bulan di tahun itu.
     *
     * @return array<int, int>
     */
    private function batchIdsForPeriod(int $year, ?int $month): array
    {
        $q = Batch::query()->where('year', $year);
        if ($month !== null) {
            $q->where('month', $month);
        }

        return $q->pluck('id')->all();
    }
}
