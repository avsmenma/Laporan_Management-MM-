<?php

namespace Tests\Feature;

use App\Domain\Report\Lm13Service;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportLm13SaldoAwalJumlahTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_baris_jumlah_saldo_awal_disisipkan_setelah_pihak_iii(): void
    {
        $this->seed();
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $unit = RefUnit::query()->where('code', '5E11')->firstOrFail();

        DB::table('alokasi_areal')->insert([
            'year' => 2026, 'kebun_code' => $unit->code,
            'real_thn_lalu' => 8, 'real_thn_ini' => 10, 'rko' => 9, 'rkap' => 11,
        ]);

        // Saldo Awal TBS (urutan 2 = Kebun Inti) bersumber dari produk "Stok Awal TBS".
        // OLAH (pabrik) = 120, JUAL (tanpa pabrik) = 80 → OLAH_JUAL = 200.
        DB::table('alokasi_produksi')->insert([
            ['batch_id' => $batch->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'Stok Awal TBS', 'jumlah' => 120],
            ['batch_id' => $batch->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => null, 'produk' => 'Stok Awal TBS', 'jumlah' => 80],
            // Produk seksi F (sub-judul) — harus DIKOSONGKAN di output meski ada nilai.
            ['batch_id' => $batch->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'TBS Olah', 'jumlah' => 999],
            ['batch_id' => $batch->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'CPO', 'jumlah' => 999],
            ['batch_id' => $batch->id, 'year' => 2026, 'month' => 5, 'kebun_code' => $unit->code, 'pabrik_code' => '5F01', 'produk' => 'Kernel', 'jumlah' => 999],
        ]);

        app(Lm13Service::class)->generate($batch, $unit, 'KS');

        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $resp = $this->actingAs($user)->getJson('/report-data/lm13?batch='.$batch->id.'&unit='.$unit->code.'&komoditi=KS');
        $resp->assertOk();
        $rows = collect($resp->json('rows'));

        // Baris Jumlah turunan: urutan 4.5, subtotal, per blok.
        $jumlah = $rows->first(fn ($r) => (string) $r['urutan'] === '4.5' && $r['block'] === 'OLAH_JUAL');
        $this->assertNotNull($jumlah, 'Baris Jumlah Saldo Awal harus ada.');
        $this->assertSame('Jumlah', $jumlah['uraian']);
        $this->assertSame('subtotal', $jumlah['row_type']);
        $this->assertEqualsWithDelta(200.0, (float) $jumlah['bi_jumlah'], 0.001); // 120 + 80

        // Posisinya tepat di antara "- Pihak III" (urutan 4) dan seksi B (urutan 5),
        // pada blok yang sama (OLAH_JUAL).
        $oj = $rows->filter(fn ($r) => $r['block'] === 'OLAH_JUAL')->values();
        $idxPihak = $oj->search(fn ($r) => (int) $r['urutan'] === 4);
        $idxJumlah = $oj->search(fn ($r) => (string) $r['urutan'] === '4.5');
        $idxB = $oj->search(fn ($r) => (int) $r['urutan'] === 5);
        $this->assertTrue($idxPihak < $idxJumlah && $idxJumlah < $idxB);

        // Sub-judul seksi F (urutan 27/32/37) → row_type 'subheader' & nilai dikosongkan.
        foreach ([27, 32, 37] as $u) {
            $sub = $rows->first(fn ($r) => (int) $r['urutan'] === $u && $r['block'] === 'OLAH_JUAL');
            $this->assertNotNull($sub, "Baris sub-judul urutan $u harus ada.");
            $this->assertSame('subheader', $sub['row_type']);
            $this->assertEqualsWithDelta(0.0, (float) $sub['bi_jumlah'], 0.001); // dikosongkan walau sumber 999
        }
    }
}
