<?php

namespace App\Domain\Report;

use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Models\RefUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Lm16Service
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function generate(Batch $batch, RefUnit $unit): Collection
    {
        $templates = LmTemplateRow::query()
            ->where('report_type', 'LM16')
            ->where(fn ($query) => $query
                ->where('komoditi', $unit->komoditi)
                ->orWhereNull('komoditi'))
            ->orderBy('urutan')
            ->get();

        $productionData = $this->productionData($batch, $unit);
        $costData = $this->costData($batch, $unit);
        $rows = collect();

        foreach ($templates as $template) {
            $values = match (true) {
                $template->row_type === 'header' => $this->zeroValues(),
                in_array($template->urutan, [9, 13, 32, 54, 56], true) => $this->subtotalValues($template->urutan, $rows),
                default => $this->detailValues($batch, $unit, $template, $productionData, $costData),
            };

            $rows->put($template->urutan, [
                ...$values,
                ...$this->derivedValues($values, $productionData),
                'batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'template_id' => $template->id,
            ]);
        }

        DB::transaction(function () use ($batch, $unit, $rows): void {
            DB::table('report_lm16')
                ->where('batch_id', $batch->id)
                ->where('unit_id', $unit->id)
                ->delete();

            if ($rows->isNotEmpty()) {
                DB::table('report_lm16')->insert($rows->values()->all());
            }
        });

        return $rows->values();
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function productionData(Batch $batch, RefUnit $unit): array
    {
        $labels = [
            'TBS dari Lapangan' => 'Jumlah Produksi TBS',
            'TBS Diolah' => 'Jumlah TBS Diolah',
            'Sisa Buah' => 'Jumlah Sisa Buah di Pabrik',
            'Minyak Sawit' => 'Jlh. Prod. Minyak Sawit',
            'Inti Sawit' => 'Jumlah Produksi Inti Sawit',
        ];

        $data = [];
        foreach ($labels as $alias => $uraian) {
            $current = $this->pksProductionRow($batch, $unit, $uraian, $batch->month);
            $previous = $batch->month > 1
                ? $this->pksProductionRow($batch, $unit, $uraian, $batch->month - 1)
                : ['nilai_bi' => 0.0, 'nilai_sd' => 0.0];

            $values = [
                'bi' => (float) $current['nilai_bi'],
                'sd' => (float) $current['nilai_sd'],
                'lalu' => (float) $previous['nilai_bi'],
            ];

            $data[$alias] = $values;
            $data[$uraian] = $values;
        }

        return $data;
    }

    /**
     * @return array{nilai_bi: float, nilai_sd: float}
     */
    private function pksProductionRow(Batch $batch, RefUnit $unit, string $uraian, int $period): array
    {
        $row = DB::table('pks_produksi')
            ->where('batch_id', $batch->id)
            ->where('plant_code', $unit->code)
            ->where('period', $period)
            ->where('uraian', $uraian)
            ->select('nilai_bi', 'nilai_sd')
            ->first();

        return [
            'nilai_bi' => (float) ($row->nilai_bi ?? 0),
            'nilai_sd' => (float) ($row->nilai_sd ?? 0),
        ];
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function costData(Batch $batch, RefUnit $unit): array
    {
        [$costCenterMap, $costElementMap] = self::accountMapArrays();

        // Dua ember terpisah supaya baris berlabel sama di dua grup tidak saling bocor:
        //  - 'pengolahan' (Biaya Langsung)   → hanya dari Cost Element (cost center STAS)
        //  - 'overhead'   (Biaya Tdk Lgsg)   → hanya dari Cost Center (BT../SUP) + catch-all
        // Acuan: sheet Pagun — mis. "Biaya Penerangan" pengolahan dari GL 51100601 dsb,
        // sedangkan "Biaya Penerangan" overhead dari cost center BT13.
        $zero = fn (): array => ['bi' => 0.0, 'sd' => 0.0, 'lalu' => 0.0];
        $pengolahan = [];
        $overhead = [];
        foreach ($costElementMap as $uraian) {
            $pengolahan[$uraian] ??= $zero();
        }
        foreach ($costCenterMap as $uraian) {
            $overhead[$uraian] ??= $zero();
        }
        $overhead['Pengeluaran Lainnya'] ??= $zero();

        // Kumulatif & bulan-lalu LINTAS-BATCH (satu impor pabrik = satu bulan). Ambil
        // seluruh batch di tahun yang sama, saring per period — pola sama LM14
        // (Lm14Service::sumSourceBeforeMonth): s.d = Σ period ≤ bulan; lalu = period bulan-1.
        $rows = DB::table('pks_biaya')
            ->join('batch', 'pks_biaya.batch_id', '=', 'batch.id')
            ->where('batch.year', $batch->year)
            ->where('pks_biaya.plant_code', $unit->code)
            ->where('pks_biaya.period', '<=', $batch->month)
            ->get(['pks_biaya.period', 'pks_biaya.cost_center', 'pks_biaya.cost_element', 'pks_biaya.nilai']);

        foreach ($rows as $row) {
            $costCenter = trim((string) $row->cost_center);
            $costElement = self::normalizeCode($row->cost_element);
            [$ember, $target] = self::lm16CostTarget($costCenter, $costElement, $costCenterMap, $costElementMap);
            if ($ember === 'pengolahan') {
                $pengolahan[$target] ??= $zero();
                $ref = &$pengolahan[$target];
            } else {
                $overhead[$target] ??= $zero();
                $ref = &$overhead[$target];
            }

            $nilai = (float) $row->nilai;
            if ((int) $row->period === $batch->month) {
                $ref['bi'] += $nilai;
            }
            if ((int) $row->period === $batch->month - 1) {
                $ref['lalu'] += $nilai;
            }
            $ref['sd'] += $nilai;
            unset($ref);
        }

        return ['pengolahan' => $pengolahan, 'overhead' => $overhead];
    }

    /**
     * Peta akun LM16 dari `lm16_account_map`: [costCenterMap, costElementMap]
     * (kode → lm16_uraian). Dipakai bersama oleh mesin laporan & drill-down agar
     * pemetaan baris identik.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function accountMapArrays(): array
    {
        $maps = DB::table('lm16_account_map')->get();

        return [
            $maps->where('match_type', 'cost_center')->mapWithKeys(fn ($m) => [$m->kode => $m->lm16_uraian])->all(),
            $maps->where('match_type', 'cost_element')->mapWithKeys(fn ($m) => [$m->kode => $m->lm16_uraian])->all(),
        ];
    }

    /**
     * @param  array<string, string>  $costCenterMap
     * @param  array<string, string>  $costElementMap
     * @return array{0: string, 1: string}  [ember ('pengolahan'|'overhead'), uraian baris LM16]
     */
    public static function lm16CostTarget(string $costCenter, string $costElement, array $costCenterMap, array $costElementMap): array
    {
        if ($costCenter !== '' && isset($costCenterMap[$costCenter])) {
            return ['overhead', $costCenterMap[$costCenter]];
        }

        if (preg_match('/^(BT|SUP)/i', $costCenter)) {
            return ['overhead', 'Pengeluaran Lainnya'];
        }

        if (isset($costElementMap[$costElement])) {
            return ['pengolahan', $costElementMap[$costElement]];
        }

        return ['overhead', 'Pengeluaran Lainnya'];
    }

    /**
     * @param  array<string, array<string, float>>  $productionData
     * @param  array<string, array<string, float>>  $costData
     * @return array<string, float>
     */
    private function detailValues(Batch $batch, RefUnit $unit, LmTemplateRow $template, array $productionData, array $costData): array
    {
        if ($template->uraian === 'Stok Awal TBS (Restan Loading Ramp)') {
            return $this->withBudget($batch, $unit, $template, $this->splitOlahKso($unit, [
                'real_bln_lalu' => 0.0,
                'bi' => $productionData['Sisa Buah']['lalu'] ?? 0.0,
                'sd' => 0.0,
            ]));
        }

        if ($template->kode !== null && isset($productionData[$template->kode])) {
            $values = $productionData[$template->kode];

            return $this->withBudget($batch, $unit, $template, $this->splitOlahKso($unit, [
                'real_bln_lalu' => $values['lalu'],
                'bi' => $values['bi'],
                'sd' => $values['sd'],
            ]));
        }

        if (str_contains($template->uraian, 'Rendemen')) {
            return $this->rendemenValues($batch, $unit, $template->uraian, $productionData);
        }

        // Baris pengolahan (kode 600.0/603-604) ambil dari ember 'pengolahan';
        // baris overhead (kode 4xx.0/490.0) dari ember 'overhead'. Mencegah baris
        // berlabel sama (Penerangan/Air) saling tumpang tindih antar grup.
        $ember = ($template->kode !== null && str_starts_with($template->kode, '6')) ? 'pengolahan' : 'overhead';
        $bucket = $costData[$ember] ?? [];
        $costKey = $template->urutan === 55 ? 'Penyusutan a/b Harga Pokok' : $template->uraian;
        if (isset($bucket[$costKey])) {
            $values = $bucket[$costKey];

            return $this->withBudget($batch, $unit, $template, $this->splitOlahKso($unit, [
                'real_bln_lalu' => $values['lalu'],
                'bi' => $values['bi'],
                'sd' => $values['sd'],
            ]));
        }

        return $this->withBudget($batch, $unit, $template, $this->zeroValues());
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    private function withBudget(Batch $batch, RefUnit $unit, LmTemplateRow $template, array $values): array
    {
        return [
            ...$values,
            'bi_rko' => $this->budgetValue('budget_rko', $batch, $unit, $template, false),
            'bi_rkap' => $this->budgetValue('budget_rkap', $batch, $unit, $template, false),
            'sd_rko' => $this->budgetValue('budget_rko', $batch, $unit, $template, true),
            'sd_rkap' => $this->budgetValue('budget_rkap', $batch, $unit, $template, true),
        ];
    }

    /**
     * $cumulative = false → "bulan ini" (period = bulan batch); true → "s.d. bulan ini"
     * (period <= bulan batch). Baris period NULL = anggaran tahunan → ikut di kedua mode.
     */
    private function budgetValue(string $table, Batch $batch, RefUnit $unit, LmTemplateRow $template, bool $cumulative): float
    {
        $codes = self::budgetCodes($template);

        if ($codes === []) {
            return 0.0;
        }

        return (float) DB::table($table)
            ->where('year', $batch->year)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM16')
            ->whereIn('kode', $codes)
            ->where(fn ($query) => $query->where('komoditi', $unit->komoditi)->orWhereNull('komoditi'))
            ->where(fn ($query) => $this->applyBudgetPeriod($query, $batch, $cumulative))
            ->sum('nilai');
    }

    /**
     * Predikat periode anggaran bersama (dipakai budgetValue & rendemenBudget).
     */
    private function applyBudgetPeriod(\Illuminate\Database\Query\Builder $query, Batch $batch, bool $cumulative): void
    {
        $query->whereNull('period');
        $cumulative
            ? $query->orWhere('period', '<=', $batch->month)
            : $query->orWhere('period', '=', $batch->month);
    }

    /**
     * Kode anggaran yang cocok utk satu baris template LM16. 'U{urutan}' = kunci
     * unik per baris — dipakai impor anggaran PKS (uraian/kode template bisa
     * ambigu antar seksi, mis. "Biaya Air" ada di Pengolahan & Overhead).
     *
     * @return array<int, string>
     */
    public static function budgetCodes(LmTemplateRow $template): array
    {
        $codes = array_filter([(string) $template->uraian, (string) $template->kode]);

        return array_values(array_unique([
            'U'.$template->urutan,
            ...$codes,
            ...match ($template->uraian) {
                'TBS dari Lapangan (masuk)', 'TBS di olah' => ['TBS Diolah'],
                'Minyak Sawit' => ['CPO'],
                'Inti Sawit' => ['Inti'],
                default => [],
            },
        ]));
    }

    /**
     * @param  array{real_bln_lalu: float, bi: float, sd: float}  $values
     * @return array<string, float>
     */
    private function splitOlahKso(RefUnit $unit, array $values): array
    {
        $isOlah = $unit->olah_status === 'Olah';

        return [
            'real_bln_lalu' => $values['real_bln_lalu'],
            'bi_olah' => $isOlah ? $values['bi'] : 0.0,
            'bi_kso' => $isOlah ? 0.0 : $values['bi'],
            'bi_jumlah' => $values['bi'],
            'bi_rko' => 0.0,
            'bi_rkap' => 0.0,
            'sd_olah' => $isOlah ? $values['sd'] : 0.0,
            'sd_kso' => $isOlah ? 0.0 : $values['sd'],
            'sd_jumlah' => $values['sd'],
            'sd_rko' => 0.0,
            'sd_rkap' => 0.0,
            'cap_bi_lalu' => 0.0,
            'cap_bi_rkap' => 0.0,
            'cap_bi_sd' => 0.0,
            'cap_sd_rkap' => 0.0,
            'rp_kg_tbs' => 0.0,
            'rp_kg_mi' => 0.0,
        ];
    }

    /**
     * @param  array<string, array<string, float>>  $productionData
     * @return array<string, float>
     */
    private function rendemenValues(Batch $batch, RefUnit $unit, string $uraian, array $productionData): array
    {
        $tbs = $productionData['TBS Diolah'];
        $ms = $productionData['Minyak Sawit'];
        $is = $productionData['Inti Sawit'];

        [$numerator, $rkapNumeratorCodes] = match (true) {
            str_contains($uraian, 'Minyak Sawit') => [$ms, ['CPO']],
            str_contains($uraian, 'Inti Sawit') => [$is, ['Inti']],
            default => [
                [
                    'lalu' => $ms['lalu'] + $is['lalu'],
                    'bi' => $ms['bi'] + $is['bi'],
                    'sd' => $ms['sd'] + $is['sd'],
                ],
                ['CPO', 'Inti'],
            ],
        };

        return [
            ...$this->splitOlahKso($unit, [
                'real_bln_lalu' => $this->safeDiv($numerator['lalu'], $tbs['lalu']) * 100,
                'bi' => $this->safeDiv($numerator['bi'], $tbs['bi']) * 100,
                'sd' => $this->safeDiv($numerator['sd'], $tbs['sd']) * 100,
            ]),
            'bi_rko' => $this->rendemenBudget('budget_rko', $batch, $unit, $rkapNumeratorCodes, false),
            'bi_rkap' => $this->rendemenBudget('budget_rkap', $batch, $unit, $rkapNumeratorCodes, false),
            'sd_rko' => $this->rendemenBudget('budget_rko', $batch, $unit, $rkapNumeratorCodes, true),
            'sd_rkap' => $this->rendemenBudget('budget_rkap', $batch, $unit, $rkapNumeratorCodes, true),
        ];
    }

    /**
     * @param  array<int, string>  $numeratorCodes
     */
    private function rendemenBudget(string $table, Batch $batch, RefUnit $unit, array $numeratorCodes, bool $cumulative): float
    {
        $tbs = (float) DB::table($table)
            ->where('year', $batch->year)
            ->where('komoditi', $unit->komoditi)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM16')
            ->where('kode', 'TBS Diolah')
            ->where(fn ($query) => $this->applyBudgetPeriod($query, $batch, $cumulative))
            ->sum('nilai');

        $numerator = (float) DB::table($table)
            ->where('year', $batch->year)
            ->where('komoditi', $unit->komoditi)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM16')
            ->whereIn('kode', $numeratorCodes)
            ->where(fn ($query) => $this->applyBudgetPeriod($query, $batch, $cumulative))
            ->sum('nilai');

        return $this->safeDiv($numerator, $tbs) * 100;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function subtotalValues(int $urutan, Collection $rows): array
    {
        return match ($urutan) {
            9 => $this->sumRows($rows, [7, 8]),
            13 => $this->sumRows($rows, [11, 12]),
            32 => $this->sumRows($rows, range(16, 31)),
            54 => $this->sumRows($rows, range(34, 53)),
            56 => $this->sumRows($rows, [32, 54, 55]),
            default => $this->zeroValues(),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $urutans
     * @return array<string, float>
     */
    private function sumRows(Collection $rows, array $urutans): array
    {
        $result = $this->zeroValues();

        foreach ($urutans as $urutan) {
            $row = $rows->get($urutan);
            if ($row === null) {
                continue;
            }

            foreach (array_keys($result) as $column) {
                $result[$column] += (float) ($row[$column] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, float>  $values
     * @param  array<string, array<string, float>>  $productionData
     * @return array<string, float>
     */
    private function derivedValues(array $values, array $productionData): array
    {
        $tbsDiolah = $productionData['TBS Diolah']['bi'] ?? 0.0;
        $msIs = ($productionData['Minyak Sawit']['bi'] ?? 0.0) + ($productionData['Inti Sawit']['bi'] ?? 0.0);

        return [
            'cap_bi_lalu' => $this->safeDiv($values['bi_jumlah'], $values['real_bln_lalu']) * 100,
            'cap_bi_rkap' => $this->safeDiv($values['bi_jumlah'], $values['bi_rkap']) * 100,
            'cap_bi_sd' => $this->safeDiv($values['bi_jumlah'], $values['sd_jumlah']) * 100,
            'cap_sd_rkap' => $this->safeDiv($values['sd_jumlah'], $values['sd_rkap']) * 100,
            'rp_kg_tbs' => $this->safeDiv($values['bi_jumlah'], $tbsDiolah),
            'rp_kg_mi' => $this->safeDiv($values['bi_jumlah'], $msIs),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function zeroValues(): array
    {
        return [
            'real_bln_lalu' => 0.0,
            'bi_olah' => 0.0,
            'bi_kso' => 0.0,
            'bi_jumlah' => 0.0,
            'bi_rko' => 0.0,
            'bi_rkap' => 0.0,
            'sd_olah' => 0.0,
            'sd_kso' => 0.0,
            'sd_jumlah' => 0.0,
            'sd_rko' => 0.0,
            'sd_rkap' => 0.0,
            'cap_bi_lalu' => 0.0,
            'cap_bi_rkap' => 0.0,
            'cap_bi_sd' => 0.0,
            'cap_sd_rkap' => 0.0,
            'rp_kg_tbs' => 0.0,
            'rp_kg_mi' => 0.0,
        ];
    }

    public static function normalizeCode(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    private function safeDiv(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }
}
