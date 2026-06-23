<?php

namespace App\Domain\Report;

use App\Models\Batch;
use App\Models\RefUnit;

class ReportGenerateService
{
    public function __construct(
        private Lm13Service $lm13,
        private Lm14Service $lm14,
        private Lm16Service $lm16,
    ) {}

    /**
     * Materialisasi seluruh report (LM14, LM13, LM16) untuk satu batch:
     * LM14/LM13 = semua unit KEBUN per komoditi; LM16 = semua unit PABRIK.
     *
     * Setelah selesai, menandai batch.processed_at = now() dan batch.needs_regenerate = false.
     *
     * @return array{lm14: int, lm13: int, lm16: int, units: int, detail: array<string, int>}
     */
    public function generateBatch(Batch $batch): array
    {
        $detail  = [];
        $totals  = ['lm14' => 0, 'lm13' => 0, 'lm16' => 0];
        $unitIds = [];

        // KEBUN: LM14 & LM13 — satu kali per komoditi yang dimiliki unit.
        $kebunUnits = RefUnit::query()
            ->where('type', 'KEBUN')
            ->with('komoditis')
            ->orderBy('code')
            ->get();

        foreach ($kebunUnits as $unit) {
            $unitIds[$unit->id] = true;

            foreach ($unit->komoditis as $km) {
                $kom = strtoupper((string) $km->komoditi);

                $c14 = $this->lm14->generate($batch, $unit, $kom)->count();
                $c13 = $this->lm13->generate($batch, $unit, $kom)->count();

                $totals['lm14'] += $c14;
                $totals['lm13'] += $c13;

                $detail["LM14 {$unit->code} {$kom}"] = $c14;
                $detail["LM13 {$unit->code} {$kom}"] = $c13;
            }
        }

        // PABRIK: LM16.
        $pabrikUnits = RefUnit::query()
            ->where('type', 'PABRIK')
            ->orderBy('code')
            ->get();

        foreach ($pabrikUnits as $unit) {
            $unitIds[$unit->id] = true;

            $c16 = $this->lm16->generate($batch, $unit)->count();
            $totals['lm16'] += $c16;

            $detail["LM16 {$unit->code}"] = $c16;
        }

        // Tandai batch sudah diproses.
        $batch->forceFill([
            'processed_at'     => now(),
            'needs_regenerate' => false,
        ])->save();

        return [
            ...$totals,
            'units'  => count($unitIds),
            'detail' => $detail,
        ];
    }
}
