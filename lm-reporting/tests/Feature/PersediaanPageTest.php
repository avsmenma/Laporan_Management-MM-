<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersediaanPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_halaman_persediaan_render(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $resp = $this->actingAs($user)->get('/laba-rugi/persediaan');
        $resp->assertOk();
        $resp->assertSee('persediaanApp', false);
        // Judul kedua tab (karet TANPA kata "KELAPA" — permintaan user) + baris kunci.
        $resp->assertSee('PERSEDIAAN AKHIR HASIL PRODUKSI KELAPA SAWIT', false);
        $resp->assertSee('PERSEDIAAN AKHIR HASIL PRODUKSI KARET', false);
        $resp->assertDontSee('KELAPA KARET', false);
        $resp->assertSee('- Tandan Buah Segar', false);
        $resp->assertSee('PKR Tambarangan', false);
        $resp->assertSee('Penyesuaian atas nilai persediaan akhir', false);
        $resp->assertSee('Jumlah Persediaan', false);
        // Nilai awal tahun manual (dari TEMPLATE PERSEDIAAN-1.xlsx) ikut terrender.
        $resp->assertSee('12353944807', false);
        $resp->assertSee('-10565230360', false);
        $resp->assertSee('-2005715276', false);
    }

    public function test_halaman_persediaan_butuh_login(): void
    {
        $this->get('/laba-rugi/persediaan')->assertRedirect('/login');
    }
}
