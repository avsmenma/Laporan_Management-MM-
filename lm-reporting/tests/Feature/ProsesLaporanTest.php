<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesLaporanTest extends TestCase
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

    public function test_button_generates_and_marks_processed(): void
    {
        $op = $this->makeUser(Role::OPERATOR);

        RefUnit::query()->create([
            'code'     => '5E11',
            'name'     => 'A',
            'type'     => 'KEBUN',
            'komoditi' => null,
        ]);

        $batch = Batch::query()->create([
            'code'             => 'Batch #2026-05',
            'year'             => 2026,
            'month'            => 5,
            'status'           => 'draft',
            'needs_regenerate' => true,
        ]);

        $res = $this->actingAs($op)->post('/proses-laporan', ['batch_id' => $batch->id]);
        $res->assertRedirect();

        $batch->refresh();
        $this->assertFalse((bool) $batch->needs_regenerate);
        $this->assertNotNull($batch->processed_at);
    }

    public function test_viewer_forbidden(): void
    {
        $viewer = $this->makeUser(Role::VIEWER);

        $batch = Batch::query()->create([
            'code'  => 'Batch #2026-05',
            'year'  => 2026,
            'month' => 5,
            'status' => 'draft',
        ]);

        $this->actingAs($viewer)
            ->post('/proses-laporan', ['batch_id' => $batch->id])
            ->assertForbidden();
    }
}
