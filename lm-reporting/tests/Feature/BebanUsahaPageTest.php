<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BebanUsahaPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_halaman_beban_usaha_render(): void
    {
        $role = Role::query()->firstOrCreate(['name' => 'Viewer']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $pages = [
            '/laba-rugi/beban-penjualan' => ['BEBAN PENJUALAN', 'Jumlah Seluruh'],
            '/laba-rugi/beban-administrasi' => ['BEBAN ADMINISTRASI', 'Jumlah beban administrasi Include Penyusutan'],
            '/laba-rugi/beban-operasional-lainnya' => ['RINCIAN BEBAN LAIN-LAIN', 'Jumlah Biaya KSO'],
            '/laba-rugi/pendapatan-lainnya' => ['RINCIAN PENDAPATAN LAIN-LAIN', 'Jumlah Pendapatan KSO'],
        ];

        foreach ($pages as $url => [$judul, $baris]) {
            $resp = $this->actingAs($user)->get($url);
            $resp->assertOk();
            $resp->assertSee('bebanUsahaApp', false);
            $resp->assertSee($judul, false);
            $resp->assertSee($baris, false);
        }
    }

    public function test_halaman_beban_usaha_butuh_login(): void
    {
        $this->get('/laba-rugi/beban-penjualan')->assertRedirect('/login');
    }
}
