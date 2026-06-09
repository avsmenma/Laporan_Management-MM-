<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sistem Pelaporan LM');
    }

    public function test_seeded_roles_can_login(): void
    {
        $this->seed();

        foreach ([Role::VIEWER, Role::OPERATOR, Role::ADMIN] as $roleName) {
            $user = User::query()
                ->whereHas('role', fn ($query) => $query->where('name', $roleName))
                ->firstOrFail();

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertRedirect(route('kebun'));

            $this->actingAs($user)->get('/reports')->assertRedirect(route('kebun'));
            $this->post('/logout')->assertRedirect(route('login'));
        }
    }
}
