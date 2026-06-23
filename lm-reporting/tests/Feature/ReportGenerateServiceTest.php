<?php

namespace Tests\Feature;

use App\Domain\Report\ReportGenerateService;
use App\Models\Batch;
use App\Models\RefUnit;
use App\Models\RefUnitKomoditi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportGenerateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_batch_materializes_and_marks_processed(): void
    {
        // Seed template baris (dibutuhkan oleh Lm13Service, Lm14Service, Lm16Service)
        $this->seed(\Database\Seeders\LmTemplateRowSeeder::class);

        // Buat batch
        $batch = Batch::query()->create([
            'code'             => 'Batch #2026-05',
            'year'             => 2026,
            'month'            => 5,
            'status'           => 'draft',
            'needs_regenerate' => true,
        ]);

        // Buat satu unit KEBUN dengan komoditi KS via ref_unit_komoditi
        $kebun = RefUnit::query()->create([
            'code'    => '5E11',
            'name'    => 'Kebun Danau Salak',
            'type'    => 'KEBUN',
            'komoditi' => null,
        ]);
        RefUnitKomoditi::query()->create([
            'unit_id'  => $kebun->id,
            'komoditi' => 'KS',
        ]);

        // Jalankan service
        $summary = app(ReportGenerateService::class)->generateBatch($batch);

        // Assert bentuk summary
        $this->assertArrayHasKey('lm14', $summary);
        $this->assertArrayHasKey('lm13', $summary);
        $this->assertArrayHasKey('lm16', $summary);
        $this->assertArrayHasKey('units', $summary);
        $this->assertArrayHasKey('detail', $summary);
        $this->assertIsInt($summary['lm14']);
        $this->assertIsInt($summary['lm13']);
        $this->assertIsInt($summary['lm16']);
        $this->assertIsInt($summary['units']);
        $this->assertIsArray($summary['detail']);

        // Units yang diproses harus 1 (hanya kebun, tidak ada pabrik)
        $this->assertSame(1, $summary['units']);

        // batch harus ditandai processed
        $batch->refresh();
        $this->assertNotNull($batch->processed_at);
        $this->assertFalse((bool) $batch->needs_regenerate);
    }

    public function test_generate_batch_handles_empty_units(): void
    {
        // Tidak ada unit sama sekali — service harus tetap mengembalikan summary dan menandai batch
        $this->seed(\Database\Seeders\LmTemplateRowSeeder::class);

        $batch = Batch::query()->create([
            'code'             => 'Batch #2026-06',
            'year'             => 2026,
            'month'            => 6,
            'status'           => 'draft',
            'needs_regenerate' => true,
        ]);

        $summary = app(ReportGenerateService::class)->generateBatch($batch);

        $this->assertSame(0, $summary['lm14']);
        $this->assertSame(0, $summary['lm13']);
        $this->assertSame(0, $summary['lm16']);
        $this->assertSame(0, $summary['units']);
        $this->assertSame([], $summary['detail']);

        $batch->refresh();
        $this->assertNotNull($batch->processed_at);
        $this->assertFalse((bool) $batch->needs_regenerate);
    }
}
