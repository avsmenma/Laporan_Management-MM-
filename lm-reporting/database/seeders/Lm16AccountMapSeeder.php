<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Lm16AccountMapSeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('lm16_account_map')->truncate();
        Schema::enableForeignKeyConstraints();

        $sql = file_get_contents(database_path('seeders/sql/seed_lm16_account_map.sql'));
        $insertOffset = strpos($sql, 'INSERT INTO lm16_account_map');

        DB::unprepared(substr($sql, $insertOffset));
    }
}
