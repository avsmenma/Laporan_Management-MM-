<?php

namespace App\Http\Controllers\Api;

use App\Domain\Report\PersediaanAwalTahun;
use App\Http\Controllers\Api\Concerns\AuthorizesReportRequests;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data tabel LM-27A (Perhitungan Laba/Rugi) — halaman /laba-rugi/lm27a.
 *
 * Tahap ini mengisi blok PENJUALAN baris "Lokal" dari sumber yang SAMA dengan
 * LM-34 (`penjualan_produk`, ekspor GL SAP) sehingga angkanya pasti sama dengan
 * /laba-rugi/penjualan tab LM 34 kolom "Jumlah Nilai Penjualan → Realisasi →
 * s.d Bulan ini" (LM-27A memang laporan kumulatif 1 Januari s/d bulan filter):
 *   Kelapa Sawit = Jumlah TBS + Jumlah A (CPO + Inti Sawit)
 *   Karet        = Jumlah C (Lump)
 *   Jumlah       = Jumlah Lokal ( A + B + C )
 * Pemetaan baris→sumber diambil dari Lm34Controller supaya satu sumber kebenaran.
 *
 * Blok HARGA POKOK PENJUALAN:
 *   "Persediaan Awal" — sumber SAMA dengan /laba-rugi/persediaan (konstanta
 *   App\Domain\Report\PersediaanAwalTahun), yaitu baris "Jumlah Persediaan" kolom
 *   PERSEDIAAN AWAL TAHUN (Rp) per tab.
 *   "Biaya Produksi" & "Penyusutan" — sumber SAMA dengan /kebun (LM Eksploitasi),
 *   kolom "s.d Bulan → Real s.d" blok "Kebun Sendiri + Pihak III", unit "Semua
 *   Unit", lewat ReportController::lm13Rows(). Kolom Karet keduanya masih '-'
 *   (lihat LM13_BUDIDAYA).
 *   "Persediaan Akhir" — baris "Jumlah Persediaan" kolom NILAI PERSEDIAAN AKHIR
 *   (Rp) halaman yang sama, lewat PersediaanController::nilaiAkhirJumlah().
 *
 * ⚠️ Tanda: tiga baris biaya (Persediaan Awal, Biaya Produksi, Penyusutan)
 * dikirim NEGATIF atas permintaan user, sehingga "Harga Pokok Penjualan" cukup
 * MENJUMLAHKAN seluruh blok dan "Laba (Rugi) Kotor" = Jumlah Penjualan + Harga
 * Pokok Penjualan. Penjumlahan kedua total itu dilakukan di blade.
 *
 * Belum ada sumber (dirender '-' di UI): baris Ekspor (template LM-34 tidak punya
 * seksi ekspor), Perubahan Nilai Wajar Aset Biologis, Order Produksi, blok Biaya
 * Usaha, dan seterusnya.
 */
class Lm27aController extends Controller
{
    use AuthorizesReportRequests;

    /**
     * Kunci baris LM-34 yang menyusun tiap kolom budidaya pada baris "Lokal".
     *
     * TBS masuk Kelapa Sawit — di LM-34 TBS berdiri di luar "Jumlah A", tetapi
     * "Jumlah Lokal" tetap menjumlahnya. Dibuktikan sel G10 LM-27A.xlsx (Juni 2026):
     * 1.097.058.874.647 = 14.854.548.687 (Jumlah TBS) + 1.082.204.325.960 (Jumlah A).
     */
    private const LOKAL_BUDIDAYA = [
        'ks' => ['jml_tbs', 'jml_a'],
        'kr' => ['jml_c'],
    ];

