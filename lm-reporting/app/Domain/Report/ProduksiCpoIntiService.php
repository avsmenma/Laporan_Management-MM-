<?php

namespace App\Domain\Report;

use Illuminate\Support\Facades\DB;

/**
 * Materialisasi tabel "PRODUKSI CPO + INTI" (tabel dasar Alokasi Biaya Olah).
 *
 * Nilai per sel (kebun × plant) = produksi Minyak Sawit + produksi Inti Sawit,
 * ditarik dari produksi_pks. Diambil snapshot posting_date TERBARU pada tiap
 * (tahun, bulan) — konsisten dengan halaman Produksi PKS (ProduksiController).
 *
 * Dua blok disimpan sekaligus:
 *   - "Bulan Ini"      = ms_sdhari  + is_sdhari
 *   - "S.D Bulan Ini"  = ms_sdbulan + is_sdbulan
 *
 * Idempoten per (tahun, bulan): hapus lalu isi ulang periode tersebut.
 */
class ProduksiCpoIntiService
{
    /**
     * Nama singkat PKS untuk kolom (mengikuti file acuan CONTOH PRODUKSI PKS V2).
     * Sinkron dengan ProduksiController::PLANT_SHORT.
     */
    private const PLANT_SHORT = [
        '5F01' => 'Pagun', // PKS Gunung Meliau
        '5F04' => 'Parba', // PKS Rimba Belian
        '5F07' => 'Panga', // PKS Ngabang
        '5F08' => 'Papar', // PKS Parindu
        '5F09' => 'Pakem', // PKS Kembayan
        '5F14' => 'Papam', // PKS Pamukan
        '5F15' => 'Papel', // PKS Pelaihari
        '5F21' => 'Pasam', // PKS Samuntai
        '5F22' => 'Palpi', // PKS Long Pinang
    ];

    /**
     * (Re)generasi seluruh periode yang ada di produksi_pks.
     *
     * @return array<int, array{year:int, month:int, cells:int}>
     */
    public function generateAll(): array
    {
        $out = [];
        foreach ($this->periods() as [$year, $month]) {
            $out[] = [
                'year' => $year,
                'month' => $month,
                'cells' => $this->generate($year, $month),
            ];
        }

        return $out;
    }

    /**
     * (Re)generasi satu periode (tahun, bulan). Mengembalikan jumlah sel (baris tabel)
     * yang ditulis. Bila periode tak punya data produksi → kosongkan periode & return 0.
     */
    public function generate(int $year, int $month): int
    {
        $date = $this->latestDate($year, $month);

        $records = [];
        if ($date !== null) {
            $rows = DB::table('produksi_pks')->whereDate('posting_date', $date)->get();

            // Agregasi per (kebun, plant) — sumber bisa >1 baris per sel
            // (mis. group_pemilik Kebun Sendiri vs Pihak III).
            $cells = [];
            foreach ($rows as $r) {
                $k = trim((string) $r->kebun_code);
                $p = trim((string) $r->plant_code);
                if ($k === '' || $p === '') {
                    continue;
                }
                $key = $k.'|'.$p;
                if (! isset($cells[$key])) {
                    $cells[$key] = [
                        'kebun_code' => $k,
                        'nama_kebun' => (string) $r->nama_kebun,
                        'plant_code' => $p,
                        'ms_bi' => 0.0, 'is_bi' => 0.0, 'ms_sd' => 0.0, 'is_sd' => 0.0,
                    ];
                }
                $cells[$key]['ms_bi'] += (float) $r->ms_sdhari;
                $cells[$key]['is_bi'] += (float) $r->is_sdhari;
                $cells[$key]['ms_sd'] += (float) $r->ms_sdbulan;
                $cells[$key]['is_sd'] += (float) $r->is_sdbulan;
            }

            $now = now();
            foreach ($cells as $c) {
                $records[] = [
                    'year' => $year,
                    'month' => $month,
                    'posting_date' => $date,
                    'kebun_code' => $c['kebun_code'],
                    'nama_kebun' => $c['nama_kebun'] !== '' ? $c['nama_kebun'] : null,
                    'plant_code' => $c['plant_code'],
                    'plant_short' => self::PLANT_SHORT[$c['plant_code']] ?? null,
                    'ms_bulan_ini' => $c['ms_bi'],
                    'is_bulan_ini' => $c['is_bi'],
                    'produksi_bulan_ini' => $c['ms_bi'] + $c['is_bi'],
                    'ms_sd' => $c['ms_sd'],
                    'is_sd' => $c['is_sd'],
                    'produksi_sd' => $c['ms_sd'] + $c['is_sd'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($year, $month, $records): void {
            DB::table('produksi_cpo_inti')->where('year', $year)->where('month', $month)->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('produksi_cpo_inti')->insert($chunk);
            }
        });

        return count($records);
    }

    /**
     * Daftar periode (tahun, bulan) yang ada di produksi_pks.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    public function periods(): array
    {
        return DB::table('produksi_pks')
            ->selectRaw('YEAR(posting_date) y, MONTH(posting_date) m')
            ->groupBy('y', 'm')
            ->orderBy('y')->orderBy('m')
            ->get()
            ->map(fn ($r) => [(int) $r->y, (int) $r->m])
            ->all();
    }

    /** Tanggal posting terbaru pada (tahun, bulan); null bila tak ada data. */
    private function latestDate(int $year, int $month): ?string
    {
        $max = DB::table('produksi_pks')
            ->whereYear('posting_date', $year)
            ->whereMonth('posting_date', $month)
            ->max('posting_date');

        return $max !== null ? substr((string) $max, 0, 10) : null;
    }
}
