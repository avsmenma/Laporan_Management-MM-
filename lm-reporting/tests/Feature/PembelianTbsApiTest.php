<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PembelianTbsApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedPembelian(): void
    {
        $base = [
            'posting_date' => '2026-05-10', 'year' => 2026, 'period' => 5,
            'plant_code' => '5F04', 'plant_desc' => 'PABRIK RIMBA BELIAN',
            'uom' => 'KG', 'prelim_val' => 0, 'price_diff' => 0, 'price' => 0,
            'jenis' => 'Good Receipt',
        ];

        DB::table('pembelian_tbs')->insert([
            // Vendor normal PHTG.
            $base + ['batch' => 'PHTG', 'vendor_code' => '23009761', 'vendor_name' => 'PK Ali Maksum', 'qty' => 100, 'actual_value' => 300000],
            // Record tanpa vendor (sel kosong di ekspor SAP) — tidak boleh jadi baris,
            // tapi nilainya tetap masuk subtotal PHTG & Grand Total.
            $base + ['batch' => 'PHTG', 'vendor_code' => null, 'vendor_name' => null, 'qty' => 40, 'actual_value' => 120000, 'jenis' => 'Invoice'],
            // Vendor normal PLSM (pembanding grup lain).
            $base + ['batch' => 'PLSM', 'vendor_code' => '25003374', 'vendor_name' => 'KUD Sawit Pama', 'qty' => 50, 'actual_value' => 150000],
        ]);
    }

    public function test_rincian_tanpa_baris_vendor_kosong_tetapi_subtotal_utuh(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->seedPembelian();

        $resp = $this->actingAs($user)->getJson('/report-data/produksi/pembelian?year=2026&month=5');
        $resp->assertOk();

        $rincian = $resp->json('rincian');
        $phtg = collect($rincian['groups'])->firstWhere('batch', 'PHTG');
        $this->assertNotNull($phtg);

        // Hanya vendor bernama yang tampil sebagai baris.
        $codes = array_column($phtg['rows'], 'vendor_code');
        $this->assertSame(['23009761'], $codes);

        // Subtotal PHTG di 5F04 tetap memuat nilai record tanpa vendor (100+40 / 300rb+120rb).
        $sub = $phtg['subtotal']['plants']['5F04']['bi'];
        $this->assertEqualsWithDelta(140.0, $sub['qty'], 0.001);
        $this->assertEqualsWithDelta(420000.0, $sub['value'], 0.001);

        // Grand Total = PHTG (140) + PLSM (50).
        $grand = $rincian['grand']['plants']['5F04']['bi'];
        $this->assertEqualsWithDelta(190.0, $grand['qty'], 0.001);
        $this->assertEqualsWithDelta(570000.0, $grand['value'], 0.001);
    }
}
