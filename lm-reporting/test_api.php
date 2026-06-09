<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;

echo "=== TEST API ENDPOINTS ===\n\n";

// 1. Test GET /api/units
echo "1. Test GET /api/units?type=KEBUN&komoditi=KS\n";
$response = (new App\Http\Controllers\Api\MasterController)->units(
    new Illuminate\Http\Request(['type' => 'KEBUN', 'komoditi' => 'KS'])
);
$data = json_decode($response->getContent(), true);
echo "   Success: ".($data['success'] ? 'true' : 'false')."\n";
echo "   Total units: ".count($data['data'])."\n";
if (! empty($data['data'])) {
    echo "   First unit: {$data['data'][0]['code']} - {$data['data'][0]['name']}\n";
}
echo "\n";

// 2. Test GET /api/batches
echo "2. Test GET /api/batches\n";
$response = (new App\Http\Controllers\Api\MasterController)->batches();
$data = json_decode($response->getContent(), true);
echo "   Success: ".($data['success'] ? 'true' : 'false')."\n";
echo "   Total batches: ".count($data['data'])."\n";
if (! empty($data['data'])) {
    echo "   First batch: {$data['data'][0]['code']} (Period {$data['data'][0]['period']}/{$data['data'][0]['year']})\n";
}
echo "\n";

// 3. Test GET /api/report/lm14
echo "3. Test GET /api/report/lm14?batch=1&unit=5E11&komoditi=KS\n";
try {
    $request = new Illuminate\Http\Request(['batch' => '1', 'unit' => '5E11', 'komoditi' => 'KS']);
    $controller = new App\Http\Controllers\Api\ReportController;
    $response = $controller->lm14($request);
    $data = json_decode($response->getContent(), true);

    echo "   Success: ".($data['success'] ? 'true' : 'false')."\n";
    if ($data['success']) {
        echo "   Unit: {$data['meta']['unit']['code']} - {$data['meta']['unit']['name']}\n";
        echo "   Batch: {$data['meta']['batch']['code']} (Period {$data['meta']['batch']['period']})\n";
        echo "   KPI Hari: {$data['meta']['kpi_hari']['jumlah_hari']} hari total, {$data['meta']['kpi_hari']['hari_dijalani']} dijalani, {$data['meta']['kpi_hari']['sisa_hari']} sisa\n";
        echo "   Total rows: ".count($data['rows'])."\n";
        echo "   Total columns: ".count($data['columns'])."\n";
        echo "   Sample row 1: {$data['rows'][0]['uraian']} = ".number_format($data['rows'][0]['bi_jumlah'], 0)."\n";
    }
} catch (\Exception $e) {
    echo "   Error: {$e->getMessage()}\n";
}
echo "\n";

// 4. Test GET /api/report/lm13
echo "4. Test GET /api/report/lm13?batch=1&unit=5E11&komoditi=KS\n";
try {
    $request = new Illuminate\Http\Request(['batch' => '1', 'unit' => '5E11', 'komoditi' => 'KS']);
    $controller = new App\Http\Controllers\Api\ReportController;
    $response = $controller->lm13($request);
    $data = json_decode($response->getContent(), true);

    echo "   Success: ".($data['success'] ? 'true' : 'false')."\n";
    if ($data['success']) {
        echo "   Unit: {$data['meta']['unit']['code']} - {$data['meta']['unit']['name']}\n";
        echo "   Total rows: ".count($data['rows'])."\n";
        echo "   Total columns: ".count($data['columns'])."\n";
        echo "   Sample row 1: {$data['rows'][0]['uraian']} (block: {$data['rows'][0]['block']})\n";
    }
} catch (\Exception $e) {
    echo "   Error: {$e->getMessage()}\n";
}
echo "\n";

// 5. Test GET /api/report/lm16
echo "5. Test GET /api/report/lm16?batch=1&unit=5F01\n";
try {
    $request = new Illuminate\Http\Request(['batch' => '1', 'unit' => '5F01']);
    $controller = new App\Http\Controllers\Api\ReportController;
    $response = $controller->lm16($request);
    $data = json_decode($response->getContent(), true);

    echo "   Success: ".($data['success'] ? 'true' : 'false')."\n";
    if ($data['success']) {
        echo "   Unit: {$data['meta']['unit']['code']} - {$data['meta']['unit']['name']}\n";
        echo "   Total rows: ".count($data['rows'])."\n";
        echo "   Total columns: ".count($data['columns'])."\n";
        // Find row with production data
        $tbsRow = collect($data['rows'])->firstWhere('urutan', 3);
        if ($tbsRow) {
            echo "   Sample (urutan 3): {$tbsRow['uraian']}\n";
            echo "      bi_olah: ".number_format($tbsRow['bi_olah'], 0)."\n";
            echo "      bi_kso: ".number_format($tbsRow['bi_kso'], 0)."\n";
            echo "      bi_jumlah: ".number_format($tbsRow['bi_jumlah'], 0)."\n";
        }
    }
} catch (\Exception $e) {
    echo "   Error: {$e->getMessage()}\n";
}
echo "\n";

echo "✅ API Test selesai.\n";
