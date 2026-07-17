<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Halaman LM Beban Usaha (workbook "LM BEBAN USAHA.xlsb") — satu halaman per
 * sheet visible: LM PENJUALAN, LM ADMINISTRASI, LM BIAYA OPERASIANAL LAINNYA,
 * LM PENDAPATAN LAINNYA. Struktur kolom & baris disalin persis dari sheet;
 * sumber data belum ada sehingga seluruh nilai dirender '-' (UI dulu).
 */
class BebanUsahaController extends Controller
{
    public function bebanPenjualan(): View
    {
        return view('laba-rugi.beban-usaha', ['cfg' => [
            'title' => 'BEBAN PENJUALAN',
            'subtitle' => 'Disajikan dalam Rupiah',
            'preset' => 'penjualan',
            'tabs' => [
                ['key' => 'all', 'label' => 'SEMUA KOMODITI', 'rows' => self::rowsBebanPenjualan()],
            ],
        ]]);
    }

    public function bebanAdministrasi(): View
    {
        $rows = self::rowsBebanAdministrasi();

        return view('laba-rugi.beban-usaha', ['cfg' => [
            'title' => 'BEBAN ADMINISTRASI',
            'subtitle' => 'Disajikan dalam Rupiah',
            'preset' => 'admin',
            'dataUrl' => '/report-data/laba-rugi/beban-usaha?page=admin',
            'tabs' => [
                ['key' => 'summary', 'label' => 'SUMMARY', 'rows' => $rows],
                ['key' => 'ks', 'label' => 'ADMI KS', 'rows' => $rows],
                ['key' => 'kr', 'label' => 'ADMI KR', 'rows' => $rows],
            ],
        ]]);
    }

    public function bebanOperasionalLainnya(): View
    {
        $ksKr = self::rowsBolKsKr();

        return view('laba-rugi.beban-usaha', ['cfg' => [
            'title' => 'RINCIAN BEBAN LAIN-LAIN',
            'subtitle' => 'Disajikan dalam Rupiah',
            'preset' => 'lain',
            'kolomKedua' => 'Kebun dan Pabrik',
            'dataUrl' => '/report-data/laba-rugi/beban-usaha?page=bol',
            'tabs' => [
                ['key' => 'summary', 'label' => 'SUMMARY', 'rows' => self::rowsBolSummary()],
                ['key' => 'ks', 'label' => 'KELAPA SAWIT', 'rows' => $ksKr],
                ['key' => 'kr', 'label' => 'KARET', 'rows' => $ksKr],
            ],
        ]]);
    }

    public function pendapatanLainnya(): View
    {
        $ksKr = self::rowsPendapatanKsKr();

        return view('laba-rugi.beban-usaha', ['cfg' => [
            'title' => 'RINCIAN PENDAPATAN LAIN-LAIN',
            'subtitle' => 'Disajikan dalam Rupiah',
            'preset' => 'lain',
            'kolomKedua' => 'Kebun & Pabrik',
            'tabs' => [
                ['key' => 'summary', 'label' => 'SUMMARY', 'rows' => self::rowsPendapatanSummary()],
                ['key' => 'ks', 'label' => 'KELAPA SAWIT', 'rows' => $ksKr],
                ['key' => 'kr', 'label' => 'KARET', 'rows' => $ksKr],
            ],
        ]]);
    }

