<?php

namespace App\Domain\Report;

use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Support\Facades\DB;

/**
 * Mesin hitung laporan /kebun/investasi (biaya investasi TBM).
 *
 * Membangun dua tampilan sebagai array baris + definisi kolom:
 *  - Rekap   (buildRekap)  : sumber investasi_wbs + areal_blok, 9 blok fase.
 *  - Rekap-2 (buildRekap2) : sumber investasi_asset + areal_blok, 10 blok fase.
 *
 * Aturan agregasi sudah divalidasi selisih-0 terhadap workbook acuan;
 * jangan mengubah formula.
 */
class InvestasiService
{
    /** Urutan 17 kebun (identik Excel). */
    public const KEBUN_ORDER = [
        '5E01', '5E02', '5E03', '5E04', '5E06', '5E07', '5E08', '5E09', '5E11',
        '5E12', '5E13', '5E14', '5E15', '5E16', '5E17', '5E18', '5E19',
    ];

    /**
     * Blok Rekap (9). Tiap elemen: [fase_sap, fase, tahun_tanam].
     * Label subtotal = "Jumh".
     */
    public const REKAP_BLOCKS = [
        ['TU',  'a. TBM-0/TU', 2026],
        ['TU',  'a. TBM-0/TU', 2025],
        ['TBM', 'b. TBM-1',    2025],
        ['TBM', 'c. TBM-2',    2024],
        ['TBM', 'd. TBM-3',    2023],
        ['TBM', 'e. TBM-4',    2022],
        ['TBM', 'f. TBM-5',    2021],
        ['TBM', 'g. TBM-6',    2020],
        ['TBM', 'h. >TBM-3',   2019],
    ];

    /**
     * Blok Rekap-2 (10). Sama 9 blok Rekap + blok 10 (>TBM-3, 2018).
     * Label subtotal = "Jumlah".
     */
    public const REKAP2_BLOCKS = [
        ['TU',  'a. TBM-0/TU', 2026],
        ['TU',  'a. TBM-0/TU', 2025],
        ['TBM', 'b. TBM-1',    2025],
        ['TBM', 'c. TBM-2',    2024],
        ['TBM', 'd. TBM-3',    2023],
        ['TBM', 'e. TBM-4',    2022],
        ['TBM', 'f. TBM-5',    2021],
        ['TBM', 'g. TBM-6',    2020],
        ['TBM', 'h. >TBM-3',   2019],
        ['TBM', 'h. >TBM-3',   2018],
    ];

    /**
     * Pemetaan fase Rekap → fase pada investasi_wbs.
     * (Hanya dipakai view Rekap; investasi_asset TIDAK dipetakan.)
     */
    public const REKAP_FASE_MAP = [
        'a. TBM-0/TU' => 'a. Land Clearing/TU',
        'b. TBM-1'    => 'b. TBM-1',
        'c. TBM-2'    => 'c. TBM-2',
        'd. TBM-3'    => 'd. TBM-3',
        'e. TBM-4'    => 'e. >TBM-3',
        'f. TBM-5'    => 'e. >TBM-3',
        'g. TBM-6'    => 'e. >TBM-3',
        'h. >TBM-3'   => 'e. >TBM-3',
    ];

    /** Kolom nilai aditif view Rekap (di-SUM untuk subtotal). */
    private const REKAP_ADDITIVE = [
        'rbi_pokok', 'rbi_ha', 'rbi_real',
        'rsbi_pokok', 'rsbi_ha', 'rsbi_real',
        'kbi_pokok', 'kbi_ha', 'kbi_rkap',
        'ksbi_pokok', 'ksbi_ha', 'ksbi_rkap',
    ];

    /** Kolom nilai aditif view Rekap-2 (di-SUM untuk subtotal). */
    private const REKAP2_ADDITIVE = [
        'sa_pokok', 'sa_ha', 'sa_murni', 'sa_borrowing', 'sa_jlh', 'sa_impair', 'sa_total',
        'pn_pokok', 'pn_ha', 'pn_murni', 'pn_reklas', 'pn_borrowing', 'pn_jlh',
        'pg_pokok', 'pg_ha', 'pg_murni', 'pg_reklas', 'pg_borrowing', 'pg_impair', 'pg_jlh',
        'sk_pokok', 'sk_ha', 'sk_murni', 'sk_borrowing', 'sk_jlh', 'sk_impair', 'sk_total',
    ];

