<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProduksiPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_halaman_produksi_render(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $resp = $this->actingAs($user)->get('/produksi/pks');
        $resp->assertOk();
        $resp->assertSee('produksiApp', false);
        $resp->assertSee('/report-data/produksi', false);
    }
}