    /**
     * Kolom budidaya → baris LM13 yang dipakai LM-27A: komoditi + urutan baris
     * "Jumlah Beban Penyusutan" dan "Jumlah Biaya Produksi". Urutan berbeda antar
     * komoditi karena templatnya memang beda panjang (KR: 41 & 48 — lihat
     * `seed_lm_template_row.sql`); diuji di Lm27aApiTest.
     *
     * ⚠️ 'kr' SENGAJA TIDAK diikutkan. Di halaman LM Eksploitasi, baris pengolahan
     * komoditi KR (Beban Langsung/Overhead/Penyusutan Pengolahan) diisi dari Alokasi
     * Biaya Olah yang sumbernya PKS/sawit (`AlokasiBiayaOlahController::jlhPerKebunLm13()`
     * tidak menerima parameter komoditi), sehingga nilainya sama persis dengan kolom
     * Sawit dan kedua subtotal Karet ikut menggelembung. Contoh Juli 2026 di server:
     * KR penyusutan pengolahan 38.875.653.655 = angka Sawit, padahal penyusutan kebun
     * karetnya hanya 2.211.421.100. Isi kolom Karet di sini setelah sumber itu
     * dipisahkan per komoditi.
     */
    private const LM13_BUDIDAYA = [
        'ks' => ['komoditi' => 'KS', 'penyusutan' => 61, 'biaya_produksi' => 68],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authenticateReportRequest($request);

        $periods = DB::table('penjualan_produk')
            ->select('year', DB::raw('period AS month'))->distinct()
            ->orderByDesc('year')->orderByDesc('period')
            ->get()->map(fn ($p) => ['year' => (int) $p->year, 'month' => (int) $p->month])->values()->all();

        if ($periods === []) {
            return response()->json([
                'periods' => [], 'year' => null, 'month' => null, 'values' => [],
            ]);
        }

        // Tanpa parameter → adopsi periode terbaru. Dengan parameter → persis
        // periode itu; bulan tanpa data → nilai 0 (semua sel '-'), TIDAK diam-diam
        // jatuh ke bulan lain.
        $year = $request->query('year');
        $month = $request->query('month');
        if ($year === null || $month === null) {
            $year = $periods[0]['year'];
            $month = $periods[0]['month'];
        }
        $year = (int) $year;
        $month = (int) $month;

        $lm13 = $this->lm13Values($year, $month);

        return response()->json([
            'periods' => $periods,
            'year' => $year,
            'month' => $month,
            'values' => [
                'lokal' => $this->lokal($year, $month),
                // Tiga baris biaya dikirim NEGATIF (aturan user, tampil dalam
                // kurung) supaya Harga Pokok Penjualan cukup menjumlahkan blok
                // ini apa adanya. "Persediaan Akhir" tetap positif karena memang
                // mengurangi harga pokok.
                'persediaan_awal' => $this->negatif($this->persediaanAwal()),
                'biaya_produksi' => $this->negatif($lm13['biaya_produksi']),
                'penyusutan' => $this->negatif($lm13['penyusutan']),
                'persediaan_akhir' => $this->persediaanAkhir($year, $month),
                // Kolom budidaya yang komponen Harga Pokok Penjualan-nya lengkap;
                // hanya kolom ini yang dihitung HPP & Laba (Rugi) Kotor-nya.
                'hpp_kolom' => $lm13['siap'],
            ],
        ]);
    }

    /**
     * Balik tanda tiap kolom budidaya (0 tetap 0, bukan -0).
     *
     * @param  array<string, float>  $nilai
     * @return array<string, float>
     */
    private function negatif(array $nilai): array
    {
        return array_map(static fn (float $v) => $v == 0.0 ? 0.0 : -$v, $nilai);
    }

    /**
     * Baris "Persediaan Akhir" = baris "Jumlah Persediaan" kolom NILAI PERSEDIAAN
     * AKHIR (Rp) pada /laba-rugi/persediaan — nilai ZSTOCK periode itu ditambah
     * baris "Penyesuaian atas nilai persediaan akhir".
     *
     * Kolom Karet dikosongkan: baris penyesuaian di halaman Persediaan tidak
     * terpisah per komoditi, jadi mengisi kedua kolom akan menghitung penyesuaian
     * itu dua kali pada kolom Jumlah.
     *
     * @return array<string, float>
     */
    private function persediaanAkhir(int $year, int $month): array
    {
        return [
            'ks' => app(PersediaanController::class)->nilaiAkhirJumlah($year, $month, 'sawit'),
            'kr' => 0.0,
        ];
    }

