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
     * Generate report LM16 untuk pabrik (PKS/PKR).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function generate(Batch $batch, RefUnit $unit): Collection
    {
        $templates = LmTemplateRow::query()
            ->where('report_type', 'LM16')
            ->where(function ($query) use ($unit) {
                $query->where('komoditi', $unit->komoditi)
                    ->orWhereNull('komoditi');
            })
            ->orderBy('urutan')
            ->get();

        $rows = collect();
        $productionData = $this->getProductionData($batch, $unit);
        $costData = $this->getCostData($batch, $unit);

        foreach ($templates as $template) {
            $values = match ($template->row_type) {
                'header' => $this->zeroValues(),
                'detail' => $this->detailValues($batch, $unit, $template, $productionData, $costData, $rows),
                'subtotal', 'total' => $this->lm16SubtotalValues($template->urutan, $rows),
                default => $this->zeroValues(),
            };

            $rows->put($template->urutan, [
                ...$values,
                ...$this->derivedValues($values, $productionData),
                'batch_id' => $batch->id,
                'unit_id' => $unit->id,
                'template_id' => $template->id,
            ]);
        }

        // Simpan ke database
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
     * Ambil data produksi dari pks_produksi (sheet LM625F01).
     *
     * @return array<string, array<string, float>>
     */
    private function getProductionData(Batch $batch, RefUnit $unit): array
    {
        $uraianMap = [
            'TBS dari Lapangan' => 'Jumlah Produksi TBS',
            'TBS Diolah' => 'Jumlah TBS Diolah',
            'Sisa Buah' => 'Jumlah Sisa Buah di Pabrik',
            'Minyak Sawit' => 'Jlh. Prod. Minyak Sawit',
            'Inti Sawit' => 'Jumlah Produksi Inti Sawit',
        ];

        $data = [];

        foreach ($uraianMap as $key => $uraian) {
            // Bulan ini
            $bi = DB::table('pks_produksi')
                ->where('batch_id', $batch->id)
                ->where('plant_code', $unit->code)
                ->where('period', $batch->month)
                ->where('uraian', $uraian)
                ->value('nilai_bi') ?? 0;

            // s.d Bulan ini
            $sd = DB::table('pks_produksi')
                ->where('batch_id', $batch->id)
                ->where('plant_code', $unit->code)
                ->where('period', $batch->month)
                ->where('uraian', $uraian)
                ->value('nilai_sd') ?? 0;

            // Bulan lalu (period - 1)
            $lalu = 0;
            if ($batch->month > 1) {
                $lalu = DB::table('pks_produksi')
                    ->where('batch_id', $batch->id)
                    ->where('plant_code', $unit->code)
                    ->where('period', $batch->month - 1)
                    ->where('uraian', $uraian)
                    ->value('nilai_bi') ?? 0;
            }

            // PENTING: Gunakan $uraian sebagai key, karena template->kode berisi nilai uraian
            $data[$uraian] = [
                'bi' => (float) $bi,
                'sd' => (float) $sd,
                'lalu' => (float) $lalu,
            ];

            // Tambah juga dengan key alias untuk backward compatibility
            $data[$key] = $data[$uraian];
        }

        return $data;
    }

    /**
     * Ambil data biaya dari pks_biaya (sheet Summary) via lm16_account_map.
     *
     * @return array<string, array<string, float>>
     */
    private function getCostData(Batch $batch, RefUnit $unit): array
    {
        // Ambil pemetaan dari lm16_account_map
        $accountMap = DB::table('lm16_account_map')
            ->select('lm16_uraian', 'match_type', 'kode')
            ->get()
            ->groupBy('lm16_uraian');

        $data = [];

        foreach ($accountMap as $uraian => $mappings) {
            $biayaBi = 0;
            $biayaSd = 0;
            $biayaLalu = 0;

            foreach ($mappings as $map) {
                // Bulan ini
                $biValue = DB::table('pks_biaya')
                    ->where('batch_id', $batch->id)
                    ->where('plant_code', $unit->code)
                    ->where('period', $batch->month)
                    ->where(
                        $map->match_type === 'cost_center' ? 'cost_center' : 'cost_element',
                        $map->kode
                    )
                    ->sum('nilai');

                $biayaBi += (float) $biValue;

                // s.d Bulan ini (kumulatif)
                $sdValue = DB::table('pks_biaya')
                    ->where('batch_id', $batch->id)
                    ->where('plant_code', $unit->code)
                    ->where('period', '<=', $batch->month)
                    ->where(
                        $map->match_type === 'cost_center' ? 'cost_center' : 'cost_element',
                        $map->kode
                    )
                    ->sum('nilai');

                $biayaSd += (float) $sdValue;

                // Bulan lalu
                if ($batch->month > 1) {
                    $laluValue = DB::table('pks_biaya')
                        ->where('batch_id', $batch->id)
                        ->where('plant_code', $unit->code)
                        ->where('period', $batch->month - 1)
                        ->where(
                            $map->match_type === 'cost_center' ? 'cost_center' : 'cost_element',
                            $map->kode
                        )
                        ->sum('nilai');

                    $biayaLalu += (float) $laluValue;
                }
            }

            $data[$uraian] = [
                'bi' => $biayaBi,
                'sd' => $biayaSd,
                'lalu' => $biayaLalu,
            ];
        }

        return $data;
    }

    /**
     * Hitung nilai detail berdasarkan template row.
     *
     * @param  array<string, array<string, float>>  $productionData
     * @param  array<string, array<string, float>>  $costData
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function detailValues(
        Batch $batch,
        RefUnit $unit,
        LmTemplateRow $template,
        array $productionData,
        array $costData,
        Collection $rows
    ): array {
        // Produksi (via template->kode yang berisi uraian pks_produksi)
        if ($template->kode && isset($productionData[$template->kode])) {
            $prod = $productionData[$template->kode];

            return $this->applyOlahKso($unit, [
                'real_bln_lalu' => $prod['lalu'],
                'bi' => $prod['bi'],
                'sd' => $prod['sd'],
            ]);
        }

        // Rendemen (MS/TBS, IS/TBS)
        if (str_contains($template->uraian, 'Rendemen') || str_contains($template->uraian, 'RENDEMEN')) {
            return $this->rendemenValues($template->uraian, $productionData);
        }

        // Biaya (via account map)
        if (isset($costData[$template->uraian])) {
            $cost = $costData[$template->uraian];

            return $this->applyOlahKso($unit, [
                'real_bln_lalu' => $cost['lalu'],
                'bi' => $cost['bi'],
                'sd' => $cost['sd'],
            ]);
        }

        // Stok Awal TBS = Sisa Buah bulan lalu
        if ($template->uraian === 'Stok Awal TBS') {
            $sisaBuahLalu = $productionData['Sisa Buah']['lalu'] ?? 0;

            return $this->applyOlahKso($unit, [
                'real_bln_lalu' => 0,
                'bi' => $sisaBuahLalu,
                'sd' => $sisaBuahLalu,
            ]);
        }

        // Stok Akhir TBS = Stok Awal + TBS Lapangan - TBS Diolah
        if ($template->uraian === 'Stok Akhir TBS') {
            $stokAwal = $productionData['Sisa Buah']['lalu'] ?? 0;
            $tbsMasuk = $productionData['TBS dari Lapangan']['bi'] ?? 0;
            $tbsOlah = $productionData['TBS Diolah']['bi'] ?? 0;
            $stokAkhir = $stokAwal + $tbsMasuk - $tbsOlah;

            return $this->applyOlahKso($unit, [
                'real_bln_lalu' => 0,
                'bi' => $stokAkhir,
                'sd' => $stokAkhir,
            ]);
        }

        // Jumlah M+I
        if ($template->uraian === 'Jumlah M + I') {
            $ms = $productionData['Minyak Sawit']['bi'] ?? 0;
            $is = $productionData['Inti Sawit']['bi'] ?? 0;
            $msSd = $productionData['Minyak Sawit']['sd'] ?? 0;
            $isSd = $productionData['Inti Sawit']['sd'] ?? 0;
            $msLalu = $productionData['Minyak Sawit']['lalu'] ?? 0;
            $isLalu = $productionData['Inti Sawit']['lalu'] ?? 0;

            return $this->applyOlahKso($unit, [
                'real_bln_lalu' => $msLalu + $isLalu,
                'bi' => $ms + $is,
                'sd' => $msSd + $isSd,
            ]);
        }

        return $this->zeroValues();
    }

    /**
     * Hitung rendemen (MS/TBS × 100, IS/TBS × 100).
     *
     * @param  array<string, array<string, float>>  $productionData
     * @return array<string, float>
     */
    private function rendemenValues(string $uraian, array $productionData): array
    {
        $tbsDiolahBi = $productionData['TBS Diolah']['bi'] ?? 0;
        $tbsDiolahSd = $productionData['TBS Diolah']['sd'] ?? 0;
        $tbsDiolahLalu = $productionData['TBS Diolah']['lalu'] ?? 0;

        $ms = $productionData['Minyak Sawit']['bi'] ?? 0;
        $msSd = $productionData['Minyak Sawit']['sd'] ?? 0;
        $msLalu = $productionData['Minyak Sawit']['lalu'] ?? 0;

        $is = $productionData['Inti Sawit']['bi'] ?? 0;
        $isSd = $productionData['Inti Sawit']['sd'] ?? 0;
        $isLalu = $productionData['Inti Sawit']['lalu'] ?? 0;

        if (str_contains($uraian, 'Minyak Sawit')) {
            return [
                'real_bln_lalu' => $this->safeDiv($msLalu, $tbsDiolahLalu) * 100,
                'bi_olah' => $this->safeDiv($ms, $tbsDiolahBi) * 100,
                'bi_kso' => 0,
                'bi_jumlah' => $this->safeDiv($ms, $tbsDiolahBi) * 100,
                'bi_rko' => 0,
                'bi_rkap' => 0,
                'sd_olah' => $this->safeDiv($msSd, $tbsDiolahSd) * 100,
                'sd_kso' => 0,
                'sd_jumlah' => $this->safeDiv($msSd, $tbsDiolahSd) * 100,
                'sd_rko' => 0,
                'sd_rkap' => 0,
                'cap_bi_lalu' => 0,
                'cap_bi_rkap' => 0,
                'cap_bi_sd' => 0,
                'cap_sd_rkap' => 0,
                'rp_kg_tbs' => 0,
                'rp_kg_mi' => 0,
            ];
        }

        if (str_contains($uraian, 'Inti Sawit')) {
            return [
                'real_bln_lalu' => $this->safeDiv($isLalu, $tbsDiolahLalu) * 100,
                'bi_olah' => $this->safeDiv($is, $tbsDiolahBi) * 100,
                'bi_kso' => 0,
                'bi_jumlah' => $this->safeDiv($is, $tbsDiolahBi) * 100,
                'bi_rko' => 0,
                'bi_rkap' => 0,
                'sd_olah' => $this->safeDiv($isSd, $tbsDiolahSd) * 100,
                'sd_kso' => 0,
                'sd_jumlah' => $this->safeDiv($isSd, $tbsDiolahSd) * 100,
                'sd_rko' => 0,
                'sd_rkap' => 0,
                'cap_bi_lalu' => 0,
                'cap_bi_rkap' => 0,
                'cap_bi_sd' => 0,
                'cap_sd_rkap' => 0,
                'rp_kg_tbs' => 0,
                'rp_kg_mi' => 0,
            ];
        }

        // Rendemen Jumlah = MS + IS
        return [
            'real_bln_lalu' => $this->safeDiv($msLalu + $isLalu, $tbsDiolahLalu) * 100,
            'bi_olah' => $this->safeDiv($ms + $is, $tbsDiolahBi) * 100,
            'bi_kso' => 0,
            'bi_jumlah' => $this->safeDiv($ms + $is, $tbsDiolahBi) * 100,
            'bi_rko' => 0,
            'bi_rkap' => 0,
            'sd_olah' => $this->safeDiv($msSd + $isSd, $tbsDiolahSd) * 100,
            'sd_kso' => 0,
            'sd_jumlah' => $this->safeDiv($msSd + $isSd, $tbsDiolahSd) * 100,
            'sd_rko' => 0,
            'sd_rkap' => 0,
            'cap_bi_lalu' => 0,
            'cap_bi_rkap' => 0,
            'cap_bi_sd' => 0,
            'cap_sd_rkap' => 0,
            'rp_kg_tbs' => 0,
            'rp_kg_mi' => 0,
        ];
    }

    /**
     * Apply Olah vs KSO split berdasarkan unit.olah_status.
     *
     * @param  array{real_bln_lalu: float, bi: float, sd: float}  $values
     * @return array<string, float>
     */
    private function applyOlahKso(RefUnit $unit, array $values): array
    {
        $isOlah = $unit->olah_status === 'Olah';

        return [
            'real_bln_lalu' => $values['real_bln_lalu'],
            'bi_olah' => $isOlah ? $values['bi'] : 0,
            'bi_kso' => $isOlah ? 0 : $values['bi'],
            'bi_jumlah' => $values['bi'],
            'bi_rko' => 0, // TODO: ambil dari budget_rko
            'bi_rkap' => 0, // TODO: ambil dari budget_rkap
            'sd_olah' => $isOlah ? $values['sd'] : 0,
            'sd_kso' => $isOlah ? 0 : $values['sd'],
            'sd_jumlah' => $values['sd'],
            'sd_rko' => 0, // TODO: ambil dari budget
            'sd_rkap' => 0, // TODO: ambil dari budget
            'cap_bi_lalu' => 0, // dihitung di derivedValues
            'cap_bi_rkap' => 0,
            'cap_bi_sd' => 0,
            'cap_sd_rkap' => 0,
            'rp_kg_tbs' => 0, // dihitung di derivedValues
            'rp_kg_mi' => 0,
        ];
    }

    /**
     * Hitung subtotal/total dari formula.
     *
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
     * Parse formula 'u{n}+u{n}...' jadi array urutan.
     *
     * @return array<int, int>
     */
    private function formulaUrutans(?string $formula): array
    {
        if ($formula === null || $formula === '') {
            return [];
        }

        preg_match_all('/u(\d+)/', $formula, $matches);

        return array_map('intval', $matches[1] ?? []);
    }

    /**
     * Hitung derived values (4 capaian, rp_kg_tbs, rp_kg_mi).
     *
     * @param  array<string, float>  $values
     * @param  array<string, array<string, float>>  $productionData
     * @return array<string, float>
     */
    private function derivedValues(array $values, array $productionData): array
    {
        // 4 Capaian
        $capBiLalu = $this->safeDiv($values['bi_jumlah'], $values['real_bln_lalu']) * 100;
        $capBiRkap = $this->safeDiv($values['bi_jumlah'], $values['bi_rkap']) * 100;
        $capBiSd = $this->safeDiv($values['bi_jumlah'], $values['sd_jumlah']) * 100;
        $capSdRkap = $this->safeDiv($values['sd_jumlah'], $values['sd_rkap']) * 100;

        // Rp/kg TBS & Rp/kg M+I
        $tbsDiolahBi = $productionData['TBS Diolah']['bi'] ?? 0;
        $ms = $productionData['Minyak Sawit']['bi'] ?? 0;
        $is = $productionData['Inti Sawit']['bi'] ?? 0;

        $rpKgTbs = $this->safeDiv($values['bi_jumlah'], $tbsDiolahBi);
        $rpKgMi = $this->safeDiv($values['bi_jumlah'], $ms + $is);

        return [
            'cap_bi_lalu' => $capBiLalu,
            'cap_bi_rkap' => $capBiRkap,
            'cap_bi_sd' => $capBiSd,
            'cap_sd_rkap' => $capSdRkap,
            'rp_kg_tbs' => $rpKgTbs,
            'rp_kg_mi' => $rpKgMi,
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

    /**
     * Hitung subtotal/total untuk LM16 berdasarkan urutan (hardcoded logic).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function lm16SubtotalValues(int $urutan, Collection $rows): array
    {
        // Urutan 9: Jumlah M+I = sum(7, 8)
        if ($urutan === 9) {
            return $this->sumRowsByUrutan($rows, [7, 8]);
        }

        // Urutan 13: Jumlah Rendemen M+I = sum(11, 12)
        if ($urutan === 13) {
            return $this->sumRowsByUrutan($rows, [11, 12]);
        }

        // Urutan 32: Jumlah Biaya Pengolahan = sum(16-31)
        if ($urutan === 32) {
            return $this->sumRowsByUrutan($rows, range(16, 31));
        }

        // Urutan 54: Jumlah Biaya Overhead = sum(34-53)
        if ($urutan === 54) {
            return $this->sumRowsByUrutan($rows, range(34, 53));
        }

        // Urutan 56: Total Biaya Pabrik = sum(32, 54, 55)
        if ($urutan === 56) {
            return $this->sumRowsByUrutan($rows, [32, 54, 55]);
        }

        return $this->zeroValues();
    }

    /**
     * Sum rows by urutan untuk semua kolom nilai.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int>  $urutanList
     * @return array<string, float>
     */
    private function sumRowsByUrutan(Collection $rows, array $urutanList): array
    {
        $result = $this->zeroValues();

        foreach ($urutanList as $u) {
            if (! $rows->has($u)) {
                continue;
            }

            $row = $rows->get($u);

            foreach (array_keys($result) as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $result[$key] += (float) $row[$key];
                }
            }
        }

        return $result;
    }

    private function safeDiv(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }
}
