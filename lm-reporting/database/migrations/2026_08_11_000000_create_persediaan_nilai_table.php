<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai persediaan akhir per unit & produk (ekspor SAP ZSTOCK, sheet "Data").
 * Mengisi kolom "NILAI PERSEDIAAN AKHIR <bulan>" halaman /laba-rugi/persediaan.
 *
 * Berkas TIDAK punya kolom periode → periode diambil dari pilihan Bulan & Tahun
 * saat impor; impor idempoten hapus-ganti per (year, period).
 * `unit_name` ikut jadi kunci karena satu plant bisa punya 2 unit kerja
 * (5R00 = IPP Tayan & Tanah Merah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persediaan_nilai', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('period');                 // 1..12 (dari pilihan bulan)
            $table->string('plant_code', 10);              // 5R00 / 5F01 / 5E06 ...
            $table->string('unit_name', 100);              // IPP Tayan / PKS Gunung Meliau ...
            $table->string('product', 100);                // - Minyak Sawit / - Inti Sawit / LUMP ...
            $table->decimal('nilai_rp', 20, 2)->default(0); // Value on Period End
            $table->timestamps();

            $table->index(['year', 'period'], 'idx_psdn_year_period');
            $table->unique(['year', 'period', 'plant_code', 'unit_name', 'product'], 'uniq_psdn_row');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persediaan_nilai');
    }
};
