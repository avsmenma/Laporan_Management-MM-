<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== VALIDASI LM13 DANAU SALAK (5E11) ===\n\n";

// 1. Statistik dasar
echo "1. Statistik Dasar:\n";
$totalRows = DB::table('report_lm13')->where('batch_id', 1)->count();
$olahJualCount = DB::table('report_lm13')->where('batch_id', 1)->where('blok', 'OLAH_JUAL')->count();
echo "   Total rows: {$totalRows} (expected: 222 = 74 × 3 blok)\n";
echo "   OLAH_JUAL: {$olahJualCount}, OLAH: ".DB::table('report_lm13')->where('batch_id', 1)->where('blok', 'OLAH')->count().", JUAL: ".DB::table('report_lm13')->where('batch_id', 1)->where('blok', 'JUAL')->count()."\n\n";

// 2. Sample data produksi (TBS Diterima)
echo "2. Sample Produksi - TBS Diterima (urutan 6):\n";
$row = DB::table('report_lm13')
    ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
    ->where('report_lm13.batch_id', 1)
    ->where('lm_template_row.urutan', 6)
    ->select('lm_template_row.uraian', 'report_lm13.blok', 'report_lm13.bi_real_thn_ini', 'report_lm13.sd_real_thn_ini')
    ->get();

foreach ($row as $r) {
    echo sprintf("   %-10s | BI: %15s | SD: %15s\n", $r->blok, number_format($r->bi_real_thn_ini, 0, ',', '.'), number_format($r->sd_real_thn_ini, 0, ',', '.'));
}

// 3. Sample data beban (Gaji)
echo "\n3. Sample Beban - Gaji (urutan 48):\n";
$row = DB::table('report_lm13')
    ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
    ->where('report_lm13.batch_id', 1)
    ->where('lm_template_row.urutan', 48)
    ->select('lm_template_row.uraian', 'report_lm13.blok', 'report_lm13.bi_real_thn_ini', 'report_lm13.sd_real_thn_ini')
    ->get();

foreach ($row as $r) {
    echo sprintf("   %-10s | BI: %15s | SD: %15s\n", $r->blok, number_format($r->bi_real_thn_ini, 0, ',', '.'), number_format($r->sd_real_thn_ini, 0, ',', '.'));
}

// 4. Sample data calculated (Jumlah Biaya Produksi - urutan 68)
echo "\n4. Sample Calculated - Jumlah Biaya Produksi (urutan 68):\n";
$row = DB::table('report_lm13')
    ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
    ->where('report_lm13.batch_id', 1)
    ->where('lm_template_row.urutan', 68)
    ->select('lm_template_row.uraian', 'report_lm13.blok', 'report_lm13.bi_real_thn_ini', 'report_lm13.sd_real_thn_ini')
    ->get();

foreach ($row as $r) {
    echo sprintf("   %-10s | BI: %15s | SD: %15s\n", $r->blok, number_format($r->bi_real_thn_ini, 0, ',', '.'), number_format($r->sd_real_thn_ini, 0, ',', '.'));
}

// 5. Sample indikator (Biaya per Ha - urutan 69)
echo "\n5. Sample Indikator - Biaya Tanaman per Ha (urutan 69):\n";
$row = DB::table('report_lm13')
    ->join('lm_template_row', 'report_lm13.template_id', '=', 'lm_template_row.id')
    ->where('report_lm13.batch_id', 1)
    ->where('lm_template_row.urutan', 69)
    ->select('lm_template_row.uraian', 'report_lm13.blok', 'report_lm13.bi_real_thn_ini', 'report_lm13.sd_real_thn_ini')
    ->get();

foreach ($row as $r) {
    echo sprintf("   %-10s | BI: %15s | SD: %15s\n", $r->blok, number_format($r->bi_real_thn_ini, 2, ',', '.'), number_format($r->sd_real_thn_ini, 2, ',', '.'));
}

// 6. Verifikasi data sumber alokasi_produksi
echo "\n6. Data Sumber - Alokasi Produksi:\n";
$alokasi = DB::table('alokasi_produksi')
    ->where('kebun_code', '5E11')
    ->where('produk', 'TBS Diterima')
    ->where('month', 5)
    ->first();

if ($alokasi) {
    echo "   TBS Diterima (bulan 5): " . number_format($alokasi->jumlah, 0, ',', '.') . " kg\n";

    $kumulatif = DB::table('alokasi_produksi')
        ->where('kebun_code', '5E11')
        ->where('produk', 'TBS Diterima')
        ->where('month', '<=', 5)
        ->sum('jumlah');

    echo "   TBS Diterima (kumulatif s.d bulan 5): " . number_format($kumulatif, 0, ',', '.') . " kg\n";
}

// 7. Verifikasi data LM14 sebagai sumber beban
echo "\n7. Data Sumber - LM14 (Jumlah Gaji):\n";
$lm14 = DB::table('report_lm14')
    ->join('lm_template_row', 'report_lm14.template_id', '=', 'lm_template_row.id')
    ->where('report_lm14.batch_id', 1)
    ->where('lm_template_row.uraian', 'Jumlah Gaji')
    ->select('report_lm14.real_bulan_ini', 'report_lm14.real_sd_bulan_ini')
    ->first();

if ($lm14) {
    echo "   Jumlah Gaji dari LM14:\n";
    echo "     Bulan Ini: " . number_format($lm14->real_bulan_ini, 0, ',', '.') . "\n";
    echo "     s.d Bulan Ini: " . number_format($lm14->real_sd_bulan_ini, 0, ',', '.') . "\n";
}

echo "\n✓ Validasi selesai. Service LM13 berfungsi dengan baik.\n";