    /**
     * Bangun tampilan Rekap.
     *
     * @return array{columns: array<int,array<string,mixed>>, rows: array<int,array<string,mixed>>}
     */
    public function buildRekap(Batch $batch, ?string $unit = null, string $komoditi = 'KS'): array
    {
        $names = $this->kebunNames();
        $plants = $this->plantFilter($unit);
        $month = (int) $batch->month;

        $pokokHa = $this->arealAgg($batch, $komoditi);
        $wbsBi = $this->wbsAgg($batch, $komoditi, $month, false);
        $wbsSbi = $this->wbsAgg($batch, $komoditi, $month, true);

        $rows = [];
        foreach (self::REKAP_BLOCKS as [$faseSap, $fase, $tahun]) {
            $mappedFase = self::REKAP_FASE_MAP[$fase] ?? $fase;
            $detailRows = [];

            foreach ($plants as $plant) {
                $areal = $pokokHa["{$plant}|{$faseSap}|{$tahun}"] ?? ['pokok' => 0.0, 'ha' => 0.0];
                $pokok = (float) $areal['pokok'];
                $ha = (float) $areal['ha'];

                $realBi = (float) ($wbsBi["{$plant}|{$mappedFase}|{$tahun}"] ?? 0.0);
                $realSbi = (float) ($wbsSbi["{$plant}|{$mappedFase}|{$tahun}"] ?? 0.0);

                // RKAP tidak tersedia di workbook → 0.
                $rkapBi = 0.0;
                $rkapSbi = 0.0;

                $detailRows[] = [
                    'plant' => $plant,
                    'kebun' => $names[$plant] ?? $plant,
                    'fase_sap' => $faseSap,
                    'fase' => $fase,
                    'tahun_tanam' => $tahun,
                    'row_type' => 'detail',

                    'rbi_pokok' => $pokok,
                    'rbi_ha' => $ha,
                    'rbi_real' => $realBi,
                    'rbi_rp_pkk' => $this->safeDiv($realBi, $pokok),
                    'rbi_rp_ha' => $this->safeDiv($realBi, $ha),

                    'rsbi_pokok' => $pokok,
                    'rsbi_ha' => $ha,
                    'rsbi_real' => $realSbi,
                    'rsbi_rp_pkk' => $this->safeDiv($realSbi, $pokok),
                    'rsbi_rp_ha' => $this->safeDiv($realSbi, $ha),

                    'kbi_pokok' => 0.0,
                    'kbi_ha' => 0.0,
                    'kbi_rkap' => $rkapBi,
                    'kbi_rp_pkk' => 0.0,
                    'kbi_rp_ha' => 0.0,

                    'ksbi_pokok' => 0.0,
                    'ksbi_ha' => 0.0,
                    'ksbi_rkap' => $rkapSbi,
                    'ksbi_rp_pkk' => 0.0,
                    'ksbi_rp_ha' => 0.0,

                    'cap_bi' => $this->percent($realBi, $rkapBi),
                    'cap_sbi' => $this->percent($realSbi, $rkapSbi),
                ];
            }

            foreach ($detailRows as $row) {
                $rows[] = $row;
            }
            $rows[] = $this->rekapSubtotal($detailRows, 'Jumh');
        }

        return ['columns' => $this->rekapColumns(), 'rows' => $rows];
    }

