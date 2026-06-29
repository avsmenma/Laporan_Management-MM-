<?php

namespace App\Jobs;

use App\Domain\Report\ReportGenerateService;
use App\Models\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Regenerasi (materialisasi ulang) laporan LM13/LM14/LM16 untuk sejumlah batch.
 *
 * Dipakai agar perubahan sumber data (impor / hapus selektif) otomatis tercermin di
 * laporan TANPA user menekan "Proses Laporan" manual. Nilai RKO/RKAP, Real, Tahun Lalu
 * dll. disimpan (materialized) di report_lm14/13/16 saat generate; menghapus/menambah
 * sumber tidak mengubah nilai tersimpan sampai laporan digenerate ulang.
 *
 * Berat (~1-2 menit/batch pada VPS 1-core) → WAJIB lewat queue (lm-reporting-worker).
 */
class RegenerateReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    /** @param  array<int, int>  $batchIds */
    public function __construct(public array $batchIds) {}

    public function handle(ReportGenerateService $generator): void
    {
        Batch::query()
            ->whereIn('id', $this->batchIds)
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->each(fn (Batch $batch) => $generator->generateBatch($batch));
    }
}