    /**
     * Baris LM-27A yang bersumber dari halaman LM Eksploitasi (/kebun), kolom
     * "s.d Bulan {n} → Real s.d" (`sd_real_thn_ini`), blok "Kebun Sendiri +
     * Pihak III" (`blok = OLAH_JUAL`), unit "Semua Unit":
     *
     *   "Penyusutan"    = baris "Jumlah Beban Penyusutan" apa adanya.
     *   "Biaya Produksi" = baris "Jumlah Biaya Produksi" DIKURANGI baris
     *                      "Jumlah Beban Penyusutan" (aturan user) — di LM-27A
     *                      penyusutan berdiri sebagai baris tersendiri, jadi harus
     *                      dikeluarkan dari biaya produksi agar tidak dobel.
     *                      Juli 2026: 1.264.270.936.505 − 80.825.741.670
     *                               = 1.183.445.194.835.
     *
     * Sengaja lewat ReportController::lm13Rows() — BUKAN query langsung ke
     * `report_lm13` — karena baris pengolahan & pembelian TBS Pihak III baru diisi
     * di lapisan presentasi, sehingga subtotal di tabel lebih kecil daripada yang
     * tampil di halaman.
     *
     * Hanya kolom Kelapa Sawit yang diisi; alasan kolom Karet dikosongkan ada di
     * LM13_BUDIDAYA.
     *
     * Kunci `siap` = kolom yang benar-benar mendapat angka. Periode tanpa batch
     * (atau batch yang belum digenerate) TIDAK masuk daftar, supaya Harga Pokok
     * Penjualan tidak dihitung dari blok yang cuma berisi persediaan.
     *
     * @return array{biaya_produksi: array<string, float>, penyusutan: array<string, float>, siap: list<string>}
     */
    private function lm13Values(int $year, int $month): array
    {
        $out = [
            'biaya_produksi' => ['ks' => 0.0, 'kr' => 0.0],
            'penyusutan' => ['ks' => 0.0, 'kr' => 0.0],
            'siap' => [],
        ];

        // Batch unik per (year, month) — sama seperti pemilihan batch di halaman
        // LM Eksploitasi. Periode tanpa batch → 0 (tampil '-').
        $batch = Batch::query()->where('year', $year)->where('month', $month)->first();
        if ($batch === null) {
            return $out;
        }

        // Viewer hanya boleh melihat batch final/locked. Bila belum, baris ini
        // dibiarkan 0 — bukan menggagalkan seluruh halaman LM-27A (blok Penjualan
        // tidak bergantung batch).
        $user = auth()->user();
        if ($user && $user->hasRole(Role::VIEWER) && ! in_array($batch->status, ['final', 'locked'], true)) {
            return $out;
        }

        $report = app(ReportController::class);
        foreach (self::LM13_BUDIDAYA as $col => $ref) {
            $rows = $report->lm13Rows($batch, $ref['komoditi']);
            if ($rows === null) {
                continue;
            }
            $nilai = function (int $urutan) use ($rows): float {
                $row = $rows->first(
                    fn ($r) => (int) ($r->urutan ?? 0) === $urutan && ($r->blok ?? null) === 'OLAH_JUAL'
                );

                return $row !== null ? (float) $row->sd_real_thn_ini : 0.0;
            };

            $penyusutan = $nilai($ref['penyusutan']);
            $out['penyusutan'][$col] = $penyusutan;
            $out['biaya_produksi'][$col] = $nilai($ref['biaya_produksi']) - $penyusutan;
            $out['siap'][] = $col;
        }

        return $out;
    }

    /**
     * Baris "Persediaan Awal" per kolom budidaya = baris "Jumlah Persediaan"
     * kolom PERSEDIAAN AWAL TAHUN (Rp) pada /laba-rugi/persediaan (tab KELAPA
     * SAWIT & KARET) — Σ nilai semua unit + baris penyesuaian.
     *
     * Tidak bergantung filter periode: saldo awal tahun sama untuk semua bulan
     * (LM-27A memang kumulatif 1 Januari s/d bulan filter), sama seperti kolom
     * itu di halaman persediaan.
     *
     * @return array<string, float>
     */
    private function persediaanAwal(): array
    {
        return [
            'ks' => PersediaanAwalTahun::jumlahRp('sawit'),
            'kr' => PersediaanAwalTahun::jumlahRp('karet'),
        ];
    }

    /**
     * Nilai penjualan lokal s.d bulan terpilih per kolom budidaya.
     * Nilai GL tersimpan kredit (negatif) → dibalik tanda agar positif seperti
     * Excel; nota debit/koreksi tetap mengurangi (bukan nilai mutlak).
     *
     * @return array<string, float>
     */
    private function lokal(int $year, int $month): array
    {
        $out = [];
        foreach (self::LOKAL_BUDIDAYA as $col => $lm34Keys) {
            $keys = [];
            foreach ($lm34Keys as $k) {
                $keys = array_merge($keys, Lm34Controller::detailKeysOf($k));
            }
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                $out[$col] = 0.0;

                continue;
            }

            $q = DB::table('penjualan_produk')
                ->where('year', $year)->where('period', '<=', $month);
            Lm34Controller::applySourceFilter($q, $keys);
            $sum = (float) $q->sum('amount');
            $out[$col] = $sum == 0.0 ? 0.0 : -$sum;
        }

        return $out;
    }
}