    /**
     * Bangun tampilan Rekap-2.
     *
     * @return array{columns: array<int,array<string,mixed>>, rows: array<int,array<string,mixed>>}
     */
    public function buildRekap2(Batch $batch, ?string $unit = null, string $komoditi = 'KS'): array
    {
        $names = $this->kebunNames();
        $plants = $this->plantFilter($unit);

        $pokokHa = $this->arealAgg($batch, $komoditi);
        $assetAgg = $this->assetAgg($batch, $komoditi);

        $rows = [];
        foreach (self::REKAP2_BLOCKS as [$faseSap, $fase, $tahun]) {
            $detailRows = [];

            foreach ($plants as $plant) {
                $areal = $pokokHa["{$plant}|{$faseSap}|{$tahun}"] ?? ['pokok' => 0.0, 'ha' => 0.0];
                $pokok = (float) $areal['pokok'];
                $ha = (float) $areal['ha'];

                // investasi_asset: fase sudah rinci = block.fase (tanpa pemetaan).
                $a = $assetAgg["{$plant}|{$fase}|{$tahun}"] ?? [];
                $apcAll = (float) ($a['apc_all'] ?? 0.0);
                $apcBorrow = (float) ($a['apc_borrow'] ?? 0.0);
                $acq = (float) ($a['acq'] ?? 0.0);
                $trfD = (float) ($a['trf_D'] ?? 0.0);
                $trfK = (float) ($a['trf_K'] ?? 0.0);
                $impAwal = (float) ($a['imp_awal'] ?? 0.0);
                $impKurang = (float) ($a['imp_kurang'] ?? 0.0);

                // --- Saldo Awal ---
                $saPokok = $pokok;
                $saHa = $ha;
                $saBorrowing = $apcBorrow;
                $saMurni = $apcAll - $apcBorrow;
                $saJlh = $saMurni + $saBorrowing;
                $saImpair = $impAwal;
                $saTotal = $saJlh + $saImpair;

                // --- Penambahan Tahun Ini ---
                $pnPokok = 0.0;
                $pnHa = 0.0;
                $pnMurni = $acq;
                $pnReklas = $trfD;
                $pnBorrowing = 0.0;
                $pnJlh = $pnMurni + $pnReklas + $pnBorrowing;

                // --- Pengurangan Tahun Ini ---
                $pgPokok = 0.0;
                $pgHa = 0.0;
                $pgMurni = 0.0;
                $pgReklas = $trfK;
                $pgBorrowing = 0.0;
                $pgImpair = $impKurang;
                $pgJlh = $pgMurni + $pgReklas + $pgBorrowing + $pgImpair;

                // --- Saldo Akhir ---
                $skPokok = $saPokok + $pnPokok + $pgPokok;
                $skHa = $saHa + $pnHa + $pgHa;
                $skMurni = $saMurni + $pnMurni + $pgMurni + $pnReklas + $pgReklas;
                $skBorrowing = $saBorrowing + $pnBorrowing + $pgBorrowing;
                $skJlh = $skMurni + $skBorrowing;
                $skImpair = $saImpair + $pgImpair;
                $skTotal = $skJlh + $skImpair;

                $detailRows[] = [
                    'plant' => $plant,
                    'kebun' => $names[$plant] ?? $plant,
                    'fase_sap' => $faseSap,
                    'fase' => $fase,
                    'tahun_tanam' => $tahun,
                    'row_type' => 'detail',

                    'sa_pokok' => $saPokok,
                    'sa_ha' => $saHa,
                    'sa_murni' => $saMurni,
                    'sa_borrowing' => $saBorrowing,
                    'sa_jlh' => $saJlh,
                    'sa_impair' => $saImpair,
                    'sa_total' => $saTotal,
                    'sa_rp_pkk' => $this->safeDiv($saTotal, $saPokok),
                    'sa_rp_ha' => $this->safeDiv($saTotal, $saHa),

                    'pn_pokok' => $pnPokok,
                    'pn_ha' => $pnHa,
                    'pn_murni' => $pnMurni,
                    'pn_reklas' => $pnReklas,
                    'pn_borrowing' => $pnBorrowing,
                    'pn_jlh' => $pnJlh,

                    'pg_pokok' => $pgPokok,
                    'pg_ha' => $pgHa,
                    'pg_murni' => $pgMurni,
                    'pg_reklas' => $pgReklas,
                    'pg_borrowing' => $pgBorrowing,
                    'pg_impair' => $pgImpair,
                    'pg_jlh' => $pgJlh,

                    'sk_pokok' => $skPokok,
                    'sk_ha' => $skHa,
                    'sk_murni' => $skMurni,
                    'sk_borrowing' => $skBorrowing,
                    'sk_jlh' => $skJlh,
                    'sk_impair' => $skImpair,
                    'sk_total' => $skTotal,
                    'sk_rp_pkk' => $this->safeDiv($skTotal, $skPokok),
                    'sk_rp_ha' => $this->safeDiv($skTotal, $skHa),
                ];
            }

            foreach ($detailRows as $row) {
                $rows[] = $row;
            }
            $rows[] = $this->rekap2Subtotal($detailRows, 'Jumlah');
        }

        return ['columns' => $this->rekap2Columns(), 'rows' => $rows];
    }

