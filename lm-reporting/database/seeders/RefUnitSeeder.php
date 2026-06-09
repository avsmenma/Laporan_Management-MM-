<?php

namespace Database\Seeders;

use App\Models\RefUnit;
use Illuminate\Database\Seeder;

class RefUnitSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['5E01', 'Kebun Gunung Meliau', 'KEBUN', null, '5E01000001', null],
            ['5E02', 'Kebun Gunung Mas', 'KEBUN', null, '5E02000001', null],
            ['5E03', 'Kebun Sungai Dekan', 'KEBUN', null, '5E03000001', null],
            ['5E04', 'Kebun Rimba Belian', 'KEBUN', null, '5E04000001', null],
            ['5E06', 'Kebun Sintang', 'KEBUN', null, '5E06000001', null],
            ['5E07', 'Kebun Ngabang', 'KEBUN', null, '5E07000001', null],
            ['5E08', 'Kebun Parindu', 'KEBUN', null, '5E08000001', null],
            ['5E09', 'Kebun Kembayan', 'KEBUN', null, '5E09000001', null],
            ['5E11', 'Kebun Danau Salak', 'KEBUN', null, '5E11000001', null],
            ['5E12', 'Kebun Kumai', 'KEBUN', null, '5E12000001', null],
            ['5E13', 'Kebun Batulicin', 'KEBUN', null, '5E13000001', null],
            ['5E14', 'Kebun Pamukan', 'KEBUN', null, '5E14000001', null],
            ['5E15', 'Kebun Pelaihari', 'KEBUN', null, '5E15000001', null],
            ['5E16', 'Kebun Tabara', 'KEBUN', null, '5E16000001', null],
            ['5E17', 'Kebun Tajati', 'KEBUN', null, '5E17000001', null],
            ['5E18', 'Kebun Pandawa', 'KEBUN', null, '5E18000001', null],
            ['5E19', 'Kebun Longkali', 'KEBUN', null, '5E19000001', null],
            ['5F01', 'PKS Gunung Meliau', 'PABRIK', 'KS', '5F01000001', 'Olah'],
            ['5F04', 'PKS Rimba Belian', 'PABRIK', 'KS', '5F04000001', 'Olah'],
            ['5F07', 'PKS Ngabang', 'PABRIK', 'KS', '5F07000001', 'Olah'],
            ['5F08', 'PKS Parindu', 'PABRIK', 'KS', '5F08000001', 'Olah'],
            ['5F09', 'PKS Kembayan', 'PABRIK', 'KS', '5F09000001', 'Olah'],
            ['5F14', 'PKS Pamukan', 'PABRIK', 'KS', '5F14000001', 'Non Olah'],
            ['5F15', 'PKS Pelaihari', 'PABRIK', 'KS', '5F15000001', 'Olah'],
            ['5F20', 'PKR Tambarangan', 'PABRIK', 'KR', '5F20000001', 'Non Olah'],
            ['5F21', 'PKS Samuntai', 'PABRIK', 'KS', '5F21000001', 'Non Olah'],
            ['5F22', 'PKS Long Pinang', 'PABRIK', 'KS', '5F22000001', 'Olah'],
        ])->each(function (array $row): void {
            RefUnit::updateOrCreate(
                ['code' => $row[0], 'komoditi' => $row[3]],
                [
                    'name' => $row[1],
                    'type' => $row[2],
                    'profit_center' => $row[4],
                    'olah_status' => $row[5],
                    'is_active' => true,
                ],
            );
        });
    }
}
