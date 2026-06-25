<?php

namespace Tests\Feature;

use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArealRingkasanEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role], ['description' => $role]);

        return User::factory()->create(['role_id' => $r->id]);
    }

    public function test_requires_authentication(): void
    {
        Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final']);

        $this->getJson('/report-data/areal/ringkasan?year=2026&month=5&komoditi=KS')->assertStatus(401);
    }

    public function test_pivot_per_unit_real_dan_total(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        RefUnit::query()->create(['code' => '5E01', 'name' => 'Kebun Gunung Meliau', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        RefUnit::query()->create(['code' => '5E02', 'name' => 'Kebun Gunung Mas', 'type' => 'KEBUN', 'komoditi' => 'KS']);

        $seed = function (string $unit, string $status, int $thn, float $luas) use ($batch) {
            ArealBlok::query()->create([
                'batch_id' => $batch->id, 'status_blok_petak' => $status, 'plant_code' => $unit,
                'divisi' => 'AFD01', 'tahun_tanam' => $thn, 'luas_tanam' => $luas,
                'total_pokok_produktif' => 1, 'komoditi' => 'KS',
            ]);
        };
        $seed('5E01', 'TM', 2012, 10.0);
        $seed('5E02', 'TM', 2012, 4.0);
        $seed('5E01', 'TM', 2013, 2.0);
        $seed('5E01', 'ATTP', 1983, 1.0);

        $res = $this->actingAs($this->user(Role::OPERATOR))->getJson('/report-data/areal/ringkasan?year=2026&month=5&komoditi=KS');
        $res->assertOk();
        $data = $res->json();

        // Unit list dinamis + nama dari ref_unit, urut kode.
        $this->assertSame(['5E01', '5E02'], array_column($data['units'], 'code'));
        $this->assertSame('Kebun Gunung Meliau', $data['units'][0]['name']);

        // Detail TM 2012: Real per unit + TOTAL = jumlah unit.
        $d2012 = collect($data['rows'])->first(fn ($r) => ($r['type'] ?? '') === 'detail' && ($r['status'] ?? '') === 'TM' && ($r['tahun_tanam'] ?? null) === 2012);
        $this->assertEqualsWithDelta(10.0, $d2012['cells']['5E01']['real'], 0.001);
        $this->assertEqualsWithDelta(4.0, $d2012['cells']['5E02']['real'], 0.001);
        $this->assertEqualsWithDelta(14.0, $d2012['total']['real'], 0.001);
        // RKAP belum ada sumber → 0, CAP → 0.
        $this->assertEqualsWithDelta(0.0, $d2012['total']['rkap'], 0.001);
        $this->assertEqualsWithDelta(0.0, $d2012['total']['cap'], 0.001);

        // Subtotal TM: 5E01 = 10+2 = 12, 5E02 = 4, TOTAL = 16.
        $tmSub = collect($data['rows'])->first(fn ($r) => ($r['type'] ?? '') === 'subtotal' && ($r['status'] ?? '') === 'TM');
        $this->assertEqualsWithDelta(12.0, $tmSub['cells']['5E01']['real'], 0.001);
        $this->assertEqualsWithDelta(4.0, $tmSub['cells']['5E02']['real'], 0.001);
        $this->assertEqualsWithDelta(16.0, $tmSub['total']['real'], 0.001);

        // Grand total Real = 10+4+2+1 = 17.
        $grand = collect($data['rows'])->firstWhere('type', 'grandtotal');
        $this->assertEqualsWithDelta(17.0, $grand['total']['real'], 0.001);
        $this->assertEqualsWithDelta(13.0, $grand['cells']['5E01']['real'], 0.001); // 10+2+1
        $this->assertEqualsWithDelta(4.0, $grand['cells']['5E02']['real'], 0.001);

        // Urutan status kustom: TM sebelum ATTP.
        $statusesInOrder = array_values(array_filter(array_map(fn ($r) => $r['status'] ?? null, $data['rows'])));
        $this->assertTrue(array_search('TM', $statusesInOrder) < array_search('ATTP', $statusesInOrder));
    }
}
