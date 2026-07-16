<?php

namespace App\Domain\Report;

use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Models\RefUnit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Lm13Service
{
    private const BLOCKS = ['OLAH_JUAL', 'OLAH', 'JUAL'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function generate(Batch $batch, RefUnit $unit, string $komoditi): Collection
    {
        $templates = LmTemplateRow::query()
            ->where('report_type', 'LM13')
            ->where('komoditi', $komoditi)
            ->orderBy('urutan')
            ->get();

        $area = $this->areaValues($batch, $unit, $komoditi);
        $rows = collect();

        foreach (self::BLOCKS as $block) {
            foreach ($templates as $template) {
                $values = match (true) {
                    $template->row_type === 'header' => $this->zeroValues(),
                    $this->productionProduct($template->urutan, $komoditi) !== null => $this->productionValues($batch, $unit, $block, $this->productionProduct($template->urutan, $komoditi)),
                    $this->lm14SourceLabel($template->urutan, $komoditi) !== null => $this->lm14Values($batch, $unit, $komoditi, $block, $this->lm14SourceLabel($template->urutan, $komoditi)),
                    default => $this->calculatedValues($template->urutan, $komoditi, $block, $rows, $area),
                };

                $rows->push([
                    ...$values,
                    'template_urutan' => $template->urutan,
                    'batch_id' => $batch->id,
                    'unit_id' => $unit->id,
                    'komoditi' => $komoditi,
                    'template_id' => $template->id,
                    'blok' => $block,
                ]);
            }
        }

        DB::transaction(function () use ($batch, $unit, $komoditi, $rows): void {
            DB::table('report_lm13')
                ->where('batch_id', $batch->id)
                ->where('unit_id', $unit->id)
                ->where('komoditi', $komoditi)
                ->delete();

            if ($rows->isNotEmpty()) {
                DB::table('report_lm13')->insert($rows->map(function (array $row): array {
                    unset($row['template_urutan']);

                    return $row;
                })->all());
            }
        });

        return $rows;
    }

    private function productionProduct(int $urutan, string $komoditi): ?string
    {
        // Karet: data produksi (Lump/RSS/SIR) belum tersedia sumbernya (tak ada sheet
        // Alokasi pada workbook karet) → baris produksi 0 sampai sumber diimpor.
        if (strtoupper($komoditi) === 'KR') {
            return null;
        }

        return [
            2 => 'Stok Awal TBS',
            6 => 'TBS Diterima',
            11 => 'TBS Dijual',
            16 => 'CPO',
            21 => 'Kernel',
            27 => 'TBS Olah',
            32 => 'CPO',
            37 => 'Kernel',
            46 => 'TBS Restan Loading Ramp',
        ][$urutan] ?? null;
    }

    private function lm14SourceLabel(int $urutan, string $komoditi): ?string
    {
        // Struktur beban identik sawit, hanya urutan bergeser (karet = sawit − 20).
        $map = strtoupper($komoditi) === 'KR'
            ? [
                28 => 'Jumlah Gaji',
                29 => 'JUMLAH BIAYA PEMELIHARAAN',
                30 => 'JUMLAH BIAYA PEMUPUKAN',
                31 => 'JUMLAH BIAYA PANEN',
                32 => 'JUMLAH BIAYA PENGANGKUTAN',
                34 => 'Jumlah Overhead (Biaya Tidak Langsung)',
                39 => 'Jumlah Depresiasi',
            ]
            : [
                48 => 'Jumlah Gaji',
                49 => 'JUMLAH BIAYA PEMELIHARAAN',
                50 => 'JUMLAH BIAYA PEMUPUKAN',
                51 => 'JUMLAH BIAYA PANEN',
                52 => 'JUMLAH BIAYA PENGANGKUTAN',
                54 => 'Jumlah Overhead (Biaya Tidak Langsung)',
                59 => 'Jumlah Depresiasi',
            ];

        return $map[$urutan] ?? null;
    }

    /**
     * @return array<string, float>
     */
    private function productionValues(Batch $batch, RefUnit $unit, string $block, string $product): array
    {
        return [
            'bi_real_thn_lalu' => 0.0,
            'bi_real_thn_ini' => $this->sumAlokasi($batch, $unit, $block, $product, false),
            'bi_rko_tw' => 0.0,
            'bi_rkap' => 0.0,
            'sd_real_thn_lalu' => 0.0,
            'sd_real_thn_ini' => $this->sumAlokasi($batch, $unit, $block, $product, true),
            'sd_rko_tw' => 0.0,
            'sd_rkap' => 0.0,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function lm14Values(Batch $batch, RefUnit $unit, string $komoditi, string $block, string $lm14Uraian): array
    {
        $source = DB::table('report_lm14')
            ->join('lm_template_row', 'report_lm14.template_id', '=', 'lm_template_row.id')
            ->where('report_lm14.batch_id', $batch->id)
            ->where('report_lm14.unit_id', $unit->id)
            ->where('report_lm14.komoditi', $komoditi)
            ->where('lm_template_row.report_type', 'LM14')
            // BINARY: pencocokan case-sensitive — LM14 punya 'Jumlah Biaya Panen'
            // (subtotal) DAN 'JUMLAH BIAYA PANEN' (total, termasuk Sensus Produksi);
            // collation ci membuat keduanya cocok dan subtotal terambil lebih dulu.
            ->whereRaw('BINARY lm_template_row.uraian = ?', [$lm14Uraian])
            ->select('report_lm14.*')
            ->first();

        if ($source === null) {
            return $this->zeroValues();
        }

        $ratio = $this->blockRatio($batch, $unit, $block);

        return [
            'bi_real_thn_lalu' => (float) $source->real_tahun_lalu * $ratio,
            'bi_real_thn_ini' => (float) $source->real_bulan_ini * $ratio,
            'bi_rko_tw' => (float) $source->rko * $ratio,
            'bi_rkap' => (float) $source->rkap * $ratio,
            'sd_real_thn_lalu' => (float) $source->real_sd_tahunlalu * $ratio,
            'sd_real_thn_ini' => (float) $source->real_sd_bulan_ini * $ratio,
            'sd_rko_tw' => (float) $source->rko_sd * $ratio,
            'sd_rkap' => (float) $source->rkap_sd * $ratio,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, float>  $area
     * @return array<string, float>
     */
    private function calculatedValues(int $urutan, string $komoditi, string $block, Collection $rows, array $area): array
    {
        if (strtoupper($komoditi) === 'KR') {
            return $this->calculatedValuesKaret($urutan, $block, $rows, $area);
        }

        return match ($urutan) {
            9 => $this->sumRows($rows, $block, [6, 7, 8]),
            14 => $this->sumRows($rows, $block, [11, 12, 13]),
            19 => $this->sumRows($rows, $block, [16, 17, 18]),
            24 => $this->sumRows($rows, $block, [21, 22, 23]),
            25 => $this->sumRows($rows, $block, [19, 24]),
            31 => $this->sumRows($rows, $block, [28, 29, 30]),
            36 => $this->sumRows($rows, $block, [33, 34, 35]),
            41 => $this->sumRows($rows, $block, [38, 39, 40]),
            42 => $this->sumRows($rows, $block, [36, 41]),
            53 => $this->sumRows($rows, $block, [48, 49, 50, 51, 52]),
            55 => $this->sumRows($rows, $block, [53, 54]),
            58 => $this->sumRows($rows, $block, [56, 57]),
            61 => $this->sumRows($rows, $block, [59, 60]),
            62 => $this->sumRows($rows, $block, [55, 58, 61]),
            68 => $this->sumRows($rows, $block, [62, 63, 64, 65, 66, 67]),
            69 => $this->divideRowsByArea($rows, $block, 53, $area),
            70 => $this->divideValues($this->subtractRows($rows, $block, 68, 61), $area),
            71 => $this->divideRowsByArea($rows, $block, 68, $area),
            72, 73, 74 => $this->hppValues($rows, $block, 68, 25),
            default => $this->zeroValues(),
        };
    }

    /**
     * Subtotal/total & rasio LM13 Karet. Bagian beban (urutan 28..54) berstruktur
     * sama dgn sawit (sawit − 20). Bagian produksi (9,14,22) 0 sampai sumber produksi
     * karet tersedia (per-Ha & HPP otomatis 0 saat area/produksi 0 via safeDiv).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, float>  $area
     * @return array<string, float>
     */
    private function calculatedValuesKaret(int $urutan, string $block, Collection $rows, array $area): array
    {
        return match ($urutan) {
            9 => $this->sumRows($rows, $block, [6, 7, 8]),
            14 => $this->sumRows($rows, $block, [11, 12, 13]),
            22 => $this->sumRows($rows, $block, [16, 17, 18, 19, 20, 21]),
            33 => $this->sumRows($rows, $block, [28, 29, 30, 31, 32]),
            35 => $this->sumRows($rows, $block, [33, 34]),
            38 => $this->sumRows($rows, $block, [36, 37]),
            41 => $this->sumRows($rows, $block, [39, 40]),
            42 => $this->sumRows($rows, $block, [35, 38, 41]),
            48 => $this->sumRows($rows, $block, [42, 43, 44, 45, 46, 47]),
            49 => $this->divideRowsByArea($rows, $block, 33, $area),
            50 => $this->divideValues($this->subtractRows($rows, $block, 48, 41), $area),
            51 => $this->divideRowsByArea($rows, $block, 48, $area),
            52, 53, 54 => $this->hppValues($rows, $block, 48, 22),
            default => $this->zeroValues(),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $urutans
     * @return array<string, float>
     */
    private function sumRows(Collection $rows, string $block, array $urutans): array
    {
        $values = $this->zeroValues();

        foreach ($urutans as $urutan) {
            $row = $this->row($rows, $block, $urutan);
            foreach (array_keys($values) as $column) {
                $values[$column] += (float) ($row[$column] ?? 0);
            }
        }

        return $values;
    }

    /**
     * @return array<string, float>
     */
    private function subtractRows(Collection $rows, string $block, int $leftUrutan, int $rightUrutan): array
    {
        $left = $this->row($rows, $block, $leftUrutan);
        $right = $this->row($rows, $block, $rightUrutan);
        $values = $this->zeroValues();

        foreach (array_keys($values) as $column) {
            $values[$column] = (float) ($left[$column] ?? 0) - (float) ($right[$column] ?? 0);
        }

        return $values;
    }

    /**
     * @param  array<string, float>  $area
     * @return array<string, float>
     */
    private function divideRowsByArea(Collection $rows, string $block, int $urutan, array $area): array
    {
        return $this->divideValues($this->row($rows, $block, $urutan), $area);
    }

    /**
     * @param  array<string, float>|array<string, mixed>|null  $values
     * @param  array<string, float>  $area
     * @return array<string, float>
     */
    private function divideValues(?array $values, array $area): array
    {
        return [
            'bi_real_thn_lalu' => $this->safeDiv((float) ($values['bi_real_thn_lalu'] ?? 0), $area['real_thn_lalu']),
            'bi_real_thn_ini' => $this->safeDiv((float) ($values['bi_real_thn_ini'] ?? 0), $area['real_thn_ini']),
            'bi_rko_tw' => $this->safeDiv((float) ($values['bi_rko_tw'] ?? 0), $area['rko']),
            'bi_rkap' => $this->safeDiv((float) ($values['bi_rkap'] ?? 0), $area['rkap']),
            'sd_real_thn_lalu' => $this->safeDiv((float) ($values['sd_real_thn_lalu'] ?? 0), $area['real_thn_lalu']),
            'sd_real_thn_ini' => $this->safeDiv((float) ($values['sd_real_thn_ini'] ?? 0), $area['real_thn_ini']),
            'sd_rko_tw' => $this->safeDiv((float) ($values['sd_rko_tw'] ?? 0), $area['rko']),
            'sd_rkap' => $this->safeDiv((float) ($values['sd_rkap'] ?? 0), $area['rkap']),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function hppValues(Collection $rows, string $block, int $costUrutan, int $productionUrutan): array
    {
        $cost = $this->row($rows, $block, $costUrutan);
        $production = $this->row($rows, $block, $productionUrutan);

        $values = $this->zeroValues();
        foreach (array_keys($values) as $column) {
            $values[$column] = $this->safeDiv((float) ($cost[$column] ?? 0), (float) ($production[$column] ?? 0));
        }

        return $values;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(Collection $rows, string $block, int $urutan): ?array
    {
        return $rows->first(fn (array $row) => $row['blok'] === $block && $row['template_urutan'] === $urutan);
    }

    private function blockRatio(Batch $batch, RefUnit $unit, string $block): float
    {
        if ($block === 'OLAH_JUAL') {
            return 1.0;
        }

        $total = $this->sumAlokasi($batch, $unit, 'OLAH_JUAL', 'TBS Diterima', false);
        if (abs($total) < 0.00001) {
            return 0.0;
        }

        return $this->sumAlokasi($batch, $unit, $block, 'TBS Diterima', false) / $total;
    }

    private function sumAlokasi(Batch $batch, RefUnit $unit, string $block, string $product, bool $cumulative): float
    {
        return DB::table('alokasi_produksi')
            ->where('year', $batch->year)
            ->where('kebun_code', $unit->code)
            ->where('produk', $product)
            ->when($cumulative, fn ($query) => $query->where('month', '<=', $batch->month), fn ($query) => $query->where('month', $batch->month))
            ->when($block === 'OLAH', fn ($query) => $query->whereNotNull('pabrik_code'))
            ->when($block === 'JUAL', fn ($query) => $query->whereNull('pabrik_code'))
            ->sum('jumlah');
    }

    /**
     * @return array<string, float>
     */
    private function areaValues(Batch $batch, RefUnit $unit, string $komoditi): array
    {
        // alokasi_areal hanya berisi luas SAWIT (tanpa kolom komoditi). Untuk karet
        // sumber luas areal belum tersedia → 0 (per-Ha jadi 0 via safeDiv, bukan nilai
        // sawit yang menyesatkan). Hapus gate ini saat sumber luas areal karet ada.
        if (strtoupper($komoditi) === 'KR') {
            return ['real_thn_lalu' => 0.0, 'real_thn_ini' => 0.0, 'rko' => 0.0, 'rkap' => 0.0];
        }

        $area = DB::table('alokasi_areal')
            ->where('year', $batch->year)
            ->where('kebun_code', $unit->code)
            ->first();

        return [
            'real_thn_lalu' => (float) ($area->real_thn_lalu ?? 0),
            'real_thn_ini' => (float) ($area->real_thn_ini ?? 0),
            'rko' => (float) ($area->rko ?? 0),
            'rkap' => (float) ($area->rkap ?? 0),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function zeroValues(): array
    {
        return [
            'bi_real_thn_lalu' => 0.0,
            'bi_real_thn_ini' => 0.0,
            'bi_rko_tw' => 0.0,
            'bi_rkap' => 0.0,
            'sd_real_thn_lalu' => 0.0,
            'sd_real_thn_ini' => 0.0,
            'sd_rko_tw' => 0.0,
            'sd_rkap' => 0.0,
        ];
    }

    private function safeDiv(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }
}
