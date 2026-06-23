<?php

namespace Tests\Feature;

use App\Models\ArealBlok;
use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArealBlokModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_model(): void
    {
        foreach (['batch_id', 'status_blok_petak', 'plant_code', 'divisi', 'tahun_tanam', 'luas_tanam', 'total_pokok_produktif', 'komoditi'] as $c) {
            $this->assertTrue(Schema::hasColumn('areal_blok', $c), "kolom {$c}");
        }
        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $row = ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => 'TM', 'plant_code' => '5E01',
            'divisi' => 'AFD07', 'tahun_tanam' => 2012, 'luas_tanam' => 7.2,
            'total_pokok_produktif' => 647, 'komoditi' => 'KS',
        ]);
        $this->assertSame('TM', $row->status_blok_petak);
        $this->assertSame(647, $row->total_pokok_produktif);
    }

    public function test_luas_preserves_three_decimals(): void
    {
        $this->assertTrue(Schema::hasColumn('areal_blok', 'luas_tanam'));
        $this->assertTrue(Schema::hasColumn('areal_blok', 'luas_ha'));

        $batch = Batch::query()->create(['code' => 'Batch #2026-05', 'year' => 2026, 'month' => 5, 'status' => 'draft']);
        $row = ArealBlok::query()->create([
            'batch_id' => $batch->id, 'status_blok_petak' => 'TM', 'plant_code' => '5E01',
            'divisi' => 'AFD07', 'tahun_tanam' => 2012, 'luas_tanam' => 15.954,
            'luas_ha' => 7.416, 'total_pokok_produktif' => 647, 'komoditi' => 'KS',
        ]);

        // Reload dari DB untuk membuktikan presisi 3 desimal bertahan (bukan 15.95).
        $fresh = ArealBlok::query()->find($row->id);
        $this->assertSame(15.954, (float) $fresh->luas_tanam);
        $this->assertSame(7.416, (float) $fresh->luas_ha);
    }
}
