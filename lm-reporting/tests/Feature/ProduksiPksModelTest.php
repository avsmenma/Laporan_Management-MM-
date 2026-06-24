<?php

namespace Tests\Feature;

use App\Models\ProduksiPks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProduksiPksModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabel_dan_model_produksi_pks(): void
    {
        $this->assertTrue(Schema::hasTable('produksi_pks'));

        $row = ProduksiPks::query()->create([
            'posting_date' => '2026-05-31',
            'plant_code' => '5F01',
            'plant_desc' => 'PABRIK GUNUNG MELIAU',
            'group_pemilik' => 'Kebun Sendiri',
            'kebun_code' => '5E01',
            'nama_kebun' => 'KEBUN GUNUNG MELIAU',
            'tbs_diterima_sdhari' => 3795250,
            'tbs_diterima_sdbulan' => 19506780,
            'sisa_akhir' => 0,
            'tidak_mengolah' => false,
        ]);

        $fresh = ProduksiPks::query()->find($row->id);
        $this->assertSame('2026-05-31', $fresh->posting_date->format('Y-m-d'));
        $this->assertSame('5F01', $fresh->plant_code);
        $this->assertEquals(3795250.0, (float) $fresh->tbs_diterima_sdhari);
        $this->assertFalse($fresh->tidak_mengolah);
    }
}
