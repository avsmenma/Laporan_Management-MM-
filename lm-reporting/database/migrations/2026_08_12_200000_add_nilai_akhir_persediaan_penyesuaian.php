<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom PERSEDIAAN AKHIR (Rp) pada tab PENYESUAIAN — isian manual untuk baris
 * "Penyesuaian atas nilai persediaan akhir" pada kolom NILAI PERSEDIAAN AKHIR
 * tabel KELAPA SAWIT/KARET (permintaan user). Rupiah, bukan Kg seperti lima
 * kolom lainnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persediaan_penyesuaian', function (Blueprint $table) {
            $table->decimal('nilai_akhir', 20, 2)->default(0)->after('sto_gi');
        });
    }

    public function down(): void
    {
        Schema::table('persediaan_penyesuaian', function (Blueprint $table) {
            $table->dropColumn('nilai_akhir');
        });
    }
};
