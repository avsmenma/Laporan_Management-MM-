<?php

namespace Database\Seeders;

use App\Models\RefKlasifikasi;
use Illuminate\Database\Seeder;

class RefKlasifikasiSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => '1', 'name' => 'Gaji'],
            ['code' => '2', 'name' => 'SPK'],
            ['code' => '3', 'name' => 'Bahan'],
            ['code' => '4', 'name' => 'EAP'],
            ['code' => '5', 'name' => 'Depresiasi'],
            ['code' => '6', 'name' => 'Lain-Lain'],
        ])->each(fn (array $row) => RefKlasifikasi::updateOrCreate(
            ['code' => $row['code']],
            ['name' => $row['name']],
        ));
    }
}