    /**
     * Subtotal blok Rekap: SUM kolom aditif, recompute rasio dari agregat.
     *
     * @param  array<int,array<string,mixed>>  $detailRows
     * @return array<string,mixed>
     */
    private function rekapSubtotal(array $detailRows, string $label): array
    {
        $sum = $this->sumAdditive($detailRows, self::REKAP_ADDITIVE);

        return array_merge($this->blankIdentity($label), $sum, [
            'row_type' => 'subtotal',
            'rbi_rp_pkk' => $this->safeDiv($sum['rbi_real'], $sum['rbi_pokok']),
            'rbi_rp_ha' => $this->safeDiv($sum['rbi_real'], $sum['rbi_ha']),
            'rsbi_rp_pkk' => $this->safeDiv($sum['rsbi_real'], $sum['rsbi_pokok']),
            'rsbi_rp_ha' => $this->safeDiv($sum['rsbi_real'], $sum['rsbi_ha']),
            'kbi_rp_pkk' => $this->safeDiv($sum['kbi_rkap'], $sum['kbi_pokok']),
            'kbi_rp_ha' => $this->safeDiv($sum['kbi_rkap'], $sum['kbi_ha']),
            'ksbi_rp_pkk' => $this->safeDiv($sum['ksbi_rkap'], $sum['ksbi_pokok']),
            'ksbi_rp_ha' => $this->safeDiv($sum['ksbi_rkap'], $sum['ksbi_ha']),
            'cap_bi' => $this->percent($sum['rbi_real'], $sum['kbi_rkap']),
            'cap_sbi' => $this->percent($sum['rsbi_real'], $sum['ksbi_rkap']),
        ]);
    }

