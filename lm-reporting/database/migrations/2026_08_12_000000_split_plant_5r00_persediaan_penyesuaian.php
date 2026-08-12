<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kode plant 5R00 dipakai dua unit kerja (IPP Tayan & Tanah Merah) sehingga pada
 * form PENYESUAIAN dipecah jadi 5R00-1 (IPP Tayan) dan 5R00-2 (Tanah Merah).
 *
 * Baris lama ber-plant '5R00' dipindahkan ke '5R00-1' — sama dengan baris yang
 * selama ini menampilkan angkanya (baris pertama plant itu), jadi tampilan tabel
 * tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('persediaan_penyesuaian')->where('plant_code', '5R00')
            ->update(['plant_code' => '5R00-1']);
    }

    public function down(): void
    {
        // Hanya IPP Tayan yang dikembalikan; baris Tanah Merah dibiarkan agar
        // tidak bentrok dengan kunci unik (tahun, bulan, plant, produk).
        DB::table('persediaan_penyesuaian')->where('plant_code', '5R00-1')
            ->update(['plant_code' => '5R00']);
    }
};
