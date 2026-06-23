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

        try {
            if ($isBudget) {
                $result = $service->importBudget((int) $job->year, $job->type, $path, $job->user_id, $onProgress);
                Batch::query()->where('year', $job->year)->update(['needs_regenerate' => true]);
            } else {
                $batch = Batch::query()->firstOrCreate(
                    ['year' => $job->year, 'month' => $job->month],
                    ['code' => "Batch #{$job->year}-".str_pad((string) $job->month, 2, '0', STR_PAD_LEFT), 'status' => 'draft', 'needs_regenerate' => true],
                );
                $result = $service->import($job->type, $batch, $path, $job->user_id, $onProgress);
                $batch->forceFill(['needs_regenerate' => true])->save();
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
    }
}