    /**
     * Subtotal blok Rekap-2: SUM kolom aditif, recompute rasio dari agregat.
     *
     * @param  array<int,array<string,mixed>>  $detailRows
     * @return array<string,mixed>
     */
    private function rekap2Subtotal(array $detailRows, string $label): array
    {
        $sum = $this->sumAdditive($detailRows, self::REKAP2_ADDITIVE);

        return array_merge($this->blankIdentity($label), $sum, [
            'row_type' => 'subtotal',
            'sa_rp_pkk' => $this->safeDiv($sum['sa_total'], $sum['sa_pokok']),
            'sa_rp_ha' => $this->safeDiv($sum['sa_total'], $sum['sa_ha']),
            'sk_rp_pkk' => $this->safeDiv($sum['sk_total'], $sum['sk_pokok']),
            'sk_rp_ha' => $this->safeDiv($sum['sk_total'], $sum['sk_ha']),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $detailRows
     * @param  array<int,string>  $keys
     * @return array<string,float>
     */
    private function sumAdditive(array $detailRows, array $keys): array
    {
        $sum = array_fill_keys($keys, 0.0);
        foreach ($detailRows as $row) {
            foreach ($keys as $key) {
                $sum[$key] += (float) ($row[$key] ?? 0.0);
            }
        }

        return $sum;
    }

    /**
     * Identitas subtotal: hanya label pada kolom fase; sisanya null.
     *
     * @return array<string,mixed>
     */
    private function blankIdentity(string $label): array
    {
        return [
            'plant' => null,
            'kebun' => null,
            'fase_sap' => null,
            'fase' => $label,
            'tahun_tanam' => null,
        ];
    }

    /**
     * Agregat areal_blok → [ "plant|status_blok_petak|tahun" => [pokok, ha] ].
     *
     * @return array<string,array{pokok:float,ha:float}>
     */
    private function arealAgg(Batch $batch, string $komoditi): array
    {
        $rows = DB::table('areal_blok')
            ->select([
                'plant_code',
                'status_blok_petak',
                'tahun_tanam',
                DB::raw('SUM(total_pokok) AS pokok'),
                DB::raw('SUM(luas_ha) AS ha'),
            ])
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->groupBy('plant_code', 'status_blok_petak', 'tahun_tanam')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out["{$r->plant_code}|{$r->status_blok_petak}|{$r->tahun_tanam}"] = [
                'pokok' => (float) $r->pokok,
                'ha' => (float) $r->ha,
            ];
        }

        return $out;
    }

    /**
     * Agregat investasi_wbs (nilai) → [ "plant|fase|tahun" => nilai ].
     * BI: period = month; SBI (cumulative): period <= month.
     *
     * @return array<string,float>
     */
    private function wbsAgg(Batch $batch, string $komoditi, int $month, bool $cumulative): array
    {
        $query = DB::table('investasi_wbs')
            ->select([
                'plant_code',
                'fase',
                'tahun_tanam',
                DB::raw('SUM(nilai) AS nilai'),
            ])
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi);

        if ($cumulative) {
            $query->where('period', '<=', $month);
        } else {
            $query->where('period', $month);
        }

        $rows = $query->groupBy('plant_code', 'fase', 'tahun_tanam')->get();

        $out = [];
        foreach ($rows as $r) {
            $out["{$r->plant_code}|{$r->fase}|{$r->tahun_tanam}"] = (float) $r->nilai;
        }

        return $out;
    }

    /**
     * Agregat investasi_asset → [ "plant|fase|tahun" => [apc_all, apc_borrow, acq, trf_D, trf_K, imp_awal, imp_kurang] ].
     *
     * @return array<string,array<string,float>>
     */
    private function assetAgg(Batch $batch, string $komoditi): array
    {
        $rows = DB::table('investasi_asset')
            ->select([
                'plant_code',
                'fase',
                'tahun_tanam',
                DB::raw('SUM(apc_start) AS apc_all'),
                DB::raw("SUM(CASE WHEN klasifikasi = 'Borrowing' THEN apc_start ELSE 0 END) AS apc_borrow"),
                DB::raw('SUM(acquisition) AS acq'),
                DB::raw("SUM(CASE WHEN dk_flag = 'D' THEN transfer ELSE 0 END) AS trf_d"),
                DB::raw("SUM(CASE WHEN dk_flag = 'K' THEN transfer ELSE 0 END) AS trf_k"),
                DB::raw('SUM(reklas_debet) + SUM(impair_awal) AS imp_awal'),
                DB::raw('SUM(impair_pengurangan) AS imp_kurang'),
            ])
            ->where('batch_id', $batch->id)
            ->where('komoditi', $komoditi)
            ->groupBy('plant_code', 'fase', 'tahun_tanam')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out["{$r->plant_code}|{$r->fase}|{$r->tahun_tanam}"] = [
                'apc_all' => (float) $r->apc_all,
                'apc_borrow' => (float) $r->apc_borrow,
                'acq' => (float) $r->acq,
                'trf_D' => (float) $r->trf_d,
                'trf_K' => (float) $r->trf_k,
                'imp_awal' => (float) $r->imp_awal,
                'imp_kurang' => (float) $r->imp_kurang,
            ];
        }

        return $out;
    }

    /**
     * Daftar plant yang dilibatkan. Null/'ALL' → seluruh KEBUN_ORDER;
     * kode spesifik → hanya kebun itu (bila termasuk KEBUN_ORDER).
     *
     * @return array<int,string>
     */
    private function plantFilter(?string $unit): array
    {
        if ($unit === null || $unit === '' || strtoupper($unit) === 'ALL') {
            return self::KEBUN_ORDER;
        }

        return array_values(array_filter(self::KEBUN_ORDER, fn (string $code) => $code === $unit));
    }

    /**
     * Peta kode kebun → nama (fallback ke kode).
     *
     * @return array<string,string>
     */
    private function kebunNames(): array
    {
        return RefUnit::query()->pluck('name', 'code')->toArray();
    }

