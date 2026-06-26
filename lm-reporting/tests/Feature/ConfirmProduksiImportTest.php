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

    public function test_konfirmasi_produksi_wajib_bulan_dan_men_dispatch_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $role = Role::query()->firstOrCreate(['name' => 'Operator'], ['description' => 'Operator']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $token = '11111111-1111-1111-1111-111111111111';
        Storage::disk('local')->put("import-staging/{$token}.xlsx", 'dummy');

        // Bulan kini wajib untuk produksi: tanpa month → gagal validasi.
        $this->actingAs($user)->postJson('/import/confirm', [
            'token' => $token,
            'ext' => 'xlsx',
            'type' => 'produksi',
            'year' => 2026,
        ])->assertStatus(422)->assertJsonValidationErrors('month');

        // Dengan month → dispatch & disimpan ke import_jobs.
        $resp = $this->actingAs($user)->postJson('/import/confirm', [
            'token' => $token,
            'ext' => 'xlsx',
            'type' => 'produksi',
            'year' => 2026,
            'month' => 5,
        ]);

        $resp->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);
        $this->assertDatabaseHas('import_jobs', ['type' => 'produksi', 'month' => 5]);
        Queue::assertPushed(ProcessImport::class, function ($job) {
            $importJobId = ImportJob::query()->where('type', 'produksi')->value('id');

            return $job->importJobId === $importJobId;
        });
    }
}
