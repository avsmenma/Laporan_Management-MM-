<?php

use App\Domain\Report\Lm13Service;
use App\Domain\Report\Lm14Service;
use App\Domain\Report\Lm16Service;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('report:generate {--type=} {--batch=} {--unit=} {--komoditi=KS}', function (Lm13Service $lm13Service, Lm14Service $lm14Service, Lm16Service $lm16Service): int {
    $type = strtoupper((string) $this->option('type'));
    $batchInput = (string) $this->option('batch');
    $unitCode = $this->option('unit');
    $komoditi = strtoupper((string) $this->option('komoditi'));

    if (! in_array($type, ['LM13', 'LM14', 'LM16'], true)) {
        $this->error('Command report:generate mendukung --type=LM13, LM14, atau LM16.');

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

    // LM16 untuk PABRIK (tidak perlu komoditi filter di whereHas)
    if ($type === 'LM16') {
        $units = RefUnit::query()
            ->where('type', 'PABRIK')
            ->when($unitCode, fn ($query) => $query->where('code', $unitCode))
            ->when($komoditi, fn ($query) => $query->where('komoditi', $komoditi))
            ->orderBy('code')
            ->get();

        if ($units->isEmpty()) {
            $this->error('Tidak ada unit pabrik yang cocok dengan filter command.');

            return 1;
        }

        foreach ($units as $unit) {
            $rows = $lm16Service->generate($batch, $unit);
            $this->info("{$type} {$unit->code}: {$rows->count()} baris dimaterialisasi.");
        }

        return 0;
    }

    // LM13 & LM14 untuk KEBUN
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
