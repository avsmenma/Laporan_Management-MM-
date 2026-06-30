<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom `raw` (JSON) ke pks_biaya untuk menyimpan baris mentah file SAP cost
 * apa adanya (semua kolom asli), dipakai oleh drill-down LM16 level-2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pks_biaya', function (Blueprint $table) {
            $table->json('raw')->nullable()->after('nilai');
        });
    }

    public function down(): void
    {
        Schema::table('pks_biaya', function (Blueprint $table) {
            $table->dropColumn('raw');
        });
    }
};
