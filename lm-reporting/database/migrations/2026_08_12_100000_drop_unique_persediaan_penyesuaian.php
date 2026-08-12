<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci unik (tahun, bulan, plant, produk) DILEPAS: satu plant boleh punya
 * beberapa baris pada periode yang sama — mis. satu baris untuk Transfer
 * Penerimaan dan baris lain untuk Susut (permintaan user). Nilai tiap kolom
 * dijumlahkan saat dibaca halaman Persediaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persediaan_penyesuaian', function (Blueprint $table) {
            $table->dropUnique('uq_psdp_periode_unit_produk');
        });
    }

    public function down(): void
    {
        // Hanya bisa dipasang lagi bila tidak ada baris kembar.
        Schema::table('persediaan_penyesuaian', function (Blueprint $table) {
            $table->unique(['year', 'month', 'plant_code', 'product'], 'uq_psdp_periode_unit_produk');
        });
    }
};
