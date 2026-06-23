<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportJobModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_jobs_table_and_defaults(): void
    {
        foreach (['type', 'year', 'status', 'total', 'processed', 'row_count', 'staged_path', 'error'] as $col) {
            $this->assertTrue(Schema::hasColumn('import_jobs', $col), "kolom {$col} ada");
        }

        $job = ImportJob::query()->create([
            'type' => 'wbs', 'year' => 2026, 'month' => 5,
            'filename' => 'x.xlsx', 'staged_path' => 'import-staging/x.xlsx', 'ext' => 'xlsx',
        ]);

        $this->assertSame('queued', $job->status);
        $this->assertSame(0, $job->processed);
        $this->assertSame(0, $job->total);
    }
}
