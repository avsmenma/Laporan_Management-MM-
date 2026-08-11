<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersediaanPenyesuaianTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role]);

        return User::factory()->create(['role_id' => $r->id]);
    }

    /** @return array<string, mixed> */
    private function baris(array $ubah = []): array
    {
        return array_merge([
            'year' => 2026, 'month' => 6, 'plant_code' => '5F01', 'product' => '- Minyak Sawit',
            'transfer_masuk' => 1000, 'transfer_keluar' => 200, 'susut' => 30, 'sto_gr' => 4, 'sto_gi' => 5,
        ], $ubah);
    }

    public function test_operator_bisa_simpan_ubah_dan_hapus_baris(): void
    {
        $op = $this->user('Operator');

        $resp = $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian', $this->baris());
        $resp->assertOk();
        $id = $resp->json('id');
        $this->assertIsInt($id);

        // Kombinasi periode+plant+material kembar ditolak.
        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian', $this->baris())->assertStatus(422);

        // Ubah baris yang sama (pakai id).
        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian',
            $this->baris(['id' => $id, 'susut' => 77]))->assertOk();

        // Viewer boleh melihat daftar + pilihan dropdown ikut dikirim.
        $viewer = $this->user('Viewer');
        $data = $this->actingAs($viewer)->getJson('/laba-rugi/persediaan/penyesuaian')->assertOk()->json();
        $this->assertCount(1, $data['rows']);
        $this->assertSame('5F01', $data['rows'][0]['plant_code']);
        $this->assertEqualsWithDelta(77.0, $data['rows'][0]['susut'], 0.001);
        $this->assertContains('Penyesuaian Nilai Akhir', $data['plants']);
        $this->assertContains('LUMP', $data['products']);

        $this->actingAs($op)->deleteJson('/laba-rugi/persediaan/penyesuaian/'.$id)->assertOk();
        $this->assertSame([], $this->actingAs($op)->getJson('/laba-rugi/persediaan/penyesuaian')->json('rows'));
    }

    public function test_viewer_tidak_boleh_menulis(): void
    {
        $viewer = $this->user('Viewer');

        $this->actingAs($viewer)->postJson('/laba-rugi/persediaan/penyesuaian', $this->baris())->assertForbidden();
        $this->actingAs($viewer)->deleteJson('/laba-rugi/persediaan/penyesuaian/1')->assertForbidden();
    }

    public function test_plant_atau_material_di_luar_daftar_ditolak(): void
    {
        $op = $this->user('Operator');

        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian',
            $this->baris(['plant_code' => '9Z99']))->assertStatus(422);
        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian',
            $this->baris(['product' => 'Kopi']))->assertStatus(422);
    }

    public function test_nilai_penyesuaian_muncul_di_endpoint_persediaan(): void
    {
        $op = $this->user('Operator');
        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian', $this->baris())->assertOk();
        // Baris khusus untuk baris "Penyesuaian atas nilai persediaan akhir".
        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian', $this->baris([
            'plant_code' => 'Penyesuaian Nilai Akhir', 'product' => 'Penyesuaian Nilai Akhir',
            'transfer_masuk' => 9, 'transfer_keluar' => 0, 'susut' => 0, 'sto_gr' => 0, 'sto_gi' => 0,
        ]))->assertOk();
        // Periode lain tidak boleh ikut.
        $this->actingAs($op)->postJson('/laba-rugi/persediaan/penyesuaian',
            $this->baris(['month' => 5, 'susut' => 999]))->assertOk();

        $data = $this->actingAs($op)->getJson('/report-data/laba-rugi/persediaan?year=2026&month=6')
            ->assertOk()->json();

        $this->assertEqualsWithDelta(1000.0, $data['penyesuaian']['MINYAK SAWIT']['5F01']['transfer_masuk'], 0.001);
        $this->assertEqualsWithDelta(30.0, $data['penyesuaian']['MINYAK SAWIT']['5F01']['susut'], 0.001);
        $this->assertEqualsWithDelta(9.0, $data['penyesuaian']['_penyes']['_penyes']['transfer_masuk'], 0.001);
        $this->assertArrayNotHasKey('5F04', $data['penyesuaian']['MINYAK SAWIT']);
    }
}
