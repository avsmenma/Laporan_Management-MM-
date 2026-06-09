<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== VALIDASI LM16 PKS GUNUNG MELIAU (5F01) ===\n\n";

// 1. Struktur Data
echo "1. Struktur Data:\n";
$totalRows = DB::table('report_lm16')->where('batch_id', 1)->count();
echo "   Total rows: {$totalRows} (expected: 56)\n\n";

// 2. Sample Production Data
echo "2. Sample Production Data (first 10 rows):\n";
$prodRows = DB::table('report_lm16')
    ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
    ->where('report_lm16.batch_id', 1)
    ->whereIn('lm_template_row.urutan', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
    ->select(
        'lm_template_row.urutan',
        'lm_template_row.uraian',
        'lm_template_row.row_type',
        'lm_template_row.kode',
        'report_lm16.bi_olah',
        'report_lm16.bi_kso',
        'report_lm16.bi_jumlah',
        'report_lm16.sd_jumlah'
    )
    ->orderBy('lm_template_row.urutan')
    ->get();

foreach ($prodRows as $row) {
    printf("   %2d. %-40s [%9s]\n", $row->urutan, substr($row->uraian, 0, 40), $row->row_type);
    printf("       Olah: %12s | KSO: %12s | BI: %12s | SD: %12s\n",
        number_format($row->bi_olah, 0),
        number_format($row->bi_kso, 0),
        number_format($row->bi_jumlah, 0),
        number_format($row->sd_jumlah, 0)
    );
    if ($row->kode) {
        echo "       Kode: {$row->kode}\n";
    }
}
echo "\n";

// 3. Check Olah vs KSO Split
echo "3. Olah vs KSO Split (unit 5F01 is 'Olah' status):\n";
$tbsRow = DB::table('report_lm16')
    ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
    ->where('report_lm16.batch_id', 1)
    ->where('lm_template_row.urutan', 3) // TBS dari Lapangan
    ->select('report_lm16.bi_olah', 'report_lm16.bi_kso', 'report_lm16.bi_jumlah')
    ->first();

echo "   TBS dari Lapangan (urutan 3):\n";
echo "   - bi_olah: ".number_format($tbsRow->bi_olah, 0)." (expected: 120,000)\n";
echo "   - bi_kso:  ".number_format($tbsRow->bi_kso, 0)." (expected: 0, karena status=Olah)\n";
echo "   - bi_jumlah: ".number_format($tbsRow->bi_jumlah, 0)." (expected: 120,000)\n";
echo "   ✓ Olah/KSO split: ".($tbsRow->bi_kso == 0 ? 'BENAR' : 'SALAH')."\n\n";

// 4. Check Subtotal/Total Formulas
echo "4. Subtotal & Total (formula rows):\n";
$formulaRows = DB::table('report_lm16')
    ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
    ->where('report_lm16.batch_id', 1)
    ->whereIn('lm_template_row.row_type', ['subtotal', 'total'])
    ->select(
        'lm_template_row.urutan',
        'lm_template_row.uraian',
        'lm_template_row.row_type',
        'lm_template_row.formula',
        'report_lm16.bi_jumlah',
        'report_lm16.sd_jumlah'
    )
    ->orderBy('lm_template_row.urutan')
    ->get();

foreach ($formulaRows->take(10) as $row) {
    printf("   %2d. %-45s [%9s]\n", $row->urutan, substr($row->uraian, 0, 45), $row->row_type);
    printf("       Formula: %s\n", $row->formula ?? 'NULL');
    printf("       BI: %12s | SD: %12s\n",
        number_format($row->bi_jumlah, 0),
        number_format($row->sd_jumlah, 0)
    );
}
echo "\n";

// 5. Check Cost Data (Biaya)
echo "5. Cost Data (Biaya Pengolahan & Overhead - first 10):\n";
$costRows = DB::table('report_lm16')
    ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
    ->where('report_lm16.batch_id', 1)
    ->where('lm_template_row.urutan', '>=', 15) // Biaya mulai dari urutan ~15
    ->where('lm_template_row.urutan', '<=', 40)
    ->select(
        'lm_template_row.urutan',
        'lm_template_row.uraian',
        'lm_template_row.row_type',
        'report_lm16.bi_jumlah',
        'report_lm16.sd_jumlah'
    )
    ->orderBy('lm_template_row.urutan')
    ->get();

foreach ($costRows->take(10) as $row) {
    if ($row->bi_jumlah != 0 || $row->sd_jumlah != 0) {
        printf("   %2d. %-45s | BI: %12s | SD: %12s\n",
            $row->urutan,
            substr($row->uraian, 0, 45),
            number_format($row->bi_jumlah, 0),
            number_format($row->sd_jumlah, 0)
        );
    }
}
echo "\n";

// 6. Check Rendemen
echo "6. Rendemen (MS/TBS, IS/TBS):\n";
$rendemenRows = DB::table('report_lm16')
    ->join('lm_template_row', 'report_lm16.template_id', '=', 'lm_template_row.id')
    ->where('report_lm16.batch_id', 1)
    ->where('lm_template_row.urutan', '>=', 10)
    ->where('lm_template_row.urutan', '<=', 14)
    ->select(
        'lm_template_row.urutan',
        'lm_template_row.uraian',
        'report_lm16.bi_jumlah',
        'report_lm16.sd_jumlah'
    )
    ->orderBy('lm_template_row.urutan')
    ->get();

foreach ($rendemenRows as $row) {
    printf("   %2d. %-40s | BI: %8.2f%% | SD: %8.2f%%\n",
        $row->urutan,
        substr($row->uraian, 0, 40),
        $row->bi_jumlah,
        $row->sd_jumlah
    );
}
echo "\n";

// 7. Summary
echo "7. Summary:\n";
$summary = DB::table('report_lm16')
    ->where('batch_id', 1)
    ->selectRaw('
        COUNT(*) as total_rows,
        SUM(CASE WHEN bi_jumlah != 0 OR sd_jumlah != 0 THEN 1 ELSE 0 END) as rows_with_data,
        MAX(bi_jumlah) as max_bi,
        MAX(sd_jumlah) as max_sd
    ')
    ->first();

echo "   Total rows: {$summary->total_rows}\n";
echo "   Rows with non-zero data: {$summary->rows_with_data}\n";
echo "   Max BI value: ".number_format($summary->max_bi, 0)."\n";
echo "   Max SD value: ".number_format($summary->max_sd, 0)."\n";

echo "\n✅ Validasi selesai.\n";
