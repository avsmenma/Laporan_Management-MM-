<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArealProduksiMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_areal_page_renders_for_viewer(): void
    {
        $res = $this->actingAs($this->makeUser(Role::VIEWER))->get('/areal');

        $res->assertOk();
        $res->assertSee('Areal');
    }

    public function test_produksi_page_renders_for_viewer(): void
    {
        $res = $this->actingAs($this->makeUser(Role::VIEWER))->get('/produksi/pks');

        $res->assertOk();
        $res->assertSee('Produksi');
        $res->assertSee('produksiApp', false);
    }

    public function test_produksi_kebun_submenu_coming_soon(): void
    {
        $res = $this->actingAs($this->makeUser(Role::VIEWER))->get('/produksi/kebun');

        $res->assertOk();
        $res->assertSee('Produksi Kebun');
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/areal')->assertRedirect('/login');
        $this->get('/produksi/pks')->assertRedirect('/login');
    }
}
