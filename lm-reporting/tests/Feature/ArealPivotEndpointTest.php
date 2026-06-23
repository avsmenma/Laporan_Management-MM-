<?php

namespace Tests\Feature;

use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArealPivotEndpointTest extends TestCase
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

        $this->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01')->assertStatus(401);
    }

    public function test_viewer_blocked_on_non_final_batch(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => 'TM', 'plant_code' => '5E01',
            'divisi' => 'AFD01', 'tahun_tanam' => 2012, 'luas_tanam' => 1.0, 'total_pokok_produktif' => 1, 'komoditi' => 'KS',
        ]);

        $this->actingAs($this->user(Role::VIEWER))
            ->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01')
            ->assertStatus(403);
    }

    public function test_viewer_allowed_on_final_batch(): void
    {
        Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'final']);

        $this->actingAs($this->user(Role::VIEWER))
            ->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01')
            ->assertOk();
    }

    public function test_pivot_structure_and_subtotals(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        RefUnit::query()->create(['code' => '5E01', 'name' => 'KEBUN GUNUNG MELIAU', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        $seed = function (string $status, string $afd, int $thn, float $luas, int $pokok) use ($batch) {
            ArealBlok::query()->create([
                'batch_id' => $batch->id, 'status_blok_petak' => $status, 'plant_code' => '5E01',
                'divisi' => $afd, 'tahun_tanam' => $thn, 'luas_tanam' => $luas,
                'total_pokok_produktif' => $pokok, 'komoditi' => 'KS',
            ]);
        };
        $seed('TM', 'AFD01', 2012, 10.5, 100);
        $seed('TM', 'AFD02', 2012, 5.0, 50);
        $seed('TM', 'AFD01', 2013, 2.0, 20);
        $seed('ATTP', 'AFD01', 1983, 1.0, 10);

        $res = $this->actingAs($this->user(Role::OPERATOR))->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01');
        $res->assertOk();
        $data = $res->json();

        $this->assertSame(['AFD01', 'AFD02'], $data['afds']); // dinamis, urut

        $types = array_column($data['rows'], 'type');
        // ATTP dulu (alfabetis sebelum TM), tiap status ada subtotal, lalu grandtotal.
        $this->assertContains('grandtotal', $types);
        $grand = collect($data['rows'])->firstWhere('type', 'grandtotal');
        $this->assertEqualsWithDelta(18.5, $grand['total']['luas'], 0.001); // 1+10.5+5+2
        $this->assertSame(180, $grand['total']['pokok']);                    // 10+100+50+20

        $tmSub = collect($data['rows'])->first(fn ($r) => ($r['type'] ?? '') === 'subtotal' && ($r['status'] ?? '') === 'TM');
        $this->assertEqualsWithDelta(17.5, $tmSub['total']['luas'], 0.001);
        // urutan status: ATTP sebelum TM
        $statusesInOrder = array_values(array_filter(array_map(fn ($r) => $r['status'] ?? null, $data['rows'])));
        $this->assertTrue(array_search('ATTP', $statusesInOrder) < array_search('TM', $statusesInOrder));
    }

    public function test_totals_are_round_of_sum_not_sum_of_rounded(): void
    {
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        RefUnit::query()->create(['code' => '5E01', 'name' => 'KEBUN GUNUNG MELIAU', 'type' => 'KEBUN', 'komoditi' => 'KS']);
        $seed = function (string $status, string $afd, int $thn, float $luas, int $pokok) use ($batch) {
            ArealBlok::query()->create([
                'batch_id' => $batch->id, 'status_blok_petak' => $status, 'plant_code' => '5E01',
                'divisi' => $afd, 'tahun_tanam' => $thn, 'luas_tanam' => $luas,
                'total_pokok_produktif' => $pokok, 'komoditi' => 'KS',
            ]);
        };
        // Catatan: kolom luas_tanam adalah decimal(16,2), jadi tiap nilai tersimpan
        // sudah persis 2-desimal. Untuk menguji invarian round-of-sum kita pakai
        // nilai 2-desimal yang penjumlahannya melampaui ribuan; total HARUS sama
        // dengan round(Σ mentah) di tiap level (detail row, subtotal, grand total),
        // identik dengan pivot Excel.
        $seed('TM', 'AFD01', 2012, 0.01, 1);
        $seed('TM', 'AFD02', 2012, 0.01, 1); // row total = 0.02
        $seed('TM', 'AFD01', 2013, 0.05, 2);
        $seed('TM', 'AFD02', 2013, 0.05, 2); // row total = 0.10
        $seed('TU', 'AFD01', 2010, 0.33, 3);

        $res = $this->actingAs($this->user(Role::OPERATOR))->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01');
        $res->assertOk();
        $data = $res->json();

        // Detail row total = round(Σ_afd raw)
        $d2012 = collect($data['rows'])->first(fn ($r) => ($r['type'] ?? '') === 'detail' && ($r['status'] ?? '') === 'TM' && ($r['tahun_tanam'] ?? null) === 2012);
        $this->assertEqualsWithDelta(0.02, $d2012['total']['luas'], 0.0001);

        // Subtotal TM = round(Σ_{t,a} raw) = 0.01+0.01+0.05+0.05 = 0.12
        $tmSub = collect($data['rows'])->first(fn ($r) => ($r['type'] ?? '') === 'subtotal' && ($r['status'] ?? '') === 'TM');
        $this->assertEqualsWithDelta(0.12, $tmSub['total']['luas'], 0.0001);
        // Subtotal cell per-AFD = round(Σ_t raw) untuk AFD itu
        $this->assertEqualsWithDelta(0.06, $tmSub['cells']['AFD01']['luas'], 0.0001); // 0.01+0.05
        $this->assertEqualsWithDelta(0.06, $tmSub['cells']['AFD02']['luas'], 0.0001);

        // Grand total = round(Σ semua raw) = 0.12 + 0.33 = 0.45
        $grand = collect($data['rows'])->firstWhere('type', 'grandtotal');
        $this->assertEqualsWithDelta(0.45, $grand['total']['luas'], 0.0001);
        $this->assertEqualsWithDelta(0.39, $grand['cells']['AFD01']['luas'], 0.0001); // 0.01+0.05+0.33
        $this->assertEqualsWithDelta(0.06, $grand['cells']['AFD02']['luas'], 0.0001);
        $this->assertSame(9, $grand['total']['pokok']); // 1+1+2+2+3
    }
}
