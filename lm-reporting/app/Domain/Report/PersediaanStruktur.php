<?php

namespace App\Domain\Report;

/**
 * Struktur baris halaman Persediaan (/laba-rugi/persediaan) per tab — daftar
 * produk & unit kerja persis sheet KELAPA SAWIT & KARET (TEMPLATE PERSEDIAAN.xlsx).
 *
 * Disimpan di PHP — dulu literal JavaScript `cfg()` di persediaan.blade.php —
 * karena dipakai dua tempat yang harus sepakat:
 *   1. persediaan.blade.php → merender barisnya
 *   2. Api\PersediaanController::nilaiAkhirJumlah() → menjumlahkan baris
 *      "Jumlah Persediaan" untuk baris "Persediaan Akhir" LM-27A
 * Bila daftarnya berbeda, angka LM-27A akan berbeda dari halaman Persediaan.
 *
 * Perhatikan: nilai (ZSTOCK/produksi) yang unit atau produknya TIDAK ada di sini
 * sengaja diabaikan — halaman memang hanya menampilkan baris ini, jadi
 * penjumlahannya pun harus mengabaikan hal yang sama.
 */
final class PersediaanStruktur
{
    /**
     * `key`/`keyOlah` mengacu peta produksi dari Api\PersediaanController
     * (ms/is/tbs, olah = TBS Diolah); karet belum punya sumber produksi → null.
     * `spacerDash` = keanehan template: baris pemisah antarproduk memuat strip
     * pada kolom "Persediaan per ...".
     * `units` = [kode plant, unit kerja, kode form penyesuaian bila berbeda] —
     * 5R00 dipakai 2 unit sehingga tab PENYESUAIAN memakai 5R00-1/5R00-2.
     */
    private const DATA = [
        'sawit' => [
            'judul' => 'PERSEDIAAN AKHIR HASIL PRODUKSI KELAPA SAWIT',
            'products' => [
                ['label' => '- Minyak Sawit', 'key' => 'ms'],
                ['label' => '- Inti Sawit', 'key' => 'is'],
                ['label' => '- Tandan Buah Segar', 'key' => 'tbs', 'keyOlah' => 'olah'],
            ],
            'spacerDash' => [true, false, false],
            'units' => [
                ['5R00', 'IPP Tayan', '5R00-1'],
                ['5R00', 'Tanah Merah', '5R00-2'],
                ['5F01', 'PKS Gunung Meliau'],
                ['5F04', 'PKS Rimba Belian'],
                ['5F07', 'PKS Ngabang'],
                ['5F08', 'PKS Parindu'],
                ['5F09', 'PKS Kembayan'],
                ['5F14', 'PKS Pamukan'],
                ['5F15', 'PKS Pelaihari'],
                ['5F21', 'PKS Samuntai'],
                ['5F22', 'PKS Long Pinang'],
            ],
        ],
        'karet' => [
            // Kata "KELAPA" dibuang atas permintaan user (sheet aslinya "KELAPA KARET").
            'judul' => 'PERSEDIAAN AKHIR HASIL PRODUKSI KARET',
            'products' => [
                ['label' => '- SIR 20 SWJP', 'key' => null],
                ['label' => '- SCRAP', 'key' => null],
                ['label' => '- BLANKET', 'key' => null],
                ['label' => '- LATEKS', 'key' => null],
                ['label' => '- LUMP', 'key' => null],
                ['label' => '- RSS I', 'key' => null],
                ['label' => '- RSS II', 'key' => null],
                ['label' => '- RSS III', 'key' => null],
            ],
            'spacerDash' => [true, true, true, true, true, true, true, false],
            'units' => [
                ['5F20', 'PKR Tambarangan'],
                ['5E06', 'Kebun Sintang'],
                ['5E11', 'Kebun Danau Salak'],
                ['5E19', 'Kebun Longkali'],
                ['5E12', 'Kebun Kumai'],
            ],
        ],
    ];

    /** Kunci tab yang punya tabel (tab PENYESUAIAN tidak termasuk). */
    public const TABS = ['sawit', 'karet'];

    /**
     * Struktur mentah untuk `cfg()` di persediaan.blade.php.
     *
     * @return array<string, mixed>
     */
    public static function untukView(): array
    {
        return self::DATA;
    }

    /**
     * Label produk satu tab, sudah dinormalkan (huruf besar, tanpa awalan '-')
     * supaya cocok dengan kunci peta nilai/penyesuaian.
     *
     * @return list<string>
     */
    public static function produkKeys(string $tab): array
    {
        return array_map(
            static fn (array $p) => self::norm($p['label']),
            self::DATA[$tab]['products'] ?? []
        );
    }

    /**
     * Kunci unit satu tab dalam bentuk "PLANT|UNIT KERJA" ternormalkan — sama
     * dengan kunci peta `nilai` pada Api\PersediaanController::nilaiAkhir().
     *
     * @return list<string>
     */
    public static function unitKeys(string $tab): array
    {
        return array_map(
            static fn (array $u) => self::norm($u[0]).'|'.self::norm($u[1]),
            self::DATA[$tab]['units'] ?? []
        );
    }

    /** Samakan dengan Api\PersediaanController::norm(). */
    private static function norm(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';

        return mb_strtoupper(ltrim($s, "- \t"));
    }
}