    /**
     * Baris sheet LM PENJUALAN: seksi Karet (rekg 860.0) + Kelapa Sawit (860.1)
     * + Jumlah Seluruh. 'k' = kolom Rekg. persis tampilan Excel (dua digit,
     * mis. '00'), 't' = header|subtotal|total (default detail).
     *
     * @return array<int, array<string, string>>
     */
    public static function rowsBebanPenjualan(): array
    {
        $detail = fn (string $k, string $u): array => ['k' => $k, 'u' => $u];

        return [
            ['k' => '860.0', 'u' => 'Karet', 't' => 'header'],
            $detail('00', 'Gaji dan Bisos Karyawan Pelaksana'),
            $detail('01', 'Pengangkutan ke Pelabuhan'),
            $detail('02', 'Sewa Gudang'),
            $detail('04', 'Pelabuhan / EMKL'),
            $detail('05', 'Analisa Mutu'),
            $detail('06', 'Klaim Mutu'),
            $detail('08', 'Bank'),
            $detail('23', 'Imbalan Jasa Pemasaran'),
            ['u' => 'Jumlah', 't' => 'subtotal'],
            ['k' => '860.1', 'u' => 'Kelapa Sawit', 't' => 'header'],
            $detail('00', 'Gaji dan Bisos Karyawan Pelaksana'),
            $detail('01', 'Pengiriman dan Pengangkutan'),
            $detail('02', 'Sewa Gudang'),
            $detail('03', 'Instalasi Pompa'),
            $detail('04', 'Pelabuhan / EMKL'),
            $detail('05', 'Analisa Mutu'),
            $detail('06', 'Klaim Mutu'),
            $detail('08', 'Bank'),
            $detail('10', 'Komisi'),
            $detail('11', 'Penyusutan Aktiva Tetap'),
            $detail('14', 'Bahan dan Perlengkapan'),
            $detail('19', 'Lain - Lain'),
            $detail('21', 'Biaya Operasi Pelabuhan'),
            $detail('22', 'Pemeliharaan Instalasi Pelabuhan'),
            $detail('23', 'Imbalan Jasa Pemasaran'),
            ['u' => 'Jumlah', 't' => 'subtotal'],
            ['u' => 'Jumlah Seluruh', 't' => 'total'],
        ];
    }

    /**
     * Baris sheet LM ADMINISTRASI (blok Summary/ADMI KS/ADMI KR memakai daftar sama).
     *
     * @return array<int, array<string, string>>
     */
    public static function rowsBebanAdministrasi(): array
    {
        $details = [
            'Beban Gaji, Tunjangan & Beban Sosial Karyawan',
            'Beban Pengangkutan, Perjalanan dan Penginapan',
            'Beban Pemeliharaan Bangunan, Mesin, Jalan dan Instalasi',
            'Beban Pemeliharaan Perlengkapan Kantor',
            'Beban Pajak dan Retribusi',
            'Beban Premi Asuransi',
            'Beban Keamanan',
            'Beban Air dan Listrik',
            'Beban Mutu (ISO 9000)',
            'Beban Pengendalian Lingkungan (ISO 14000)',
            'Beban Sistem Manajemen Kesehatan & Keselamatan Kerja',
            'Beban Penelitian dan Percobaan',
            'Beban Sumbangan, Iuran dan CSR',
            'Beban Pendidikan dan Pengembangan SDM',
            'Beban Konsultan',
            'Beban Audit',
            'Beban Komisaris',
            'Beban Distrik/SBU',
            'Beban Kantor Perwakilan/Penghubung',
            'Beban Bonus',
            'Beban Tantiem',
            'Beban Estimasian Imbalan Kerja Jangka Panjang & Pasca Kerja',
            'Beban Instansi Terkait',
            'Beban Telekomunikasi & Ekspedisi',
            'Beban Rapat',
            'Beban Konsumsi',
            'Alat Tulis Kantor (ATK)',
            'Beban Media (Subscription, Iklan, Liputan, Gathering)',
            'Lain-Lain',
        ];

        $rows = array_map(fn (string $u): array => ['u' => $u], $details);
        $rows[] = ['u' => 'Jumlah', 't' => 'subtotal'];
        $rows[] = ['u' => 'Beban Depresiasi dan Amortisasi'];
        $rows[] = ['u' => 'Jumlah beban administrasi Include Penyusutan', 't' => 'total'];

        return $rows;
    }

    /**
     * Susun daftar rincian + seksi KSO + Jumlah/Total (pola bersama BOL & Pendapatan).
     *
     * @param  array<int, string>  $details
     * @param  array<int, string>  $kso
     * @return array<int, array<string, string>>
     */
    public static function withKso(array $details, string $jumlahKso, array $kso): array
    {
        $rows = array_map(fn (string $u): array => ['u' => $u], $details);
        $rows[] = ['u' => 'Jumlah', 't' => 'subtotal'];
        foreach ($kso as $u) {
            $rows[] = ['u' => $u];
        }
        $rows[] = ['u' => $jumlahKso, 't' => 'subtotal'];
        $rows[] = ['u' => 'Total', 't' => 'total'];

        return $rows;
    }

