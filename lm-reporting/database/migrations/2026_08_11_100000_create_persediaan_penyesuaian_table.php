<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian manual halaman Persediaan (tab PENYESUAIAN) — meniru
 * "FORM PENYESUAIAN.xlsx": PLANT × MATERIAL × BULAN × TAHUN dengan lima kolom
 * kuantitas yang belum punya sumber sistem: Transfer Penerimaan, Transfer
 * Pengeluaran, Susut, STO GR, STO GI (satuan Kg).
 *
 * Diisi Operator/Admin lewat inline edit (pola tab PROPORSI Beban Administrasi).
 * `plant_code` & `product` boleh berisi 'Penyesuaian Nilai Akhir' — sesuai daftar
 * dropdown form — untuk baris "Penyesuaian atas nilai persediaan akhir".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persediaan_penyesuaian', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';

            $table->id();
            $table->smallInteger('year');
            $table->tinyInteger('month');                        // 1..12
            $table->string('plant_code', 30);                    // 5F01 ... / 'Penyesuaian Nilai Akhir'
            $table->string('product', 60);                       // '- Minyak Sawit' ... / 'Penyesuaian Nilai Akhir'
            $table->decimal('transfer_masuk', 20, 2)->default(0); // TRANSFER PENERIMAAN
            $table->decimal('transfer_keluar', 20, 2)->default(0); // TRANSFER PENGELUARAN
            $table->decimal('susut', 20, 2)->default(0);
            $table->decimal('sto_gr', 20, 2)->default(0);
            $table->decimal('sto_gi', 20, 2)->default(0);
            $table->timestamps();

            $table->index(['year', 'month'], 'idx_psdp_year_month');
            $table->unique(['year', 'month', 'plant_code', 'product'], 'uq_psdp_periode_unit_produk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persediaan_penyesuaian');
    }
};
