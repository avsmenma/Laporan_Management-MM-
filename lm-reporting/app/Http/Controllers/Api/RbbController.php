<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman RBB — "RINCIAN PNL" (template: sheet "Report I." workbook
 * Beban Pokok & Usaha), dibaca dari agregat rbb_pivot.
 *
 * Bentuknya persis PivotTable acuan:
 *  - BARIS 3 tingkat: Klasifikasi 2 → Jenis Beban → Account (+ GL Account Desc)
 *  - KOLOM dinamis: blok Klasifikasi × sub-kolom Segmen, hanya yang ADA datanya
 *    (mis. blok Penjualan Produk cuma punya Sawit & Karet), tiap blok ditutup kolom
 *    Total, ditutup Grand Total.
 *  - NILAI: SUM(amount) apa adanya — penjualan & pendapatan bertanda negatif.
 *
 * Sel hanya diisi bila kombinasi (baris × Klasifikasi × Segmen) benar-benar punya
 * posting; nol yang memang ada tetap ditampilkan 0, yang tidak ada dibiarkan kosong
 * seperti pivot Excel.
 */
class RbbController extends Controller
{
    private const BULAN = [
        1 => 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI',
        'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER',
    ];

    /** Kunci sel: blok+segmen, total blok, dan grand total. */
    private const KUNCI_TOTAL = '__total';

    private const KUNCI_GRAND = '__grand';

    public function index(Request $request): JsonResponse
    {
        // Tanpa parameter → periode terbaru yang sudah ada datanya (halaman memakai
        // ini saat pertama dibuka, agar tidak menampilkan bulan kosong).
        $terbaru = DB::table('rbb_pivot')->orderByDesc('year')->orderByDesc('period')->first(['year', 'period']);
        $year = (int) $request->query('year', (string) ($terbaru->year ?? now()->year));
        $month = (int) $request->query('month', (string) ($terbaru->period ?? now()->month));
        $month = max(1, min(12, $month));

        $rows = DB::table('rbb_pivot')
            ->where('year', $year)
            ->where('period', $month)
            ->get(['klasifikasi', 'klasifikasi_2', 'jenis_beban', 'account', 'gl_account_desc', 'segmen', 'total']);

        return response()->json([
            'periode' => [
                'year' => $year,
                'month' => $month,
                'label' => (self::BULAN[$month] ?? '').' '.$year,
            ],
            'ada_data' => $rows->isNotEmpty(),
            'blok' => $this->blok($rows),
            'baris' => $this->baris($rows),
        ]);
    }

    /**
     * Blok kolom = Klasifikasi yang ada datanya, tiap blok berisi Segmen yang ada
     * datanya. Urut alfabetis (label sudah berawalan angka: "1. Sawit", dst).
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array{nama: string, segmen: array<int, string>}>
     */
    private function blok($rows): array
    {
        $blok = [];
        foreach ($rows as $r) {
            $k = (string) $r->klasifikasi;
            $s = (string) $r->segmen;
            $blok[$k][$s] = true;
        }
        uksort($blok, $this->urutan(...));

        $out = [];
        foreach ($blok as $nama => $segmen) {
            $daftar = array_keys($segmen);
            usort($daftar, $this->urutan(...));
            $out[] = ['nama' => $nama, 'segmen' => $daftar];
        }

        return $out;
    }

    /**
     * Baris laporan berurut: tiap Klasifikasi 2 diikuti Jenis Beban-nya, lalu Account
     * di bawahnya, ditutup baris Grand Total.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function baris($rows): array
    {
        // Pohon: klasifikasi 2 → jenis beban → account, tiap simpul membawa peta nilai.
        $pohon = [];
        $grand = [];
        foreach ($rows as $r) {
            $k2 = (string) $r->klasifikasi_2;
            $jb = (string) $r->jenis_beban;
            $acc = (string) $r->account;
            $kunci = $r->klasifikasi.'|'.$r->segmen;
            $nilai = (float) $r->total;

            $pohon[$k2]['nilai'][$kunci] = ($pohon[$k2]['nilai'][$kunci] ?? 0) + $nilai;
            $pohon[$k2]['anak'][$jb]['nilai'][$kunci] = ($pohon[$k2]['anak'][$jb]['nilai'][$kunci] ?? 0) + $nilai;
            $pohon[$k2]['anak'][$jb]['anak'][$acc]['nilai'][$kunci] = ($pohon[$k2]['anak'][$jb]['anak'][$acc]['nilai'][$kunci] ?? 0) + $nilai;
            $pohon[$k2]['anak'][$jb]['anak'][$acc]['desc'] = (string) $r->gl_account_desc;
            $grand[$kunci] = ($grand[$kunci] ?? 0) + $nilai;
        }
        uksort($pohon, $this->urutan(...));

        $out = [];
        $no = 0;
        foreach ($pohon as $k2 => $simpul1) {
            $id1 = 'g'.(++$no);
            $out[] = $this->baris1($id1, null, 1, $k2, '', $simpul1['nilai']);

            $anak1 = $simpul1['anak'] ?? [];
            uksort($anak1, $this->urutan(...));
            foreach ($anak1 as $jb => $simpul2) {
                $id2 = 'g'.(++$no);
                $out[] = $this->baris1($id2, $id1, 2, $jb, '', $simpul2['nilai']);

                $anak2 = $simpul2['anak'] ?? [];
                uksort($anak2, $this->urutan(...));
                foreach ($anak2 as $acc => $simpul3) {
                    $out[] = $this->baris1('g'.(++$no), $id2, 3, $acc, $simpul3['desc'] ?? '', $simpul3['nilai']);
                }
            }
        }
        if ($rows->isNotEmpty()) {
            $out[] = $this->baris1('total', null, 0, 'Grand Total', '', $grand);
        }

        return $out;
    }

    /**
     * Satu baris laporan lengkap dengan total per blok & grand total.
     *
     * @param  array<string, float>  $nilai
     * @return array<string, mixed>
     */
    private function baris1(string $id, ?string $induk, int $level, string $uraian, string $gl, array $nilai): array
    {
        $sel = [];
        $totalBlok = [];
        $grand = null;
        foreach ($nilai as $kunci => $v) {
            $sel[$kunci] = $v;
            $blok = substr($kunci, 0, (int) strpos($kunci, '|'));
            $totalBlok[$blok] = ($totalBlok[$blok] ?? 0) + $v;
            $grand = ($grand ?? 0) + $v;
        }
        foreach ($totalBlok as $blok => $v) {
            $sel[$blok.'|'.self::KUNCI_TOTAL] = $v;
        }
        if ($grand !== null) {
            $sel[self::KUNCI_GRAND] = $grand;
        }

        return [
            'id' => $id,
            'induk' => $induk,
            'level' => $level,
            'uraian' => $uraian,
            'gl' => $gl,
            'nilai' => $sel,
        ];
    }

    /**
     * Urutan pivot Excel: alfabetis tanpa memandang besar-kecil huruf, tetapi label
     * yang tidak diawali huruf/angka (sisa pemetaan gagal, mis. "#N/A") ditaruh paling
     * bawah supaya tidak menyela laporan.
     */
    private function urutan(string $a, string $b): int
    {
        $rank = static fn (string $s): int => preg_match('/^[\p{L}\p{N}]/u', trim($s)) === 1 ? 0 : 1;
        $ra = $rank($a);
        $rb = $rank($b);

        return $ra === $rb ? strcasecmp($a, $b) : $ra <=> $rb;
    }
}
