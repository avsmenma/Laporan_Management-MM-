<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman Persediaan Akhir Hasil Produksi (/laba-rugi/persediaan).
 *
 * Kolom PRODUKSI (Kg) tab KELAPA SAWIT dari `produksi_pks` (tabel yang sama
 * dengan halaman /produksi/pks):
 * - Minyak Sawit       ← Σ ms_sdbulan
 * - Inti Sawit         ← Σ is_sdbulan
 * - Tandan Buah Segar  ← Σ tbs_diterima_sdbulan (TBS DITERIMA, keputusan user)
 * per plant PKS pada SNAPSHOT posting_date TERBARU di bulan filter — angkanya
 * identik dengan baris Grand Total blok "S.D Bulan Ini" halaman produksi.
 *
 * Kolom PENGELUARAN → PENGOLAHAN SENDIRI (Kg) baris Tandan Buah Segar dari
 * sumber yang sama: Σ tbs_diolah_sdbulan per plant = Grand Total tab
 * "TBS DIOLAH" blok "S.D Bulan Ini" halaman /produksi/pks (keputusan user).
 *
 * Kolom PENGELUARAN → PENJUALAN (Kg) dari `penjualan_produk` (tabel halaman
 * /laba-rugi/penjualan): Σ qty s.d bulan filter per plant — identik blok
 * "SD BULAN INI → QTY" tab PLANT. Minyak Sawit ← material CPO, Inti Sawit ←
 * material INTI SAWIT (keputusan user); TBS tidak diisi.
 *
 * Kolom NILAI PERSEDIAAN AKHIR (Rp) dari `persediaan_nilai` (impor ZSTOCK,
 * jenis "Persediaan — Nilai Akhir") pada periode filter, dicocokkan per
 * produk × unit kerja. Baris "Penyesuaian atas nilai persediaan akhir" pada
 * kolom ini diisi manual (kolom PERSEDIAAN AKHIR tab PENYESUAIAN).
 *
 * Kolom PENERIMAAN TRANSFER, PENGELUARAN→TRANSFER, SUSUT, dan SELISIH STOCK
 * OPNAME (GR/GI) dari `persediaan_penyesuaian` — isian manual tab PENYESUAIAN.
 *
 * Kolom lain belum ada sumber → frontend menampilkan '-'.
 */
class PersediaanController extends Controller
{
    use AuthorizesReportRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $dates = DB::table('produksi_pks')
            ->select('posting_date')->distinct()->orderByDesc('posting_date')
            ->pluck('posting_date')->map(fn ($d) => substr((string) $d, 0, 10))->values()->all();

        // Periode = (tahun, bulan), diwakili tanggal posting TERBARU bulan itu
        // (snapshot s.d bulan paling lengkap) — pola sama dengan ProduksiController.
        $latestByPeriod = [];
        foreach ($dates as $d) {
            $key = substr($d, 0, 7); // "YYYY-MM"
            $latestByPeriod[$key] = $latestByPeriod[$key] ?? $d;
        }
        $periods = [];
        foreach (array_keys($latestByPeriod) as $key) {
            [$yy, $mm] = explode('-', $key);
            $periods[] = ['year' => (int) $yy, 'month' => (int) $mm];
        }
        usort($periods, fn ($a, $b) => ($b['year'] <=> $a['year']) ?: ($b['month'] <=> $a['month']));

        // Tanpa parameter → adopsi periode terbaru. Dengan parameter → persis
        // periode itu; bulan tanpa snapshot → produksi null (semua sel '-'),
        // TIDAK diam-diam jatuh ke bulan lain.
        $year = $request->query('year');
        $month = $request->query('month');

