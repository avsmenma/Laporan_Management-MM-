<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (test DB) menyimpan DECIMAL sebagai numeric tanpa enforce presisi,
        // jadi ALTER MODIFY hanya relevan untuk MySQL.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE areal_blok MODIFY luas_tanam DECIMAL(16,3) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE areal_blok MODIFY luas_ha DECIMAL(16,3) NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE areal_blok MODIFY luas_tanam DECIMAL(16,2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE areal_blok MODIFY luas_ha DECIMAL(16,2) NULL');
    }
};
