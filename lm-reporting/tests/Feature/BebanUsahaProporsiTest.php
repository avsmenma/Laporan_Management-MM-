<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BebanUsahaProporsiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role]);

        return User::factory()->create(['role_id' => $r->id]);
    }

    public function test_operator_bisa_simpan_ubah_dan_hapus_baris(): void
    {
        $op = $this->user('Operator');

        // Buat baru.
        $resp = $this->actingAs($op)->postJson('/laba-rugi/beban-usaha/proporsi', [
            'year' => 2026, 'month' => 6, 'uraian' => 'ABS Karet',
            'total_nilai' => 20140018352, 'nilai_proporsi' => 25009484,
        ]);
        $resp->assertOk();
        $id = $resp->json('id');
        $this->assertIsInt($id);

        // Duplikat kombinasi periode+uraian ditolak.
        $this->actingAs($op)->postJson('/laba-rugi/beban-usaha/proporsi', [
            'year' => 2026, 'month' => 6, 'uraian' => 'ABS Karet',
            'total_nilai' => 1, 'nilai_proporsi' => 1,
        ])->assertStatus(422);

        // Ubah baris yang sama (pakai id).
        $this->actingAs($op)->postJson('/laba-rugi/beban-usaha/proporsi', [
            'id' => $id, 'year' => 2026, 'month' => 6, 'uraian' => 'ABS Karet',
            'total_nilai' => 100, 'nilai_proporsi' => 5,
        ])->assertOk();

        // Daftar terbaca (Viewer boleh melihat).
        $viewer = $this->user('Viewer');
        $rows = $this->actingAs($viewer)->getJson('/laba-rugi/beban-usaha/proporsi')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('ABS Karet', $rows[0]['uraian']);
        $this->assertEqualsWithDelta(100.0, $rows[0]['total_nilai'], 0.001);
        $this->assertEqualsWithDelta(5.0, $rows[0]['nilai_proporsi'], 0.001);

        // Hapus.
        $this->actingAs($op)->deleteJson('/laba-rugi/beban-usaha/proporsi/'.$id)->assertOk();
        $this->assertSame([], $this->actingAs($op)->getJson('/laba-rugi/beban-usaha/proporsi')->json('rows'));
    }

    public function test_viewer_tidak_boleh_menulis(): void
    {
        $viewer = $this->user('Viewer');

        $this->actingAs($viewer)->postJson('/laba-rugi/beban-usaha/proporsi', [
            'year' => 2026, 'month' => 6, 'uraian' => 'ABS Sawit',
            'total_nilai' => 1, 'nilai_proporsi' => 1,
        ])->assertForbidden();

        $this->actingAs($viewer)->deleteJson('/laba-rugi/beban-usaha/proporsi/1')->assertForbidden();
    }

    public function test_uraian_di_luar_daftar_ditolak(): void
    {
        $op = $this->user('Operator');

        $this->actingAs($op)->postJson('/laba-rugi/beban-usaha/proporsi', [
            'year' => 2026, 'month' => 6, 'uraian' => 'ABS Teh',
            'total_nilai' => 1, 'nilai_proporsi' => 1,
        ])->assertStatus(422);
    }
}