    /** @return array<int, array<string, string>> */
    public static function rowsBolSummary(): array
    {
        return self::withKso([
            'Biaya Operasional PT. Agrinas Palma Nusantara',
            'Kantor Akuntan Publik',
            'Piutang Tidak Tertagih',
            'Biaya Bibit (STO)',
            'Beban Rugi Penurunan Nilai Aset Tetap',
            'Denda Lainnya (diluar pajak)',
            'Biaya Operasional Pabrik Kebun / Diluar Harga Pokok',
            'DUP, RUPS, RKAP, Tutup Buku Bersama',
            'Selisih Kas Opname/Gudang',
            'Kerugian Selisih Kurs',
            'Denda Pajak/ Dampak Perhitungan TER PPh 21',
            'Koordinasi Pemda/Instansi Terkait/Iuran, Sumbangan, dan Rapat',
            'Rapat Laporan Tahunan',
            'Coorporate Social Responsibility ( CSR )',
            'Akomodasi Rekonsiliasi Hutang Piutang',
            'Biaya Perizinan',
            'Biaya RSPO',
            'Lain - Lain',
            'Biaya Provisi, Fee dan Admin Pinjaman',
            'Penyusutan di Luar Harga Pokok',
            'Jasa Telkom ERP-SAP',
            'Biaya Pakaian Dinas',
            'Beban PSAK 73',
            'Penghapusan Aktiva Tanaman dan Non Tanaman',
            'Biaya Penelitian dan Pengembangan/Lainnya/Cadangan Top UP BPJS',
            'Biaya Pengurusan Kebun Plasma',
        ], 'Jumlah Biaya KSO', [
            'Biaya Admin/ Bunga Inves KSO PT NMA',
            'Biaya Operasional Batubara Tabara (PT.BSI)',
            'Biaya Operasional Batubara Danau Salak (PT.MAS)',
            'Biaya KSO Tambarangan - PT Borneo Makmur Sejati',
            'Biaya KSO PKS Kembayan - PT Maulana Karya Persada',
            'Biaya KSO Kumai - CV. Murutuwu Putra',
            'Biaya KSO Kembayan - CV Noyan Persada Jaya',
            'Biaya KSO PKS Pamukan - PT XXX',
            'Biaya KSO PKS Parindu - PT XXX',
            'Biaya KSO Sintang PT - xxx',
            'Biaya KSO Kebun Pamukan - PT Sumber Daya Energi (SDE)',
        ]);
    }

    /** @return array<int, array<string, string>> */
    public static function rowsBolKsKr(): array
    {
        return self::withKso([
            'Biaya Operasional PT. Agrinas Palma Nusantara',
            'Kantor Akuntan Publik',
            'Piutang Tidak Tertagih',
            'Biaya Bibit (STO)',
            'Rugi Penjualan Bahan Baku & Perlengkapan',
            'Denda Lainnya (diluar pajak)',
            'Biaya Operasional Pabrik Kebun / Diluar Harga Pokok',
            'DUP, RUPS, RKAP',
            'Selisih Kas Opname/Gudang',
            'Kerugian Selisih Kurs',
            'Denda Pajak',
            'Koordinasi Pemda/Instansi Terkait',
            'Rapat Laporan Tahunan',
            'Coorporate Social Responsibility ( CSR )',
            'Akomodasi Rekonsiliasi Hutang Piutang',
            'Biaya Perizinan',
            'Biaya RSPO',
            'Lain - Lain',
            'Biaya Provisi, Fee dan Admin Pinjaman',
            'Penyusutan di Luar Harga Pokok',
            'Jasa Telkom ERP-SAP',
            'Biaya Pakaian Dinas',
            'Beban PSAK 73',
            'Penghapusan Aktiva Tanaman dan Non Tanaman',
            'Biaya Penelitian dan Pengembangan/Lainnya',
            'Biaya Pengurusan Kebun Plasma',
        ], 'Jumlah Biaya KSO', [
            'Biaya Admin/ Bunga Inves KSO PT NMA',
            'Biaya Operasional Batubara Tabara (PT.BSI)',
            'Biaya Operasional Batubara Danau Salak (PT.MAS)',
            'Biaya KSO Tambarangan - PT Borneo Makmur Sejati',
            'Biaya KSO PKS Kembayan - PT Maulana Karya Persada',
            'Biaya KSO Kumai - CV. Murutuwu Putra',
            'Biaya KSO Kembayan - CV Noyan Persada Jaya',
            'Biaya KSO PKS Pamukan - PT XXX',
            'Biaya KSO PKS Parindu - PT XXX',
            'Biaya KSO Sintang PT - Sumber Baru Mitra Abadi',
            'Biaya KSO Kebun Pamukan - PT Sumber Daya Energi (SDE)',
        ]);
    }

