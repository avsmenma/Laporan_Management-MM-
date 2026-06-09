<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\RefUnit;
use App\Models\Batch;
use App\Models\LmTemplateRow;
use App\Domain\Report\Lm16Service;

echo "=== DEBUG LM16 ===\n\n";

// 1. Check unit
$unit = RefUnit::where('code', '5F01')->first();
echo "1. Unit 5F01:\n";
echo "   Nama: {$unit->name}\n";
echo "   Type: {$unit->type}\n";
echo "   Komoditi: {$unit->komoditi}\n";
echo "   Olah Status: {$unit->olah_status}\n\n";

// 2. Check templates
echo "2. LM16 Templates:\n";
$templates = LmTemplateRow::where('report_type', 'LM16')
    ->where(function ($query) use ($unit) {
        $query->where('komoditi', $unit->komoditi)
            ->orWhereNull('komoditi');
    })
    ->orderBy('urutan')
    ->get();
echo "   Total: {$templates->count()} templates\n";
echo "   First 10 (with kode):\n";
foreach ($templates->take(10) as $t) {
    echo "     {$t->urutan}: {$t->uraian} (kode: {$t->kode}, type: {$t->row_type})\n";
}
echo "\n";

// 3. Check pks_produksi data
echo "3. PKS Produksi Data:\n";
$produksi = DB::table('pks_produksi')
    ->where('plant_code', '5F01')
    ->where('period', 5)
    ->get();
echo "   Total rows: {$produksi->count()}\n";
foreach ($produksi as $p) {
    echo "     {$p->uraian}: BI={$p->nilai_bi}, SD={$p->nilai_sd}\n";
}
echo "\n";

// 4. Check pks_biaya data
echo "4. PKS Biaya Data:\n";
$biaya = DB::table('pks_biaya')
    ->where('plant_code', '5F01')
    ->where('period', 5)
    ->get();
echo "   Total rows: {$biaya->count()}\n";
foreach ($biaya->take(5) as $b) {
    echo "     CC={$b->cost_center}, GL={$b->cost_element}, Nilai={$b->nilai}\n";
}
echo "\n";

// 5. Try to generate
echo "5. Trying to generate LM16...\n";
$batch = Batch::find(1);
$service = app(Lm16Service::class);

try {
    $rows = $service->generate($batch, $unit);
    echo "   ✓ Generated {$rows->count()} rows\n\n";

    // Show sample results
    echo "6. Sample Results (first 10 rows):\n";
    foreach ($rows->take(10) as $row) {
        $tpl = LmTemplateRow::find($row['template_id']);
        echo sprintf("   %2d. %-40s | BI: %10s | SD: %10s\n",
            $tpl->urutan,
            substr($tpl->uraian, 0, 40),
            number_format($row['bi_jumlah'], 0),
            number_format($row['sd_jumlah'], 0)
        );
    }
} catch (\Exception $e) {
    echo "   ✗ Error: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
    echo "   Trace:\n";
    foreach (explode("\n", $e->getTraceAsString()) as $line) {
        echo "     {$line}\n";
    }
}