        // Belum ada snapshot produksi SAMA SEKALI & periode tak diminta → kosong.
        // (Bila periode diminta, kolom non-produksi tetap dilayani.)
        if ($periods === [] && ($year === null || $month === null)) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null, 'date' => null,
                'produksi' => null, 'penjualan' => null, 'nilai' => [], 'penyesuaian' => [],
            ]);
        }

        if ($year === null || $month === null) {
            $year = $periods[0]['year'];
            $month = $periods[0]['month'];
        }
        $year = (int) $year;
        $month = (int) $month;
        $date = $latestByPeriod[sprintf('%04d-%02d', $year, $month)] ?? null;

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'date' => $date,
            'produksi' => $date === null ? null : $this->produksiPerPlant($date),
            // Penjualan & nilai persediaan TIDAK bergantung snapshot produksi —
            // periode tanpa snapshot tetap bisa punya angkanya.
            'penjualan' => $this->penjualanPerPlant($year, $month),
            'nilai' => $this->nilaiAkhir($year, $month),
            'penyesuaian' => $this->penyesuaian($year, $month),
        ]);
    }

    /**
     * Penyesuaian manual (tab PENYESUAIAN) periode terpilih: produk → PLANT →
     * kolom nilai (lima kuantitas Kg + `nilai_akhir` Rp). Baris ber-plant/produk
     * 'Penyesuaian Nilai Akhir' dikumpulkan ke kunci khusus `_penyes` (baris
     * "Penyesuaian atas nilai persediaan akhir" pada tabel).
     *
     * @return array<string, array<string, array<string, float>>>
     */
    private function penyesuaian(int $year, int $month): array
    {
        $kolom = \App\Http\Controllers\PersediaanPenyesuaianController::kolomNilai();
        $khusus = self::norm(\App\Http\Controllers\PersediaanPenyesuaianController::KHUSUS);

        $rows = DB::table('persediaan_penyesuaian')
            ->where('year', $year)->where('month', $month)->get();

        $out = [];
        foreach ($rows as $r) {
            $produk = self::norm((string) $r->product);
            $plant = self::norm((string) $r->plant_code);
            [$kProduk, $kPlant] = ($produk === $khusus || $plant === $khusus)
                ? ['_penyes', '_penyes']
                : [$produk, $plant];

            $out[$kProduk][$kPlant] ??= array_fill_keys($kolom, 0.0);
            foreach ($kolom as $k) {
                $out[$kProduk][$kPlant][$k] += (float) $r->{$k};
            }
        }

        return $out;
    }

    /**
     * Nilai baris "Jumlah Persediaan" kolom NILAI PERSEDIAAN AKHIR (Rp) satu tab
     * = Σ nilai ZSTOCK seluruh produk × unit yang PUNYA baris di halaman
     * (App\Domain\Report\PersediaanStruktur) + baris "Penyesuaian atas nilai
     * persediaan akhir". Dipakai baris "Persediaan Akhir" LM-27A supaya angkanya
     * persis sama dengan halaman ini.
     *
     * Baris berkas yang unit/produknya tidak ada di halaman (mis. 5E13 Kebun
     * Batulicin) DIABAIKAN — sama seperti yang dilakukan tabelnya.
     *
     * ⚠️ Baris penyesuaian TIDAK terpisah per komoditi (satu ember `_penyes`),
     * jadi ia ikut terhitung di tab mana pun. Menjumlahkan hasil 'sawit' + 'karet'
     * berarti menghitung penyesuaian itu DUA KALI.
     */
    public function nilaiAkhirJumlah(int $year, int $month, string $tab): float
    {
        $nilai = $this->nilaiAkhir($year, $month);
        $units = \App\Domain\Report\PersediaanStruktur::unitKeys($tab);

        $total = 0.0;
        foreach (\App\Domain\Report\PersediaanStruktur::produkKeys($tab) as $produk) {
            foreach ($units as $unit) {
                $total += (float) ($nilai[$produk][$unit] ?? 0);
            }
        }

        // Baris penyesuaian tidak punya produk/unit — nilainya berdiri sendiri dan
        // berlaku untuk tab mana pun yang sedang dibuka, sama seperti di tabel.
        $penyesuaian = $this->penyesuaian($year, $month);

        return $total + (float) ($penyesuaian['_penyes']['_penyes']['nilai_akhir'] ?? 0);
    }

    /** Kunci pencocokan longgar: huruf besar, tanpa awalan '-'/spasi ganda. */
    private static function norm(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';

        return mb_strtoupper(ltrim($s, "- \t"));
    }

    /**
     * Nilai persediaan akhir (Rp) hasil impor ZSTOCK untuk periode terpilih:
     * produk → "PLANT|UNIT KERJA" → Rp. Kunci dinormalkan (huruf besar, tanpa
     * awalan '-') supaya label berkas ("LUMP") cocok dgn label halaman ("- LUMP").
     *
     * @return array<string, array<string, float>>
     */
    private function nilaiAkhir(int $year, int $month): array
    {
        $rows = DB::table('persediaan_nilai')
            ->where('year', $year)->where('period', $month)
            ->select('plant_code', 'unit_name', 'product', 'nilai_rp')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $produk = self::norm((string) $r->product);
            $unit = self::norm((string) $r->plant_code).'|'.self::norm((string) $r->unit_name);
            $out[$produk][$unit] = ($out[$produk][$unit] ?? 0) + (float) $r->nilai_rp;
        }

        return $out;
    }

    /**
     * Σ kolom *_sdbulan per plant PKS pada snapshot $date, dibulatkan 0 desimal
     * setelah dijumlah (round-of-sum) — identik Grand Total halaman produksi.
     *
     * @return array{ms: array<string, float>, is: array<string, float>, tbs: array<string, float>, olah: array<string, float>}
     */
    private function produksiPerPlant(string $date): array
    {
        $rows = DB::table('produksi_pks')
            ->selectRaw(
                'plant_code, SUM(ms_sdbulan) AS ms, SUM(is_sdbulan) AS inti,'
                .' SUM(tbs_diterima_sdbulan) AS tbs, SUM(tbs_diolah_sdbulan) AS olah'
            )
            ->whereDate('posting_date', $date)
            ->whereNotNull('plant_code')
            ->where('plant_code', '!=', '')
            ->groupBy('plant_code')
            ->get();

        $out = ['ms' => [], 'is' => [], 'tbs' => [], 'olah' => []];
        foreach ($rows as $r) {
            $p = (string) $r->plant_code;
            $out['ms'][$p] = round((float) $r->ms);
            $out['is'][$p] = round((float) $r->inti);
            $out['tbs'][$p] = round((float) $r->tbs);
            $out['olah'][$p] = round((float) $r->olah);
        }

        return $out;
    }

    /** material_desc penjualan_produk → kunci produk halaman Persediaan. */
    private const PENJUALAN_MATERIAL = ['ms' => 'CPO', 'is' => 'INTI SAWIT'];

    /**
     * Σ qty penjualan s.d bulan filter per plant (4 digit awal profit center) —
     * identik blok "SD BULAN INI → QTY" tab PLANT halaman Penjualan. Qty GL
     * tersimpan kredit (negatif) → dibalik agar positif seperti di layar.
     *
     * @return array{ms: array<string, float>, is: array<string, float>}
     */
    private function penjualanPerPlant(int $year, int $month): array
    {
        $rows = DB::table('penjualan_produk')
            ->where('year', $year)
            ->where('period', '<=', $month)
            ->whereIn('material_desc', array_values(self::PENJUALAN_MATERIAL))
            // Dipotong jadi 4 digit di PHP (bukan LEFT/SUBSTR di SQL) agar sama
            // hasilnya di MySQL maupun SQLite yang dipakai test.
            ->selectRaw('material_desc, profit_center, SUM(qty) AS qty')
            ->groupBy('material_desc', 'profit_center')
            ->get();

        $out = ['ms' => [], 'is' => []];
        foreach ($rows as $r) {
            $key = array_search((string) $r->material_desc, self::PENJUALAN_MATERIAL, true);
            if ($key === false) {
                continue;
            }
            $plant = substr((string) $r->profit_center, 0, 4);
            $out[$key][$plant] = ($out[$key][$plant] ?? 0) + round(-1 * (float) $r->qty);
        }

        return $out;
    }
}