    /** Pembagian aman: penyebut ~0 → 0. */
    private function safeDiv(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /** Rasio persen aman: penyebut ~0 → 0 (selaras ReportController::percent). */
    private function percent(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 0.00001) {
            return 0.0;
        }

        $value = round(($numerator / $denominator) * 100, 2);

        return max(-99999999.99, min(99999999.99, $value));
    }

    /**
     * Definisi kolom view Rekap (grouped header).
     *
     * @return array<int,array<string,mixed>>
     */
    public function rekapColumns(): array
    {
        return [
            ['key' => 'plant', 'title' => 'Plant', 'frozen' => true, 'group' => null],
            ['key' => 'kebun', 'title' => 'Kebun', 'frozen' => true, 'group' => null],
            ['key' => 'fase_sap', 'title' => 'Fase SAP', 'frozen' => true, 'group' => null],
            ['key' => 'fase', 'title' => 'Fase', 'frozen' => true, 'group' => null],
            ['key' => 'tahun_tanam', 'title' => 'Tahun Tanam', 'frozen' => true, 'group' => null],

            ['key' => 'rbi_pokok', 'title' => 'Jlh.Pokok', 'group' => 'Realisasi Bulan Ini'],
            ['key' => 'rbi_ha', 'title' => 'Jlh. Ha', 'group' => 'Realisasi Bulan Ini'],
            ['key' => 'rbi_real', 'title' => 'Real BI', 'group' => 'Realisasi Bulan Ini'],
            ['key' => 'rbi_rp_pkk', 'title' => 'Rp/Pkk', 'group' => 'Realisasi Bulan Ini'],
            ['key' => 'rbi_rp_ha', 'title' => 'Rp/Ha', 'group' => 'Realisasi Bulan Ini'],

            ['key' => 'rsbi_pokok', 'title' => 'Jlh.Pokok', 'group' => 'Realisasi s.d Bulan Ini'],
            ['key' => 'rsbi_ha', 'title' => 'Jlh. Ha', 'group' => 'Realisasi s.d Bulan Ini'],
            ['key' => 'rsbi_real', 'title' => 'Real SBI', 'group' => 'Realisasi s.d Bulan Ini'],
            ['key' => 'rsbi_rp_pkk', 'title' => 'Rp/Pkk', 'group' => 'Realisasi s.d Bulan Ini'],
            ['key' => 'rsbi_rp_ha', 'title' => 'Rp/Ha', 'group' => 'Realisasi s.d Bulan Ini'],

            ['key' => 'kbi_pokok', 'title' => 'Jlh.Pokok', 'group' => 'RKAP Bulan Ini'],
            ['key' => 'kbi_ha', 'title' => 'Jlh. Ha', 'group' => 'RKAP Bulan Ini'],
            ['key' => 'kbi_rkap', 'title' => 'RKAP BI', 'group' => 'RKAP Bulan Ini'],
            ['key' => 'kbi_rp_pkk', 'title' => 'Rp/Pkk', 'group' => 'RKAP Bulan Ini'],
            ['key' => 'kbi_rp_ha', 'title' => 'Rp/Ha', 'group' => 'RKAP Bulan Ini'],

            ['key' => 'ksbi_pokok', 'title' => 'Jlh.Pokok', 'group' => 'RKAP s.d Bulan Ini'],
            ['key' => 'ksbi_ha', 'title' => 'Jlh. Ha', 'group' => 'RKAP s.d Bulan Ini'],
            ['key' => 'ksbi_rkap', 'title' => 'RKAP SBI', 'group' => 'RKAP s.d Bulan Ini'],
            ['key' => 'ksbi_rp_pkk', 'title' => 'Rp/Pkk', 'group' => 'RKAP s.d Bulan Ini'],
            ['key' => 'ksbi_rp_ha', 'title' => 'Rp/Ha', 'group' => 'RKAP s.d Bulan Ini'],

            ['key' => 'cap_bi', 'title' => 'BI', 'group' => 'Capaian (%)'],
            ['key' => 'cap_sbi', 'title' => 'SBI', 'group' => 'Capaian (%)'],
        ];
    }

