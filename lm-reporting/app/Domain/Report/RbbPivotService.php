<?php

namespace App\Domain\Report;

use Illuminate\Support\Facades\DB;

/**
 * Materialisasi rbb_gl → rbb_pivot: agregat pada tingkat terdalam laporan RBB
 * (Klasifikasi 2 → Jenis Beban → Account, dipecah per Klasifikasi × Segmen).
 *
 * Subtotal tingkat 1 & 2 TIDAK disimpan — halaman menjumlahkannya dari baris agregat
 * ini, sehingga total selalu konsisten dengan rinciannya (tidak bisa berbeda sendiri).
 */
class RbbPivotService
{
    /** Bangun ulang agregat satu periode. Mengembalikan jumlah baris agregat. */
    public function generate(int $year, int $period): int
    {
        $rows = DB::table('rbb_gl')
            ->where('year', $year)
            ->where('period', $period)
            ->selectRaw('klasifikasi, klasifikasi_2, jenis_beban, account, gl_account_desc, segmen, SUM(amount) AS total')
            ->groupBy('klasifikasi', 'klasifikasi_2', 'jenis_beban', 'account', 'gl_account_desc', 'segmen')
            ->get();

        $records = [];
        foreach ($rows as $r) {
            $records[] = [
                'year' => $year,
                'period' => $period,
                'klasifikasi' => $r->klasifikasi,
                'klasifikasi_2' => $r->klasifikasi_2,
                'jenis_beban' => $r->jenis_beban,
                'account' => $r->account,
                'gl_account_desc' => $r->gl_account_desc,
                'segmen' => $r->segmen,
                'total' => (float) $r->total,
            ];
        }

        DB::transaction(function () use ($year, $period, $records): void {
            DB::table('rbb_pivot')->where('year', $year)->where('period', $period)->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('rbb_pivot')->insert($chunk);
            }
        });

        return count($records);
    }

    /**
     * Bangun ulang seluruh periode yang ada di rbb_gl (opsional dibatasi satu tahun).
     *
     * @return array<int, array{year: int, period: int, rows: int}>
     */
    public function generateAll(?int $year = null): array
    {
        $periods = DB::table('rbb_gl')
            ->when($year !== null, fn ($q) => $q->where('year', $year))
            ->select('year', 'period')->distinct()
            ->orderBy('year')->orderBy('period')
            ->get();

        $out = [];
        foreach ($periods as $p) {
            $out[] = [
                'year' => (int) $p->year,
                'period' => (int) $p->period,
                'rows' => $this->generate((int) $p->year, (int) $p->period),
            ];
        }

        return $out;
    }
}
