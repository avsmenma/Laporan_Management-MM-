<?php

namespace Database\Seeders;

use App\Models\RefUnit;
use App\Models\RefUnitKomoditi;
use Illuminate\Database\Seeder;

class RefUnitKomoditiSeeder extends Seeder
{
    public function run(): void
    {
        RefUnit::query()
            ->where('type', 'KEBUN')
            ->get()
            ->each(fn (RefUnit $unit) => RefUnitKomoditi::updateOrCreate(
                ['unit_id' => $unit->id, 'komoditi' => 'KS'],
            ));

        RefUnit::query()
            ->whereIn('code', ['5E06', '5E12', '5E13', '5E19'])
            ->get()
            ->each(fn (RefUnit $unit) => RefUnitKomoditi::updateOrCreate(
                ['unit_id' => $unit->id, 'komoditi' => 'KR'],
            ));
    }
}
