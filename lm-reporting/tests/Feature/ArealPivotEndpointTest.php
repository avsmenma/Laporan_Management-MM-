<?php

namespace Tests\Feature;

use App\Models\ArealBlok;
use App\Models\Batch;
use App\Models\RefUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArealPivotEndpointTest extends TestCase
{
    use RefreshDatabase;

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

        $res = $this->getJson('/report-data/areal?year=2026&month=5&komoditi=KS&unit=5E01');
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
}
