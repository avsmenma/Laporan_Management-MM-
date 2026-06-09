<?php

namespace App\Domain\Report;

use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Models\RefUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Lm14Service
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function generate(Batch $batch, RefUnit $unit, string $komoditi): Collection
    {
        $templates = LmTemplateRow::query()
            ->where('report_type', 'LM14')
            ->where('komoditi', $komoditi)
            ->orderBy('urutan')
            ->get();

        $rows = collect();

        foreach ($templates as $template) {
            $values = match ($template->row_type) {
                'detail' => $this->detailValues($batch, $unit, $komoditi, $template),
                'subtotal', 'total' => $this->formulaValues($template->formula, $rows),
                default => $this->zeroValues(),
            };

            $rows->put($template->urutan, [
                ...$values,
                ...$this->achievementValues($values),
                'batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'komoditi' => $komoditi,
                'template_id' => $template->id,
            ]);
        }

        DB::transaction(function () use ($batch, $unit, $komoditi, $rows): void {
            DB::table('report_lm14')
                ->where('batch_id', $batch->id)
                ->where('unit_id', $unit->id)
                ->where('komoditi', $komoditi)
                ->delete();

            if ($rows->isNotEmpty()) {
                DB::table('report_lm14')->insert($rows->values()->all());
            }
        });

        return $rows->values();
    }

    /**
     * @return array<string, float>
     */
    private function detailValues(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template): array
    {
        if ($template->kode === null || $template->source === null) {
            return $this->zeroValues();
        }

        $realBulanIni = $this->sumSourceCurrentBatch($batch, $unit, $komoditi, $template);
        $realBulanLalu = $batch->month > 1
            ? $this->sumSourceYearPeriod($batch, $unit, $komoditi, $template, $batch->month - 1)
            : 0.0;
        $realTahunLalu = $this->sumTahunLalu($batch, $unit, $komoditi, $template->kode, $batch->month);
        $rko = $this->sumBudget('budget_rko', $batch, $unit, $komoditi, $template->kode);
        $rkap = $this->sumBudget('budget_rkap', $batch, $unit, $komoditi, $template->kode);
        $realSebelumBulanIni = $this->sumSourceBeforeMonth($batch, $unit, $komoditi, $template);

        return [
            'real_bulan_ini' => $realBulanIni,
            'real_bulan_lalu' => $realBulanLalu,
            'real_tahun_lalu' => $realTahunLalu,
            'rko' => $rko,
            'rkap' => $rkap,
            'real_sd_bulan_ini' => $realBulanIni + $realSebelumBulanIni,
            'real_sd_tahunlalu' => $this->sumTahunLaluSd($batch, $unit, $komoditi, $template->kode),
            'rko_sd' => $rko,
            'rkap_sd' => $rkap,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function formulaValues(?string $formula, Collection $rows): array
    {
        $values = $this->zeroValues();

        foreach ($this->formulaUrutans($formula) as $urutan) {
            $source = $rows->get($urutan);
            if ($source === null) {
                continue;
            }

            foreach (array_keys($values) as $column) {
                $values[$column] += (float) ($source[$column] ?? 0);
            }
        }

        return $values;
    }

    /**
     * @return array<int, int>
     */
    private function formulaUrutans(?string $formula): array
    {
        if ($formula === null) {
            return [];
        }

        preg_match_all('/u(\d+)/i', $formula, $matches);

        return array_map('intval', $matches[1] ?? []);
    }

    /**
     * @return array<string, float>
     */
    private function zeroValues(): array
    {
        return [
            'real_bulan_ini' => 0.0,
            'real_bulan_lalu' => 0.0,
            'real_tahun_lalu' => 0.0,
            'rko' => 0.0,
            'rkap' => 0.0,
            'real_sd_bulan_ini' => 0.0,
            'real_sd_tahunlalu' => 0.0,
            'rko_sd' => 0.0,
            'rkap_sd' => 0.0,
        ];
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    private function achievementValues(array $values): array
    {
        return [
            'cap_bi_lalu' => $this->percent($values['real_bulan_ini'], $values['real_bulan_lalu']),
            'cap_bi_thnlalu' => $this->percent($values['real_bulan_ini'], $values['real_tahun_lalu']),
            'cap_bi_rko' => $this->percent($values['real_bulan_ini'], $values['rko']),
            'cap_bi_rkap' => $this->percent($values['real_bulan_ini'], $values['rkap']),
            'cap_sd_thnlalu' => $this->percent($values['real_sd_bulan_ini'], $values['real_sd_tahunlalu']),
            'cap_sd_rko' => $this->percent($values['real_sd_bulan_ini'], $values['rko_sd']),
            'cap_sd_rkap' => $this->percent($values['real_sd_bulan_ini'], $values['rkap_sd']),
        ];
    }

    private function percent(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function sumSourceCurrentBatch(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template): float
    {
        $value = $this->sourceQuery($template->source, $template->kode)
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $batch->month)
            ->sum('nilai');

        return $this->withGajiStafFallback($value, $template, fn () => DB::table('db_wbs')
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $batch->month)
            ->where('aktivitas', $template->kode)
            ->where('cost_element', '90042012')
            ->sum('nilai'));
    }

    private function sumSourceYearPeriod(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template, int $period): float
    {
        $value = $this->sourceQuery($template->source, $template->kode)
            ->join('batch', $this->sourceTable($template->source).'.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $period)
            ->sum('nilai');

        return $this->withGajiStafFallback($value, $template, fn () => DB::table('db_wbs')
            ->join('batch', 'db_wbs.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $period)
            ->where('aktivitas', $template->kode)
            ->where('cost_element', '90042012')
            ->sum('nilai'));
    }

    private function sumSourceBeforeMonth(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template): float
    {
        $value = $this->sourceQuery($template->source, $template->kode)
            ->join('batch', $this->sourceTable($template->source).'.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', '<', $batch->month)
            ->sum('nilai');

        return $this->withGajiStafFallback($value, $template, fn () => DB::table('db_wbs')
            ->join('batch', 'db_wbs.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', '<', $batch->month)
            ->where('aktivitas', $template->kode)
            ->where('cost_element', '90042012')
            ->sum('nilai'));
    }

    private function withGajiStafFallback(float $value, LmTemplateRow $template, callable $fallback): float
    {
        if ($value != 0.0 || $template->source !== 'BTL' || $template->kode !== '99-01') {
            return $value;
        }

        return (float) $fallback();
    }

    private function sourceQuery(?string $source, ?string $kode): \Illuminate\Database\Query\Builder
    {
        $table = $this->sourceTable($source);
        $codeColumn = match (true) {
            $source === 'BTL' && str_starts_with((string) $kode, '511') => 'cost_element',
            $source === 'BTL' => 'kode_cc',
            default => 'aktivitas',
        };

        return DB::table($table)->where($codeColumn, $kode);
    }

    private function sourceTable(?string $source): string
    {
        return $source === 'BTL' ? 'db_btl' : 'db_wbs';
    }

    private function sumTahunLalu(Batch $batch, RefUnit $unit, string $komoditi, string $kode, int $period): float
    {
        return DB::table('realisasi_tahun_lalu')
            ->where('year', $batch->year - 1)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM14')
            ->where('kode', $kode)
            ->where('period', $period)
            ->sum('nilai');
    }

    private function sumTahunLaluSd(Batch $batch, RefUnit $unit, string $komoditi, string $kode): float
    {
        return DB::table('realisasi_tahun_lalu')
            ->where('year', $batch->year - 1)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM14')
            ->where('kode', $kode)
            ->where('period', '<=', $batch->month)
            ->sum('nilai');
    }

    private function sumBudget(string $table, Batch $batch, RefUnit $unit, string $komoditi, string $kode): float
    {
        return DB::table($table)
            ->where('year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM14')
            ->where('kode', $kode)
            ->sum('nilai');
    }
}
