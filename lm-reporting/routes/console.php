<?php

use App\Domain\Report\Lm13Service;
use App\Domain\Report\Lm14Service;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('report:generate {--type=} {--batch=} {--unit=} {--komoditi=KS}', function (Lm13Service $lm13Service, Lm14Service $lm14Service): int {
    $type = strtoupper((string) $this->option('type'));
    $batchInput = (string) $this->option('batch');
    $unitCode = $this->option('unit');
    $komoditi = strtoupper((string) $this->option('komoditi'));

    if (! in_array($type, ['LM13', 'LM14'], true)) {
        $this->error('Saat ini command report:generate mendukung --type=LM13 atau --type=LM14.');

        return 1;
    }

    if ($batchInput === '') {
        $this->error('Opsi --batch wajib diisi dengan id atau kode batch.');

        return 1;
    }

    $batch = Batch::query()
        ->where('id', is_numeric($batchInput) ? (int) $batchInput : 0)
        ->orWhere('code', $batchInput)
        ->first();

    if (! $batch) {
        $this->error("Batch {$batchInput} tidak ditemukan.");

        return 1;
    }

    $units = RefUnit::query()
        ->where('type', 'KEBUN')
        ->when($unitCode, fn ($query) => $query->where('code', $unitCode))
        ->whereHas('komoditis', fn ($query) => $query->where('komoditi', $komoditi))
        ->orderBy('code')
        ->get();

    if ($units->isEmpty()) {
        $this->error('Tidak ada unit kebun yang cocok dengan filter command.');

        return 1;
    }

    foreach ($units as $unit) {
        $rows = $type === 'LM13'
            ? $lm13Service->generate($batch, $unit, $komoditi)
            : $lm14Service->generate($batch, $unit, $komoditi);

        $this->info("{$type} {$unit->code} {$komoditi}: {$rows->count()} baris dimaterialisasi.");
    }

    return 0;
})->purpose('Generate materialized report LM.');
