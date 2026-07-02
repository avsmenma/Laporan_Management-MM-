<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvestasiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_investasi_rekap_endpoint_returns_columns_and_subtotal_rows(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'final',
            'processed_at' => '2026-05-20 08:00:00',
        ]);
        $operator = User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OPERATOR))->firstOrFail();

        // Areal blok (pokok/ha) untuk blok pertama TU/2026 kebun 5E01.
        DB::table('areal_blok')->insert([
            'batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01',
            'status_blok_petak' => 'TU', 'tahun_tanam' => 2026,
            'total_pokok' => 1000, 'luas_ha' => 10, 'luas_tanam' => 10,
        ]);

        // Realisasi investasi WBS: bulan ini (period 5) + bulan lalu (period 4) → kumulatif.
        DB::table('investasi_wbs')->insert([
            [
                'batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01',
                'fase' => 'a. Land Clearing/TU', 'tahun_tanam' => 2026, 'period' => 5, 'nilai' => 5000,
            ],
            [
                'batch_id' => $batch->id, 'komoditi' => 'KS', 'plant_code' => '5E01',
                'fase' => 'a. Land Clearing/TU', 'tahun_tanam' => 2026, 'period' => 4, 'nilai' => 3000,
            ],
        ]);

        $resp = $this->actingAs($operator)
            ->getJson("/report-data/lm-investasi?view=rekap&batch={$batch->id}&unit=ALL")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.report_type', 'INVESTASI_REKAP')
            ->assertJsonPath('meta.view', 'rekap')
            ->assertJsonPath('meta.unit.code', 'ALL')
            ->assertJsonPath('meta.unit.name', 'Semua Unit')
            ->assertJsonPath('meta.batch.period', 5);

        $this->assertCount(27, $resp->json('columns'));

        $rows = collect($resp->json('rows'));
        $this->assertTrue($rows->isNotEmpty());

        $firstSubtotal = $rows->firstWhere('row_type', 'subtotal');
        $this->assertNotNull($firstSubtotal);
        $this->assertSame('subtotal', $firstSubtotal['row_type']);
        // Blok TU/2026: SBI kumulatif (period<=5) = 5000 + 3000 = 8000.
        $this->assertEquals(8000.0, $firstSubtotal['rsbi_real']);
    }

    public function test_investasi_rekap2_endpoint_returns_36_columns(): void
    {
        $this->seed();
        $batch = Batch::query()->create([
            'code' => 'Batch #2026-05',
            'year' => 2026,
            'month' => 5,
            'status' => 'final',
            'processed_at' => '2026-05-20 08:00:00',
        ]);
        $operator = User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OPERATOR))->firstOrFail();

        $resp = $this->actingAs($operator)
            ->getJson("/report-data/lm-investasi?view=rekap2&batch={$batch->id}&unit=ALL")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.report_type', 'INVESTASI_REKAP2')
            ->assertJsonPath('meta.view', 'rekap2');

        $this->assertCount(36, $resp->json('columns'));
        $this->assertTrue(collect($resp->json('rows'))->isNotEmpty());
    }

    public function test_investasi_endpoint_404_on_missing_batch(): void
    {
        $this->seed();
        $operator = User::query()->whereHas('role', fn ($q) => $q->where('name', Role::OPERATOR))->firstOrFail();

        $this->actingAs($operator)
            ->getJson('/report-data/lm-investasi?view=rekap&batch=999999&unit=ALL')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Batch tidak ditemukan.');
    }
}
