<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BudgetSourceColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_and_batch_have_new_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('budget_rko', 'source'));
        $this->assertTrue(Schema::hasColumn('budget_rkap', 'source'));
        $this->assertTrue(Schema::hasColumn('batch', 'needs_regenerate'));
    }
}
