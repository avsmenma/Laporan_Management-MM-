<?php

namespace App\Domain\Report;

/**
 * Saldo PERSEDIAAN AWAL TAHUN hasil produksi — nilai manual dari
 * TEMPLATE PERSEDIAAN-1.xlsx (bukan tarikan data: saldo awal tahun ditetapkan
 * sekali lalu tetap sepanjang tahun berjalan).
 *
 * Disimpan di PHP — dulu berupa konstanta JavaScript di persediaan.blade.php —
 * supaya dua tempat yang memakainya membaca angka yang SAMA:
 *   1. /laba-rugi/persediaan  → kolom PERSEDIAAN AWAL TAHUN (Kg / Rp/Kg / Rp)
 *   2. /laba-rugi/lm27a       → Harga Pokok Penjualan baris "Persediaan Awal"
 * Sebelumnya keduanya harus disamakan manual dan gampang selisih.
 *
 * Bentuk data: tab → produk → unit kerja → [Kg, Rp]. Hanya baris bernilai yang
 * dicatat; sisanya 0 → tampil '-'. Kolom (Rp/Kg) tidak disimpan karena
 * diturunkan = Rp / Kg (persis formula sheet).
 *
 * PENTING: label produk & nama unit kerja WAJIB sama persis dengan `cfg()` di
 * persediaan.blade.php. Bila tidak cocok, nilainya tetap masuk hitungan
 * jumlahRp() di sini tetapi TIDAK muncul di baris rincian halaman persediaan —
 * dua halaman langsung berselisih.
 */
final class PersediaanAwalTahun
{
    /**
     * Tahun buku pemilik saldo ini. Dipakai sebagai penanda, bukan filter:
     * halaman persediaan menampilkan saldo ini untuk tahun mana pun (perilaku
     * lama dipertahankan). Saat saldo awal 2027 tersedia, ganti di sini saja.
     */
    public const TAHUN = 2026;

    /**
     * @var array<string, array{products: array<string, array<string, array{0: int|float, 1: int|float}>>, penyesuaianRp: int|float}>
     */
    private const DATA = [
        'sawit' => [
            'products' => [
                '- Minyak Sawit' => [
                    'Tanah Merah' => [712882, 12353944807],
                    'PKS Gunung Meliau' => [2030505, 23271461007],
                    'PKS Rimba Belian' => [737387, 7346163149],
                    'PKS Ngabang' => [1062012, 15599707349],
                    'PKS Parindu' => [1913260, 24541441053],
                    'PKS Kembayan' => [1118908, 18298558807],
                    'PKS Pamukan' => [82821, 1325320363],
                    'PKS Pelaihari' => [1937181, 20927291873],
                    'PKS Long Pinang' => [2829536, 49034667128],
                ],
                '- Inti Sawit' => [
                    'PKS Gunung Meliau' => [1031360, 5393442463],
                    'PKS Rimba Belian' => [697934, 4079867207],
                    'PKS Ngabang' => [320539, 2085112545],
                    'PKS Parindu' => [338739, 2521554971],
                    'PKS Kembayan' => [159524, 555201093],
                    'PKS Pamukan' => [36864, 164370461],
                    'PKS Pelaihari' => [149876, 524842609],
                    'PKS Long Pinang' => [489116, 1849985403],
                ],
            ],
            // Dikoreksi user 2026-08-10 (dulu −10.565.230.360) → Jumlah Persediaan
            // jadi 194.983.848.689, sama dengan "Persediaan Awal" Kelapa Sawit LM-27A.
            'penyesuaianRp' => 5110916401,
        ],
        'karet' => [
            'products' => [
                '- LUMP' => [
                    'Kebun Sintang' => [10080, 2055906444],
                    'Kebun Kumai' => [10897, 479567444],
                ],
            ],
            // Dikoreksi user 2026-08-11 (dulu −2.005.715.276) → Jumlah Persediaan
            // jadi 241.975.496, sama dengan "Persediaan Awal" Karet LM-27A.
            'penyesuaianRp' => -2293498392,
        ],
    ];

    /**
     * Struktur mentah untuk konstanta `awalTahun` di persediaan.blade.php.
     *
     * @return array<string, mixed>
     */
    public static function untukView(): array
    {
        return self::DATA;
    }

    /**
     * Nilai baris "Jumlah Persediaan" kolom PERSEDIAAN AWAL TAHUN (Rp) satu tab
     * = Σ nilai seluruh unit + baris "Penyesuaian atas nilai persediaan akhir".
     * Inilah angka yang dipakai baris "Persediaan Awal" LM-27A.
     */
    public static function jumlahRp(string $tab): float
    {
        $data = self::DATA[$tab] ?? null;
        if ($data === null) {
            return 0.0;
        }

        $total = (float) $data['penyesuaianRp'];
        foreach ($data['products'] as $units) {
            foreach ($units as [, $rp]) {
                $total += (float) $rp;
            }
        }

        return $total;
    }
}
