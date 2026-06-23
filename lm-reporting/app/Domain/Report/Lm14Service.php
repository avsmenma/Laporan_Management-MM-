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
     * SEMUA kolom realisasi (bulan ini, bulan lalu, s.d. bulan ini) bersumber dari
     * db_ohc lock SP01 (Sawit) / SR01 (Karet) — selaras formula
     * =CONCATENATE(IF(KS,"SP","SR"),"01") pada kolom Kode CC.
     *
     * Kolom "bulan lalu" TIDAK boleh dari db_wbs_raw aktifitas 99-01: aktifitas 99-01
     * mencampur banyak klasifikasi (1.Gaji + 6.Lain-Lain + 5.Depresiasi), sehingga
     * SUM tanpa filter klasifikasi menghasilkan grand-total (mis. 2.366.107.451),
     * bukan gaji staf (mis. 217.189.151). db_ohc SP01 sudah ekuivalen klasifikasi Gaji,
     * sehingga "bulan lalu" konsisten dengan "bulan ini" periode sebelumnya.
     */
    private const STAF_GAJI_KODE = '99-01';

    /** Kode CC db_ohc untuk Gaji Staf, bergantung komoditi (SP01=KS, SR01=KR). */
    private function stafGajiOhcLock(string $komoditi): string
    {
        return strtoupper($komoditi) === 'KR' ? 'SR01' : 'SP01';
    }

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
        // RKO/RKAP per-periode: kolom "bulan ini" = anggaran bulan ini, kolom "s.d. bulan
        // ini" = akumulasi anggaran bulan 1..ini. Baris tanpa periode (NULL) = anggaran
        // tahunan → ikut di kedua kolom (kompatibel mundur).
        $rko = $this->sumBudget('budget_rko', $batch, $unit, $komoditi, $template->kode, false);
        $rkap = $this->sumBudget('budget_rkap', $batch, $unit, $komoditi, $template->kode, false);
        $rkoSd = $this->sumBudget('budget_rko', $batch, $unit, $komoditi, $template->kode, true);
        $rkapSd = $this->sumBudget('budget_rkap', $batch, $unit, $komoditi, $template->kode, true);
        $realSebelumBulanIni = $this->sumSourceBeforeMonth($batch, $unit, $komoditi, $template);

        return [
            'real_bulan_ini' => $realBulanIni,
            'real_bulan_lalu' => $realBulanLalu,
            'real_tahun_lalu' => $realTahunLalu,
            'rko' => $rko,
            'rkap' => $rkap,
            'real_sd_bulan_ini' => $realBulanIni + $realSebelumBulanIni,
            'real_sd_tahunlalu' => $this->sumTahunLaluSd($batch, $unit, $komoditi, $template->kode),
            'rko_sd' => $rkoSd,
            'rkap_sd' => $rkapSd,
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

        $value = round(($numerator / $denominator) * 100, 2);

        // Kolom capaian bertipe decimal(10,2) (maks ±99.999.999,99). Penyebut yang
        // mendekati nol (mis. realisasi tahun lalu ~1 rupiah) menghasilkan rasio di luar
        // rentang kolom dan menggagalkan insert. Rasio sebesar itu tak bermakna, jadi
        // batasi ke rentang kolom agar materialisasi tetap aman.
        return max(-99999999.99, min(99999999.99, $value));
    }

    private function sumSourceCurrentBatch(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template): float
    {
        if ($this->isStafGaji($template)) {
            return $this->sumStafGajiOhc($unit, $komoditi, function ($query) use ($batch): void {
                $query->where('batch_id', $batch->id)
                    ->where('period', $batch->month);
            });
        }

        return (float) $this->sourceQuery($template->source, $template->kode)
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('period', $batch->month)
            ->sum($this->valueColumn($template->source));
    }

    private function sumSourceYearPeriod(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template, int $period): float
    {
        if ($this->isStafGaji($template)) {
            return $this->sumStafGajiOhc($unit, $komoditi, function ($query) use ($batch, $period): void {
                $query->join('batch', 'db_ohc.batch_id', '=', 'batch.id')
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
            ->sum($this->valueColumn($template->source));
    }

    private function sumSourceBeforeMonth(Batch $batch, RefUnit $unit, string $komoditi, LmTemplateRow $template): float
    {
        if ($this->isStafGaji($template)) {
            return $this->sumStafGajiOhc($unit, $komoditi, function ($query) use ($batch): void {
                $query->join('batch', 'db_ohc.batch_id', '=', 'batch.id')
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
            ->sum($this->valueColumn($template->source));
    }

    private function isStafGaji(LmTemplateRow $template): bool
    {
        return $template->source === 'BTL' && $template->kode === self::STAF_GAJI_KODE;
    }

    /**
     * Realisasi baris "Gaji Staf dari WBS" untuk SEMUA kolom (bulan ini, bulan lalu,
     * s.d. bulan ini) mengikuti db_ohc lock SP01/SR01 (pengganti db_btl cost center SP01).
     */
    private function sumStafGajiOhc(RefUnit $unit, string $komoditi, callable $scope): float
    {
        $ohc = DB::table('db_ohc')
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('lock', $this->stafGajiOhcLock($komoditi));

        $scope($ohc);

        return (float) $ohc->sum('value_obj_crcy');
    }

    private function sourceQuery(?string $source, ?string $kode): \Illuminate\Database\Query\Builder
    {
        $table = $this->sourceTable($source);
        $codeColumn = match (true) {
            $source === 'BTL' && str_starts_with((string) $kode, '511') => 'cost_element', // GL/depresiasi di db_ohc
            $source === 'BTL' => 'lock', // CC overhead (BT01..) di db_ohc kolom "lock"
            default => 'aktifitas', // kolom db_wbs_raw (ejaan file: "Aktifitas")
        };

        return DB::table($table)->where($codeColumn, $kode);
    }

    private function sourceTable(?string $source): string
    {
        // Sumber mentah SAP: WBS dari db_wbs_raw (kolom value), BTL/overhead dari db_ohc
        // (kolom value_obj_crcy). Lihat docs/MIGRASI_SUMBER_LM14_LM13.md.
        return $source === 'BTL' ? 'db_ohc' : 'db_wbs_raw';
    }

    private function valueColumn(?string $source): string
    {
        return $source === 'BTL' ? 'value_obj_crcy' : 'value';
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

    /**
     * Jumlah anggaran (RKO/RKAP) untuk satu kode baris.
     *
     * $cumulative = false → kolom "bulan ini": baris dengan period = bulan batch.
     * $cumulative = true  → kolom "s.d. bulan ini": baris dengan period <= bulan batch.
     * Baris dengan period NULL = anggaran tahunan → selalu ikut di kedua mode.
     */
    private function sumBudget(string $table, Batch $batch, RefUnit $unit, string $komoditi, string $kode, bool $cumulative): float
    {
        return DB::table($table)
            ->where('year', $batch->year)
            ->where('komoditi', $komoditi)
            ->where('plant_code', $unit->code)
            ->where('report_type', 'LM14')
            ->where('kode', $kode)
            ->where(function ($query) use ($batch, $cumulative): void {
                $query->whereNull('period');
                $cumulative
                    ? $query->orWhere('period', '<=', $batch->month)
                    : $query->orWhere('period', '=', $batch->month);
            })
            ->sum('nilai');
    }
}
