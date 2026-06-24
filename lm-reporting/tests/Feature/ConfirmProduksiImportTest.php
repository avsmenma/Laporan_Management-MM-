<?php

namespace Tests\Feature;

use App\Jobs\ProcessImport;
use App\Models\ImportJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfirmProduksiImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_konfirmasi_produksi_tanpa_bulan_men_dispatch_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $role = Role::query()->firstOrCreate(['name' => 'Operator'], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $token = '11111111-1111-1111-1111-111111111111';
        Storage::disk('local')->put("import-staging/{$token}.xlsx", 'dummy');

        $resp = $this->actingAs($user)->postJson('/import/confirm', [
            'token' => $token,
            'ext' => 'xlsx',
            'type' => 'produksi',
            'year' => 2026,
            // tanpa month — produksi tidak memerlukannya
        ]);

        $resp->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);
        $this->assertDatabaseHas('import_jobs', ['type' => 'produksi', 'month' => null]);
        Queue::assertPushed(ProcessImport::class);
    }
}