    /** @return array<int, array<string, string>> */
    public static function rowsPendapatanSummary(): array
    {
        return self::withKso([
            'Penjualan Bahan Baku dan Pelengkap',
            'Penjualan Aktiva Tetap Non Produktif',
            'Penjualan Dokumen Tender',
            'Penjualan Bibit',
            'Penjualan Limbah MS dan Kayu Karet',
            'Selisih Stock Opname Barang Gudang',
            'Selisih Kas Opname',
            'Keuntungan Pengolahan TBS Pihak III',
            'Denda atas Faktur',
            'Denda atas Keterlambatan Pekerjaan/ Denda mutu',
            'Pendapatan Selisih Kurs',
            'Pendapatan Penjualan Palm Oil Mill Effluent (POME)',
            'Laba atas Entitas Asosiasi',
            'Pendapatan Penjualan Cangkang Inti Sawit',
            'Selisih Perubahan Estimasi Aset',
            'Pendapatan Bunga Pinjaman (KMN)',
            'Lain - Lain',
            'Bunga Jasa Giro',
            'Pendapatan Pengurusan Plasma / Cost Underrun',
        ], 'Jumlah Pendapatan KSO', [
            'Pendapatan KSO PT Nabati Mas Asri',
            'Pendapatan KSO Batubara Danau Salak (PT.MAS)',
            'Pendapatan KSO PT Maulana Karya Persada',
            'Pendapatan KSO PKS Parindu - PT XXX',
            'Pendapatan KSO Kumai - CV. Murutuwu Putra',
            'Pendapatan KSO Kembayan - CV Noyan Persada Jaya',
            'Pendapatan KSO PKS Pamukan - PT Srirejeki Putra Mandiri',
            'Pendapatan KSO Danau Salak - PT Sumber Baru Mitra Abadi',
            'Pendapatan KSO Sintang PT - xxx',
            'Pendapatan KSO Kebun Pamukan - PT Sumber Daya Energi (SDE)',
        ]);
    }

    /** @return array<int, array<string, string>> */
    public static function rowsPendapatanKsKr(): array
    {
        return self::withKso([
            'Penjualan Bahan Baku dan Pelengkap',
            'Penjualan Aktiva Tetap Non Produktif',
            'Penjualan Dokumen Tender',
            'Penjualan Bibit',
            'Penjualan Limbah MS dan Kayu Karet',
            'Selisih Stock Opname Barang Gudang',
            'Selisih Kas Opname',
            'Keuntungan Pengolahan TBS Pihak III',
            'Denda atas Faktur',
            'Denda atas Keterlambatan Pekerjaan',
            'Pendapatan Selisih Kurs',
            'Premi Mutu Alb',
            'Laba atas Entitas Asosiasi',
            'Pendapatan Penjualan Cangkang Inti Sawit',
            'Selisih Perubahan Estimasi Aset',
            'Pendapatan Bunga Pinjaman (KMN)',
            'Lain - Lain',
            'Bunga Jasa Giro',
            'Pendapatan Pengurusan Plasma / Cost Underrun',
        ], 'Jumlah Pendapatan KSO', [
            'Pendapatan KSO PT Nabati Mas Asri',
            'Pendapatan KSO Batubara Danau Salak (PT.MAS)',
            'Pendapatan KSO PT Maulana Karya Persada',
            'Pendapatan KSO PKS Parindu - PT XXX',
            'Pendapatan KSO Kumai - CV. Murutuwu Putra',
            'Pendapatan KSO Kembayan - CV Noyan Persada Jaya',
            'Pendapatan KSO PKS Pamukan - PT Srirejeki Putra Mandiri',
            'Pendapatan KSO Danau Salak - PT Sumber Baru Mitra Abadi',
            'Pendapatan KSO Sintang PT - Sumber Baru Mitra Abadi',
            'Pendapatan KSO Kebun Pamukan - PT Sumber Daya Energi (SDE)',
        ]);
    }
}
