<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BebanUsahaDataApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingViewer(): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function glRow(string $type, int $period, float $amount, array $extra = []): array
    {
        return array_merge([
            'report_type' => $type,
            'posting_date' => sprintf('2026-%02d-15', $period),
            'year' => 2026,
            'period' => $period,
            'profit_center' => '5R00000001',
            'cost_center' => '5R00ADM05',
            'amount' => $amount,
            'class_code' => null,
            'class_desc' => null,
        ], $extra);
    }

    public function test_admin_summary_tanpa_split_ks_kr(): void
    {
        DB::table('beban_usaha_gl')->insert([
            $this->glRow('ADMIN', 5, 1000, ['class_desc' => 'Beban Gaji, Tunjangan & Beban Sosial Karyawan']),
            $this->glRow('ADMIN', 5, 100, ['class_desc' => 'Beban Gaji, Tunjangan & Beban Sosial Karyawan', 'cost_center' => '5E11AU18KR', 'profit_center' => '5E11000001']),
            $this->glRow('ADMIN', 5, 900, ['class_desc' => 'Beban Rapat', 'cost_center' => '5E02AU01KS', 'profit_center' => '5E02000001']),
            // Klasifikasi asing → baris Lain-Lain.
            $this->glRow('ADMIN', 6, 500, ['class_desc' => 'Beban Gaji, Tunjangan & Beban Sosial Karyawan']),
            $this->glRow('ADMIN', 6, 200, ['class_desc' => 'Beban Depresiasi dan Amortisasi']),
            $this->glRow('ADMIN', 6, 50, ['class_desc' => 'Klasifikasi Di Luar Peta']),
        ]);

        $resp = $this->actingAs($this->actingViewer())
            ->getJson('/report-data/laba-rugi/beban-usaha?page=admin&year=2026&month=6');
        $resp->assertOk();
        $v = $resp->json('values');

        // Indeks baris: 0=Gaji, 28=Lain-Lain, 29=Jumlah, 30=Depresiasi, 31=Total.
        $sum = $v['summary'];
        $this->assertSame(500, $sum[0]['bln']);
        $this->assertSame(1600, $sum[0]['sd']);
        $this->assertSame(1100, $sum[0]['sdbl']);
        $this->assertSame(50, $sum[28]['bln']);   // klasifikasi asing → Lain-Lain
        $this->assertSame(2550, $sum[29]['sd']);  // Jumlah = 1600+900+50
        $this->assertSame(200, $sum[30]['bln']);
        $this->assertSame(2750, $sum[31]['sd']);  // Total = Jumlah + Depresiasi

        // Tab ADMI KS/KR sengaja tanpa nilai — UI merender seluruh sel '-'.
        $this->assertArrayNotHasKey('ks', $v);
        $this->assertArrayNotHasKey('kr', $v);
    }

    public function test_bol_mapping_kodering_kso_dan_split_karet(): void
    {
        DB::table('beban_usaha_gl')->insert([
            $this->glRow('BOL', 5, 800, ['class_code' => 'A123', 'profit_center' => '5F14000001']),
            $this->glRow('BOL', 5, 100, ['class_code' => 'A123', 'profit_center' => '5R00000001']),
            $this->glRow('BOL', 5, 60, ['class_code' => 'A123', 'profit_center' => '5F20000001']),  // PKR → karet
            $this->glRow('BOL', 5, 300, ['class_code' => 'A119', 'profit_center' => '5E12000001']), // KSO Kumai → karet
            $this->glRow('BOL', 5, 40, ['class_code' => 'A119', 'profit_center' => '5E09000001']),  // KSO Kembayan Noyan
            $this->glRow('BOL', 5, 500, ['class_code' => 'A154', 'profit_center' => '5R00000001']),
            $this->glRow('BOL', 5, 25, ['class_code' => 'A999', 'profit_center' => '5R00000001']),  // kodering asing → Lain-Lain
            $this->glRow('BOL', 5, 10, ['class_code' => 'A119', 'profit_center' => '5E99000001']),  // pc KSO asing → Lain-Lain
            $this->glRow('BOL', 6, 200, ['class_code' => 'A123', 'profit_center' => '5F14000001']),
            $this->glRow('BOL', 6, 70, ['class_code' => 'A135', 'profit_center' => '5R00000001']),
        ]);

        $resp = $this->actingAs($this->actingViewer())
            ->getJson('/report-data/laba-rugi/beban-usaha?page=bol&year=2026&month=6');
        $resp->assertOk();
        $v = $resp->json('values');

        // Indeks: rincian 0-25, 26=Jumlah, KSO 27-37, 38=Jumlah KSO, 39=Total.
        $sum = $v['summary'];
        $this->assertSame(200, $sum[6]['bln']);    // Biaya Ops Pabrik Kebun
        $this->assertSame(1160, $sum[6]['sd']);
        $this->assertSame(960, $sum[6]['sdbl']);
        $this->assertSame(0, $sum[6]['ro']);       // bulan Juni: seluruhnya Kebun & Pabrik
        $this->assertSame(200, $sum[6]['kp']);
        $this->assertSame(500, $sum[4]['sd']);     // A154 → Beban Rugi Penurunan Nilai Aset
        $this->assertSame(70, $sum[13]['bln']);    // A135 → CSR (RO)
        $this->assertSame(70, $sum[13]['ro']);
        $this->assertSame(35, $sum[17]['sd']);     // Lain-Lain = A999(25) + A119 pc asing(10)
        $this->assertSame(300, $sum[32]['sd']);    // KSO Kumai (27+5)
        $this->assertSame(40, $sum[33]['sd']);     // KSO Kembayan Noyan
        $this->assertSame(1765, $sum[26]['sd']);   // Jumlah rincian
        $this->assertSame(340, $sum[38]['sd']);    // Jumlah KSO
        $this->assertSame(2105, $sum[39]['sd']);   // Total

        // Tab KARET = A119@5E12 + A123@5F20; KELAPA SAWIT = Summary − KARET.
        $this->assertSame(60, $v['kr'][6]['sd']);
        $this->assertSame(300, $v['kr'][32]['sd']);
        $this->assertSame(360, $v['kr'][39]['sd']);
        $this->assertSame(1100, $v['ks'][6]['sd']);
        $this->assertSame(0, $v['ks'][32]['sd']);
        $this->assertSame(1745, $v['ks'][39]['sd']);
    }

    public function test_tanpa_data_mengembalikan_values_null(): void
    {
        $resp = $this->actingAs($this->actingViewer())
            ->getJson('/report-data/laba-rugi/beban-usaha?page=admin');
        $resp->assertOk();
        $this->assertNull($resp->json('values'));
        $this->assertSame([], $resp->json('periods'));
    }
}
