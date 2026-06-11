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
     * Baris "Gaji/Upah & Biaya Kary. Staf dari WBS" memakai kode LM 99-01.
     * Workbook sumber menghitung bulan ini dan s.d. bulan ini dari db_btl cost
     * center SP01, sedangkan kolom bulan lalu memakai db_wbs aktivitas 99-01.
     */
    private const STAF_GAJI_KODE = '99-01';

    private const STAF_GAJI_BTL_CC = 'SP01';

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
        if ($this->isStafGaji($template)) {
            return $this->sumStafGajiBtl($unit, $komoditi, function ($query) use ($batch): void {
                $query->where('batch_id', $batch->id)
                    ->where('period', $batch->month);
            });
        }

        return (float) $this->sourceQuery($template->source, $template->kode)
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $batch->month)
            ->sum('nilai');
    }

    private function sumSourceYearPeriod(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template, int $period): float
    {
        if ($this->isStafGaji($template)) {
            return $this->sumStafGajiWbs($unit, $komoditi, function ($query) use ($batch, $period): void {
                $query->join('batch', 'db_wbs.batch_id', '=', 'batch.id')
                    ->where('batch.year', $batch->year)
                    ->where('period', $period);
            });
        }

        return (float) $this->sourceQuery($template->source, $template->kode)
            ->join('batch', $this->sourceTable($template->source).'.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $period)
            ->sum('nilai');
    }

    private function sumSourceBeforeMonth(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template): float
    {
        if ($this->isStafGaji($template)) {
            return $this->sumStafGajiBtl($unit, $komoditi, function ($query) use ($batch): void {
                $query->join('batch', 'db_btl.batch_id', '=', 'batch.id')
                    ->where('batch.year', $batch->year)
                    ->where('period', '<', $batch->month);
            });
        }

        return (float) $this->sourceQuery($template->source, $template->kode)
            ->join('batch', $this->sourceTable($template->source).'.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', '<', $batch->month)
            ->sum('nilai');
    }

    private function isStafGaji(LmTemplateRow $template): bool
    {
        return $template->source === 'BTL' && $template->kode === self::STAF_GAJI_KODE;
    }

    /**
     * Realisasi bulan ini/s.d. bulan ini baris "Gaji Staf dari WBS"
     * mengikuti db_btl cost center SP01.
     */
    private function sumStafGajiBtl(RefUnit $unit, string $komoditi, callable $scope): float
    {
        $btl = DB::table('db_btl')
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('kode_cc', self::STAF_GAJI_BTL_CC);

        $scope($btl);

        return (float) $btl->sum('nilai');
    }

    /**
     * Kolom bulan lalu untuk baris "Gaji Staf dari WBS" mengikuti db_wbs
     * aktivitas 99-01 pada periode sebelumnya.
     */
    private function sumStafGajiWbs(RefUnit $unit, string $komoditi, callable $scope): float
    {
        $wbs = DB::table('db_wbs')
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('aktivitas', self::STAF_GAJI_KODE);

        $scope($wbs);

        return (float) $wbs->sum('nilai');
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
