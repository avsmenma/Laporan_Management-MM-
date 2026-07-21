<?php

namespace App\Domain\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Membuat berkas template Excel kosong (hanya baris header) untuk tiap jenis import.
 * Nama sheet & urutan kolom dibuat persis seperti yang dibaca importer di server,
 * sehingga berkas yang diunduh pasti diterima saat diunggah kembali.
 */
class ImportTemplateService
{
    /**
     * Spesifikasi tiap template: nama sheet (harus cocok dgn yang dibaca importer)
     * + daftar header kolom sesuai urutan posisi yang dibaca importer.
     *
     * @return array<string, array{sheet: string, headers: array<int, string>, note: string}>
     */
    public static function specs(): array
    {
        return [
            'wbs' => [
                'sheet' => 'DB WBS',
                'note' => 'Realisasi biaya WBS (sheet pertama). Isi data mulai baris ke-2.',
                'headers' => [
                    'Company Code', 'Plant', 'Desc.', 'Divisi/Afdeling', 'Blok', 'Status Blok', 'Tahun Tanam',
                    'Komoditi', 'Period', 'Project', 'WBS', 'WBS Desc.', 'Fase.', 'Group Aktifitas', 'Group Desc',
                    'Aktifitas', 'Job Name', 'Hierarchy Area', 'Cost Center', 'CC Desc.', 'Partner-CCtr',
                    'Partner-CCtr Desc.', 'Cost Element', 'Cost Element Desc', 'Value', 'Currency', 'Material',
                    'Mat. Desc.', 'Qty', 'UoM', 'Object Num.', 'Object Type', 'Profit Center', 'Value Type',
                    'Reference Procedure', 'Order', 'Order Type', 'Order Category', 'Order Desc.', 'Hectare Planted',
                    'CO Business Transaction', 'Mapping COGM', 'Klasifikasi', 'Kode', 'Pekerjaan PB712-II',
                    'Pekerjaan PB7-I', 'Source', 'Keterangan',
                ],
            ],
            'ohc' => [
                'sheet' => 'DB OHC',
                'note' => 'Realisasi overhead kebun (OHC). Kolom "Period" = bulan (1-12). Isi mulai baris ke-2.',
                'headers' => [
                    'Cost Center', 'CO Object Name', 'Business Transaction', 'Document Number', 'Ref. document number',
                    'Cost Element', 'Cost element name', 'Period', 'Posting Date', 'Value in Obj. Crcy', 'Total quantity',
                    'Posted unit of meas.', 'Name', 'User Name', 'Material', 'Material Description', 'Reference procedure',
                    'Dr/Cr indicator', 'Reference Key', 'Partner Object Class', 'Object Type', 'Partner object name',
                    'Partner Object Type', 'Offsetting Account', 'Name of offsetting account', 'Name of offsetting account2',
                    'Document Header Text', 'Partner Object', 'Partner object type3', 'Partner-CCtr', 'Source Object',
                    'Source object name', 'Origin-obj. type', 'Source object type', 'Cost element descr.', 'Plant',
                    'lock', 'Kode', 'Pekerjaan PB712-II', 'Klasifikasi', 'Pekerjaan PB7-I', 'Komoditi', 'Unit Kerja',
                    'Pekerjaan PB712-III',
                ],
            ],
            'gc' => [
                'sheet' => 'DB CC GC',
                'note' => 'Realisasi general charge (GC). Kolom "Period" = bulan (1-12). Isi mulai baris ke-2.',
                'headers' => [
                    'Cost Center', 'CO Object Name', 'Business Transaction', 'Document Number', 'Ref. document number',
                    'Cost Element', 'Cost element name', 'Period', 'Posting Date', 'Value in Obj. Crcy', 'Total quantity',
                    'Posted unit of meas.', 'Name', 'User Name', 'Material', 'Material Description', 'Reference procedure',
                    'Dr/Cr indicator', 'Reference Key', 'Partner Object Class', 'Object Type', 'Partner object name',
                    'Partner Object Type', 'Offsetting Account', 'Name of offsetting account', 'Name of offsetting account2',
                    'Document Header Text', 'Partner Object', 'Partner object type3', 'Partner-CCtr', 'Source Object',
                    'Source object name', 'Origin-obj. type', 'Source object type', 'Cost element descr.', 'Plant',
                    'Afdeling', 'Kode', 'Pekerjaan PB712-II', 'Klasifikasi', 'Pekerjaan PB7-I', 'Komoditi', 'Unit Kerja', 'GC',
                ],
            ],
            'rko_bku' => [
                'sheet' => 'BKU',
                'note' => 'Anggaran RKO/RKAP — BKU. Kolom: A=Komoditi (KS/KR), D=Period (bulan), E=Aktifitas (kode), J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Desc.', 'Period', 'Aktifitas', 'Job Name', 'Cost Element',
                    'Cost Element Desc', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rko_ohc' => [
                'sheet' => 'OHC',
                'note' => 'Anggaran RKO/RKAP — OHC. Kolom: A=Komoditi (KS/KR), D=Period (bulan), E=Kode CC, J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rko_gc' => [
                'sheet' => 'GC',
                'note' => 'Anggaran RKO/RKAP — GC. Kolom: A=Komoditi (KS/KR), D=Period (bulan), E=Kode GC, J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode GC', 'GC', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rko_pks_biaya' => [
                'sheet' => 'Sheet1',
                'note' => 'Anggaran RKO — Biaya PKS. Kolom: A=Komoditi (KS), B=Plant (5F01..), D=Period (bulan 1-12), E=Kode CC (600.00/603-604.xx/400..426/490), F=CO Object Name (nama biaya sesuai baris LM16), J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rko_pks_produksi' => [
                'sheet' => 'Sheet1',
                'note' => 'Anggaran RKO — Produksi PKS. Kolom: A=Komoditi (KS), B=Plant (5F01..), D=Period (bulan 1-12), E=Kode CC (TBS Diolah / CPO / Inti), J=Nilai (Kg). Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rkap_pks_biaya' => [
                'sheet' => 'Sheet1',
                'note' => 'Anggaran RKAP — Biaya PKS. Kolom: A=Komoditi (KS), B=Plant (5F01..), D=Period (bulan 1-12), E=Kode CC (600.00/603-604.xx/400..426/490), F=CO Object Name (nama biaya sesuai baris LM16), J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rkap_pks_produksi' => [
                'sheet' => 'Sheet1',
                'note' => 'Anggaran RKAP — Produksi PKS. Kolom: A=Komoditi (KS), B=Plant (5F01..), D=Period (bulan 1-12), E=Kode CC (TBS Diolah / CPO / Inti), J=Nilai (Kg). Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rkap_bku' => [
                'sheet' => 'BKU',
                'note' => 'Anggaran RKAP — BKU. Kolom: A=Komoditi (KS/KR), D=Period (bulan), E=Aktifitas (kode), J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Desc.', 'Period', 'Aktifitas', 'Job Name', 'Cost Element',
                    'Cost Element Desc', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rkap_ohc' => [
                'sheet' => 'OHC',
                'note' => 'Anggaran RKAP — OHC. Kolom: A=Komoditi (KS/KR), D=Period (bulan), E=Kode CC, J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode CC', 'CO Object Name', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'rkap_gc' => [
                'sheet' => 'GC',
                'note' => 'Anggaran RKAP — GC. Kolom: A=Komoditi (KS/KR), D=Period (bulan), E=Kode GC, J=Nilai. Isi mulai baris ke-2.',
                'headers' => [
                    'Komoditi', 'Plant', 'Unit Kerja', 'Period', 'Kode GC', 'GC', 'Cost Element',
                    'Cost element name', 'Klasifikasi', 'Nilai', 'Fisik',
                ],
            ],
            'areal' => [
                'sheet' => 'DB',
                'note' => 'Areal statement — sheet harus bernama "DB". Isi data mulai baris ke-2.',
                'headers' => [
                    'Status', 'Status Blok/Petak', 'Plant', 'Divisi', 'Kode Blok/Petak', 'Tanggal Mulai', 'Sampai',
                    'Project Definition', 'Deskripsi Blok/Petak', 'Luas Tanam (Ha)', 'Tahun Tanam', 'Total Pokok',
                    'Luas (Ha)', 'Total Pokok Produktif', 'Kondisi Areal', 'Jenis Tanah', 'GIS ID', 'Unit Kerja', 'Komoditi',
                ],
            ],
            'produksi' => [
                'sheet' => 'ZPTPNHLPP039',
                'note' => 'Produksi PKS — sheet harus bernama "ZPTPNHLPP039". Tanggal di kolom "Tgl Posting". Isi mulai baris ke-2.',
                'headers' => [
                    'Company Code', 'Plant', 'Desc.', 'Group Pemilik', 'Kebun', 'Nama Kebun', 'Sisa Awal di PKS',
                    'TBS Diterima Hari Ini', 'TBS Diterima s/d Hari Ini', 'TBS Diterima s/d Bulan Ini', 'TBS Diolah Hari Ini',
                    'TBS Diolah s/d Hari Ini', 'TBS Diolah s/d Bulan Ini', 'Sisa Akhir di PKS', 'MS Hari Ini',
                    'MS S/D Hari Ini', 'MS S/D Bulan Ini', 'IS Hari Ini', 'IS S/D Hari Ini', 'IS S/D Bulan Ini',
                    'Realisasi Hari Ini Minyak Sawit', 'Realisasi S/D Hari Ini Minyak Sawit', 'Realisasi S/D Bulan Ini Minyak Sawit',
                    'Realisasi Hari Ini Inti Sawit', 'Realisasi S/D Hari Ini Inti Sawit', 'Realisasi S/D Bulan Ini Inti Sawit',
                    'Tgl Posting', 'Tidak Mengolah',
                ],
            ],
            'pks_biaya' => [
                'sheet' => 'Sheet1',
                'note' => 'Biaya PKS (ekspor SAP cost) — dibaca dari sheet pertama. Kolom kunci LM16: F=Cost Element (GL), H=Period (bulan 1-12), J=Value in Obj. Crcy (nilai), AN=Plant (5F01..), AO=Kode A (STAS/BT../SUP3). Isi data mulai baris ke-2.',
                'headers' => [
                    'Cost Center', 'CO Object Name', 'Business Transaction', 'Document Number', 'Ref. document number',
                    'Cost Element', 'Cost element name', 'Period', 'Posting Date', 'Value in Obj. Crcy', 'Total quantity',
                    'Posted unit of meas.', 'Name', 'User Name', 'Material', 'Material Description', 'Reference procedure',
                    'Dr/Cr indicator', 'Reference Key', 'Partner Object Class', 'Object Type', 'Partner object name',
                    'Partner Object Type', 'Offsetting Account', 'Name of offsetting account', 'Name of offsetting account2',
                    'Document Header Text', 'Partner Object', 'Partner object type3', 'Partner-CCtr', 'Source Object',
                    'Source object name', 'Origin-obj. type', 'Source object type', 'Cost element descr.', 'Lock',
                    'Kode B', 'Klasifikasi', 'PB71', 'Plant', 'Kode A', 'Klasifikasi 2', 'Bulan', 'Klasifikasi STAS', 'Unit Kerja',
                ],
            ],
            'produksi_kebun' => [
                'sheet' => 'ZESTHLE020',
                'note' => 'Produksi Kebun (jembatan timbang TBS) — sheet harus bernama "ZESTHLE020". Tanggal di "Posting Date", berat di "Weight netto". Isi mulai baris ke-2.',
                'headers' => [
                    'Plant', 'Desc Plant WB', 'Goods Recipient', 'Desc Plant Kebun', 'Afdeling', 'Supplier', 'Vendor Name',
                    'Status', 'In/Out', 'Transaction Code', 'Nomor SPBS', 'Tgl Angkut', 'Posting Date', 'Tanggal Timbang',
                    'Jam Timbang', 'Tanggal Timbang 2', 'Jam Timbang 2', 'Nomor Polisi', 'Sopir', 'Base Unit of Measure',
                    'Berat Timbang 1', 'Berat Timbang 2', 'Weight netto',
                ],
            ],
            'pembelian_tbs' => [
                'sheet' => 'Data',
                'note' => 'Pembelian TBS (ekspor SAP) — sheet harus bernama "Data". Satu file boleh berisi banyak periode (kolom Period); seluruh periode pada tahun terpilih diimpor (hapus-ganti per periode). Isi mulai baris ke-2.',
                'headers' => [
                    'Post. Date', 'Period', 'Plant', 'Plant Desc.', 'Batch', 'Vendor', 'Vendor Name', 'UOM',
                    'Qty TBS', 'Prelim Val', 'Price Diff', 'Actual Value', 'Price', 'Curr.', 'Jenis',
                    'Contract', 'Purch. Order', 'Mat. Doc', 'Year', 'Inv. Doc', 'Item Inv', 'Year Inv',
                ],
            ],
            'penjualan_produk' => [
                'sheet' => 'Data',
                'note' => 'Penjualan Produk (ekspor GL SAP) — sheet harus bernama "Data". Satu file boleh berisi banyak periode (kolom Posting Period); seluruh periode pada tahun terpilih diimpor (hapus-ganti per periode). Nilai kredit (negatif) disimpan apa adanya. Isi mulai baris ke-2.',
                'headers' => [
                    'Document Number', 'Posting Date', 'Posting Period', 'Account', 'Assignment', 'Reference',
                    'Supplier', 'Vendor Name', 'Profit Center', 'Description Prctr',
                    'Offsetting Account in General Ledger', 'Name of offsetting account', 'Document Type',
                    'Posting Key', 'Amount in Local Currency', 'Clearing Document', 'Text', 'User Name',
                    'GL Account Desc', 'Entry Date', 'Time of Entry', 'Year/Month', 'Reference Key',
                    'Purchasing Document', 'Material', 'Material Description', 'Quantity', 'Base Unit of Measure',
                    'Cost Center', 'Cost Element', 'Reference Key 3', 'Customer', 'Customer Name', 'Asset',
                ],
            ],
            'beban_admin' => [
                'sheet' => 'Sheet1',
                'note' => 'Beban Administrasi (ekspor line-item GL SAP). Kolom kunci: B=Posting Date, C=Posting Period, I=Profit Center, O=Amount in Local Currency, AC=Cost Center (akhiran KR = karet), AL=Cost Element BPC, AM=Cost Element BPC Desc (nama baris laporan). Satu file boleh berisi banyak periode; seluruh periode pada tahun terpilih diimpor (hapus-ganti per periode). Isi mulai baris ke-2.',
                'headers' => [
                    'Document Number', 'Posting Date', 'Posting Period', 'Account', 'Assignment', 'Reference',
                    'Supplier', 'Vendor Name', 'Profit Center', 'Description Prctr',
                    'Offsetting Account in General Ledger', 'Name of offsetting account', 'Document Type',
                    'Posting Key', 'Amount in Local Currency', 'Clearing Document', 'Text', 'User Name',
                    'GL Account Desc', 'Entry Date', 'Time of Entry', 'Year/Month', 'Reference Key',
                    'Purchasing Document', 'Material', 'Material Description', 'Quantity', 'Base Unit of Measure',
                    'Cost Center', 'Cost Element', 'Reference Key 3', 'Customer', 'Customer Name', 'Asset',
                    'Reversed With', 'WBS Element', 'Descripition Cctr', 'Cost Element BPC', 'Cost Element BPC Desc',
                ],
            ],
            'beban_ops' => [
                'sheet' => 'Sheet1',
                'note' => 'Beban Ops Lainnya (ekspor line-item GL SAP). Kolom kunci: B=Posting Date, C=Posting Period, I=Profit Center, O=Amount in Local Currency, AM=Kodering (A1xx), AN=Klasifikasi LM HO. Satu file boleh berisi banyak periode; seluruh periode pada tahun terpilih diimpor (hapus-ganti per periode). Isi mulai baris ke-2.',
                'headers' => [
                    'Document Number', 'Posting Date', 'Posting Period', 'Account', 'Assignment', 'Reference',
                    'Supplier', 'Vendor Name', 'Profit Center', 'Description Prctr',
                    'Offsetting Account in General Ledger', 'Name of offsetting account', 'Document Type',
                    'Posting Key', 'Amount in Local Currency', 'Clearing Document', 'Text', 'User Name',
                    'GL Account Desc', 'Entry Date', 'Time of Entry', 'Year/Month', 'Reference Key',
                    'Purchasing Document', 'Material', 'Material Description', 'Quantity', 'Base Unit of Measure',
                    'Cost Center', 'Cost Element', 'Reference Key 3', 'Customer', 'Customer Name', 'Asset',
                    'Reversed With', 'WBS Element', 'Descripition Cctr', 'Klasifikasi LM Induk', 'Kodering', 'Klasifikasi LM HO',
                ],
            ],
            'beban_penjualan' => [
                'sheet' => 'Sheet1',
                'note' => 'Beban Penjualan (ekspor line-item GL SAP, cost center R5OBPJ...). Kolom kunci: B=Posting Date, C=Posting Period, I=Profit Center, O=Amount in Local Currency, AC=Cost Center (R5OBPJ101=CPO, R5OBPJ102=PK), AL=Klasifikasi LM Induk (nama baris laporan — seluruhnya masuk seksi Kelapa Sawit). Satu file boleh berisi banyak periode; seluruh periode pada tahun terpilih diimpor (hapus-ganti per periode). Isi mulai baris ke-2.',
                'headers' => [
                    'Document Number', 'Posting Date', 'Posting Period', 'Account', 'Assignment', 'Reference',
                    'Supplier', 'Vendor Name', 'Profit Center', 'Description Prctr',
                    'Offsetting Account in General Ledger', 'Name of offsetting account', 'Document Type',
                    'Posting Key', 'Amount in Local Currency', 'Clearing Document', 'Text', 'User Name',
                    'GL Account Desc', 'Entry Date', 'Time of Entry', 'Year/Month', 'Reference Key',
                    'Purchasing Document', 'Material', 'Material Description', 'Quantity', 'Base Unit of Measure',
                    'Cost Center', 'Cost Element', 'Reference Key 3', 'Customer', 'Customer Name', 'Asset',
                    'Reversed With', 'WBS Element', 'Descripition Cctr', 'Klasifikasi LM Induk',
                ],
            ],
            'pendapatan_lain' => [
                'sheet' => 'Sheet1',
                'note' => 'Pendapatan Lainnya (ekspor line-item GL SAP). Kolom kunci: B=Posting Date, C=Posting Period, I=Profit Center, O=Amount in Local Currency (pendapatan tersimpan minus/kredit), AL=Klasifikasi (nama baris laporan tab SUMMARY). Satu file boleh berisi banyak periode; seluruh periode pada tahun terpilih diimpor (hapus-ganti per periode). Isi mulai baris ke-2.',
                'headers' => [
                    'Document Number', 'Posting Date', 'Posting Period', 'Account', 'Assignment', 'Reference',
                    'Supplier', 'Vendor Name', 'Profit Center', 'Description Prctr',
                    'Offsetting Account in General Ledger', 'Name of offsetting account', 'Document Type',
                    'Posting Key', 'Amount in Local Currency', 'Clearing Document', 'Text', 'User Name',
                    'GL Account Desc', 'Entry Date', 'Time of Entry', 'Year/Month', 'Reference Key',
                    'Purchasing Document', 'Material', 'Material Description', 'Quantity', 'Base Unit of Measure',
                    'Cost Center', 'Cost Element', 'Reference Key 3', 'Customer', 'Customer Name', 'Asset',
                    'Reversed With', 'WBS Element', 'Descripition Cctr', 'Klasifikasi',
                ],
            ],
            'investasi_wbs' => [
                'sheet' => 'DB',
                'note' => 'Biaya investasi TBM (sheet harus bernama "DB"). Baca posisional; data mulai baris pertama yang kolom A berkode kebun (5Exx).',
                'headers' => [
                    'Fase/No.Aset', 'Desc.', 'Project', 'Fase', 'Tahun Tanam', 'No.Asset', 'Aktifitas',
                    'WBS Desc.', 'Klasifikasi', 'Cost Element', 'Cost Element Desc', 'Period', 'Nilai',
                ],
            ],
            'investasi_asset' => [
                'sheet' => 'WS',
                'note' => 'Mutasi aset TBM (sheet harus bernama "WS"). Header di baris ke-2; data mulai baris yang kolom A berkode kebun (5Exx). Sebagian kolom impairment tanpa judul.',
                'headers' => [
                    'Unit Kerja', 'Kebun', 'Tahun Tanam', 'Fase', 'Klasifikasi', 'Asset', 'Description', 'Project',
                    'Luas Areal (Ha)', 'Tegakan (Pokok)', 'APC FY start', 'Acquisition', 'Retirement', 'Transfer',
                    'Current APC', 'Impairment', '(kol Q)', 'Reklas Debet (R)', '(kol S)', '(kol T)', 'Impair Awal (U)',
                    '(kol V)', '(kol W)', 'Impair Pengurangan (X)', 'Curr.bk.val Af Impairment', 'D/K',
                ],
            ],
        ];
    }

    public static function hasTemplate(string $type): bool
    {
        return array_key_exists($type, self::specs());
    }

    public static function filename(string $type): string
    {
        return 'template_'.$type.'.xlsx';
    }

    /**
     * Bangun workbook template (sheet + baris header bercetak tebal, dibekukan).
     */
    public function build(string $type): Spreadsheet
    {
        $spec = self::specs()[$type] ?? null;
        abort_if($spec === null, 404, 'Template tidak tersedia untuk jenis ini.');

        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(mb_substr($spec['sheet'], 0, 31)); // batas 31 char nama sheet Excel
        $sheet->fromArray([$spec['headers']], null, 'A1');

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($spec['headers']));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');
        foreach (range(1, count($spec['headers'])) as $i) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return $ss;
    }
}
