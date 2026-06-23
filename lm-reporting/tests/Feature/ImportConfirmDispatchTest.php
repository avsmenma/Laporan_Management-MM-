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

class ImportConfirmDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function operator(): User
    {
        $role = Role::query()->firstOrCreate(['name' => Role::OPERATOR], ['description' => 'op']);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_confirm_creates_import_job_and_dispatches(): void
    {
        Queue::fake();
        Storage::fake('local');

        $token = (string) \Illuminate\Support\Str::uuid();
        Storage::disk('local')->put("import-staging/{$token}.xlsx", 'dummy');

        $res = $this->actingAs($this->operator())->postJson('/import/confirm', [
            'token' => $token, 'ext' => 'xlsx', 'type' => 'gc', 'year' => 2026, 'month' => 5,
        ]);

        $res->assertStatus(202)->assertJsonStructure(['job_id', 'status_url']);
        $this->assertSame(1, ImportJob::query()->count());
        Queue::assertPushed(ProcessImport::class);
    }

    public function test_confirm_returns_422_when_staged_file_missing(): void
    {
        Queue::fake();
        Storage::fake('local');

        $token = (string) \Illuminate\Support\Str::uuid();
        // Tidak meletakkan file → 422

        $res = $this->actingAs($this->operator())->postJson('/import/confirm', [
            'token' => $token, 'ext' => 'xlsx', 'type' => 'gc', 'year' => 2026, 'month' => 5,
        ]);

        $res->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_status_endpoint_returns_progress(): void
    {
        $job = ImportJob::query()->create([
            'type' => 'gc', 'year' => 2026, 'month' => 5, 'filename' => 'g.xlsx',
            'staged_path' => 'import-staging/x.xlsx', 'ext' => 'xlsx',
            'status' => 'processing', 'total' => 10, 'processed' => 4,
        ]);

        $res = $this->actingAs($this->operator())->getJson("/import/status/{$job->id}");

        $res->assertOk()->assertJson(['status' => 'processing', 'processed' => 4, 'total' => 10]);
    }
}