    /**
     * Definisi kolom view Rekap-2 (grouped header, 36 kolom).
     *
     * @return array<int,array<string,mixed>>
     */
    public function rekap2Columns(): array
    {
        return [
            ['key' => 'plant', 'title' => 'Plant', 'frozen' => true, 'group' => null],
            ['key' => 'kebun', 'title' => 'Kebun', 'frozen' => true, 'group' => null],
            ['key' => 'fase_sap', 'title' => 'Fase SAP', 'frozen' => true, 'group' => null],
            ['key' => 'fase', 'title' => 'Fase', 'frozen' => true, 'group' => null],
            ['key' => 'tahun_tanam', 'title' => 'Tahun Tanam', 'frozen' => true, 'group' => null],

            ['key' => 'sa_pokok', 'title' => 'Jlh.Pokok', 'group' => 'Saldo Awal'],
            ['key' => 'sa_ha', 'title' => 'Jlh. Ha', 'group' => 'Saldo Awal'],
            ['key' => 'sa_murni', 'title' => 'Murni', 'group' => 'Saldo Awal'],
            ['key' => 'sa_borrowing', 'title' => 'Borrowing Cost', 'group' => 'Saldo Awal', 'minWidth' => 120],
            ['key' => 'sa_jlh', 'title' => 'Jlh Biaya', 'group' => 'Saldo Awal'],
            ['key' => 'sa_impair', 'title' => 'Impair', 'group' => 'Saldo Awal'],
            ['key' => 'sa_total', 'title' => 'Total', 'group' => 'Saldo Awal'],
            ['key' => 'sa_rp_pkk', 'title' => 'Rp/Pkk', 'group' => 'Saldo Awal'],
            ['key' => 'sa_rp_ha', 'title' => 'Rp/Ha', 'group' => 'Saldo Awal'],

            ['key' => 'pn_pokok', 'title' => 'Jlh.Pokok', 'group' => 'Penambahan Tahun Ini'],
            ['key' => 'pn_ha', 'title' => 'Jlh. Ha', 'group' => 'Penambahan Tahun Ini'],
            ['key' => 'pn_murni', 'title' => 'Murni', 'group' => 'Penambahan Tahun Ini'],
            ['key' => 'pn_reklas', 'title' => 'Reklasifikasi', 'group' => 'Penambahan Tahun Ini'],
            ['key' => 'pn_borrowing', 'title' => 'Borrowing Cost', 'group' => 'Penambahan Tahun Ini', 'minWidth' => 120],
            ['key' => 'pn_jlh', 'title' => 'Jlh Biaya', 'group' => 'Penambahan Tahun Ini'],

            ['key' => 'pg_pokok', 'title' => 'Jlh.Pokok', 'group' => 'Pengurangan Tahun Ini'],
            ['key' => 'pg_ha', 'title' => 'Jlh. Ha', 'group' => 'Pengurangan Tahun Ini'],
            ['key' => 'pg_murni', 'title' => 'Murni', 'group' => 'Pengurangan Tahun Ini'],
            ['key' => 'pg_reklas', 'title' => 'Reklasifikasi', 'group' => 'Pengurangan Tahun Ini'],
            ['key' => 'pg_borrowing', 'title' => 'Borrowing Cost', 'group' => 'Pengurangan Tahun Ini', 'minWidth' => 120],
            ['key' => 'pg_impair', 'title' => 'Impair', 'group' => 'Pengurangan Tahun Ini'],
            ['key' => 'pg_jlh', 'title' => 'Jlh Biaya', 'group' => 'Pengurangan Tahun Ini'],

            ['key' => 'sk_pokok', 'title' => 'Jlh.Pokok', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_ha', 'title' => 'Jlh. Ha', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_murni', 'title' => 'Murni', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_borrowing', 'title' => 'Borrowing Cost', 'group' => 'Saldo Akhir', 'minWidth' => 120],
            ['key' => 'sk_jlh', 'title' => 'Jlh Biaya', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_impair', 'title' => 'Impair', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_total', 'title' => 'Total', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_rp_pkk', 'title' => 'Rp/Pkk', 'group' => 'Saldo Akhir'],
            ['key' => 'sk_rp_ha', 'title' => 'Rp/Ha', 'group' => 'Saldo Akhir'],
        ];
    }
}
